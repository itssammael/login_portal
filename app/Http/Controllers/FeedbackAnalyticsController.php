<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveFeedbackAnalyticsReportRequest;
use App\Models\Feedback;
use App\Models\FeedbackAnalyticsReport;
use App\Services\FeedbackAnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class FeedbackAnalyticsController extends Controller
{
    public function index(Feedback $feedback, FeedbackAnalyticsService $analytics): JsonResponse
    {
        Gate::authorize('manageAnalytics', $feedback);

        return response()->json([
            'reports' => $feedback->analyticsReports()->latest('id')->get(['id', 'configuration', 'status', 'published_at']),
            'publication' => $analytics->publication($feedback),
            'selected_report_id' => $feedback->published_analytics_report_id,
            'questions' => collect($analytics->questions($feedback))->values(),
        ]);
    }

    public function store(SaveFeedbackAnalyticsReportRequest $request, Feedback $feedback, FeedbackAnalyticsService $analytics): JsonResponse
    {
        $configuration = $request->validated('configuration');
        $analytics->validate($feedback, $configuration);
        $report = new FeedbackAnalyticsReport(['configuration' => $configuration]);
        $report->created_by = $request->user()->id;
        $feedback->analyticsReports()->save($report);

        return response()->json(['report' => $report->fresh()], 201);
    }

    public function update(SaveFeedbackAnalyticsReportRequest $request, Feedback $feedback, FeedbackAnalyticsReport $report, FeedbackAnalyticsService $analytics): JsonResponse
    {
        abort_unless((int) $report->feedback_id === (int) $feedback->id, 404);
        $configuration = $request->validated('configuration');
        $analytics->validate($feedback, $configuration);
        $report->update(['configuration' => $configuration]);

        return response()->json(['report' => $report]);
    }

    public function preview(SaveFeedbackAnalyticsReportRequest $request, Feedback $feedback, FeedbackAnalyticsService $analytics): JsonResponse
    {
        $configuration = $request->validated('configuration');
        $analytics->validate($feedback, $configuration);

        return response()->json($analytics->aggregate($feedback, $configuration, true));
    }

    public function publish(Request $request, Feedback $feedback, FeedbackAnalyticsReport $report, FeedbackAnalyticsService $analytics): JsonResponse
    {
        Gate::authorize('manageAnalytics', $feedback);
        abort_unless((int) $report->feedback_id === (int) $feedback->id, 404);
        $input = $request->validate([
            'confirm_public' => ['required', 'accepted'],
            'replace_report_id' => ['present', 'nullable', 'integer'],
            'approve_sensitive' => ['sometimes', 'boolean'],
        ]);
        abort_if(($input['approve_sensitive'] ?? false) && ! $request->user()->isAdmin(), 403);

        DB::transaction(function () use ($feedback, $report, $analytics, $request, $input): void {
            $locked = Feedback::query()->lockForUpdate()->findOrFail($feedback->id);
            $current = $locked->published_analytics_report_id;
            abort_if($current !== null && (int) $current !== (int) $report->id && (int) ($input['replace_report_id'] ?? 0) !== (int) $current, 409, 'Another report is selected. Reload and explicitly confirm its replacement.');
            $report = $locked->analyticsReports()->lockForUpdate()->findOrFail($report->id);
            $analytics->validate($locked, $report->configuration, true, $input['approve_sensitive'] ?? false);
            if ($current !== null && (int) $current !== (int) $report->id) {
                $locked->analyticsReports()->whereKey($current)->update(['status' => 'unpublished', 'visibility' => 'private', 'unpublished_at' => now()]);
            }
            $report->status = 'published';
            $report->visibility = 'public';
            $report->published_configuration = ['report' => $report->configuration, 'mappings' => $analytics->mappings($locked, $report->configuration), 'version' => (string) Str::uuid()];
            $report->published_at = now();
            $report->published_by = $request->user()->id;
            $report->unpublished_at = null;
            $report->save();
            $locked->published_analytics_report_id = $report->id;
            $locked->save();
        }, 3);

        return response()->json($analytics->publication($feedback->fresh()));
    }

    public function unpublish(Feedback $feedback, FeedbackAnalyticsReport $report, FeedbackAnalyticsService $analytics): JsonResponse
    {
        Gate::authorize('manageAnalytics', $feedback);
        abort_unless((int) $report->feedback_id === (int) $feedback->id, 404);
        DB::transaction(function () use ($feedback, $report): void {
            $locked = Feedback::query()->lockForUpdate()->findOrFail($feedback->id);
            $report = $locked->analyticsReports()->lockForUpdate()->findOrFail($report->id);
            $report->status = 'unpublished';
            $report->visibility = 'private';
            $report->unpublished_at = now();
            $report->save();
            if ((int) $locked->published_analytics_report_id === (int) $report->id) {
                $locked->published_analytics_report_id = null;
                $locked->save();
            }
        }, 3);

        return response()->json($analytics->publication($feedback->fresh()));
    }
}
