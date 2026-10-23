<?php

namespace App\Http\Controllers;

use App\Models\FbEvent;
use App\Services\FeedbackAnalyticsService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class FeedbackAnalyticsPageController extends Controller
{
    public function show(Request $request, string $api_key, FeedbackAnalyticsService $analytics): Response
    {
        $event = FbEvent::query()->where('api_key', $api_key)->with('feedback.publishedAnalyticsReport')->firstOrFail();
        $feedback = $event->feedback;
        abort_unless($feedback && $analytics->available($feedback, $feedback->publishedAnalyticsReport), 404);
        $report = $feedback->publishedAnalyticsReport;

        Inertia::flushShared();

        return Inertia::render('Feedback/Analytics', [
            'eventTitle' => $event->name,
            'report' => $report->published_configuration['report'],
            'data' => $analytics->publicData($feedback, $report),
            'publishedAt' => $report->published_at->toIso8601String(),
        ])->toResponse($request)->header('Cache-Control', 'no-store, private');
    }
}
