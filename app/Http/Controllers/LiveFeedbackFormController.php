<?php

namespace App\Http\Controllers;

use App\Http\Requests\Api\SubmitFeedbackRequest;
use App\Http\Resources\FbSubmissionResource;
use App\Models\Agency;
use App\Models\Designation;
use App\Models\FbEvent;
use App\Models\Feedback;
use App\Services\FeedbackSchemaValidator;
use App\Services\FeedbackSubmissionPersistenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class LiveFeedbackFormController extends Controller
{
    /**
     * Display the live deployed feedback form for a given API key.
     */
    public function show(
        Request $request,
        string $api_key,
        FeedbackSchemaValidator $schemaValidator
    ): Response {
        if (! $this->isValidApiKeyFormat($api_key)) {
            abort(404);
        }

        $event = FbEvent::query()
            ->whereNull('deleted_at')
            ->where('api_key', $api_key)
            ->with(['feedback', 'functions:id,function,details'])
            ->first();

        if (! $event) {
            abort(404);
        }

        $feedback = $event->feedback;
        if (! $feedback || empty($feedback->schema) || ! is_array($feedback->schema)) {
            abort(404);
        }

        // When unpublished or saved as draft, public respondents see a proper "Not Available" message
        if ($feedback->isDraft() && ! $request->user()?->isAdmin()) {
            return Inertia::render('Feedback/Live', [
                'event' => [
                    'name' => $event->name,
                    'details' => $event->details,
                ],
                'form' => null,
                'isAvailable' => false,
                'unavailableReason' => 'draft',
                'unavailableMessage' => 'This feedback form is currently unpublished or still saved as draft and is not accepting responses at this time.',
                'functions' => [],
                'lookups' => [
                    'agencies' => [],
                    'designations' => [],
                ],
                'apiKey' => $api_key,
            ]);
        }

        try {
            $resolvedSchema = $schemaValidator->resolveSchemaForEvent($feedback->schema, $event);
        } catch (Throwable) {
            abort(404);
        }

        if (empty($resolvedSchema['fields']) || ! is_array($resolvedSchema['fields'])) {
            abort(404);
        }

        $functions = $event->functions()
            ->orderBy('function')
            ->get(['fb_functions.id', 'fb_functions.function', 'fb_functions.details']);

        return Inertia::render('Feedback/Live', [
            'event' => [
                'name' => $event->name,
                'details' => $event->details,
            ],
            'form' => [
                'id' => $feedback->id,
                'status' => $feedback->status ?? Feedback::STATUS_PUBLISHED,
                'is_draft' => $feedback->isDraft(),
                'schema' => $resolvedSchema,
            ],
            'isAvailable' => true,
            'unavailableReason' => null,
            'unavailableMessage' => null,
            'functions' => $functions,
            'lookups' => [
                'agencies' => Agency::getCachedList(),
                'designations' => Designation::getCachedList(),
            ],
            'apiKey' => $api_key,
        ]);
    }

    /**
     * Submit feedback answers for a live deployed feedback form.
     */
    public function submit(
        SubmitFeedbackRequest $request,
        string $api_key,
        FeedbackSubmissionPersistenceService $persistenceService
    ): JsonResponse {
        if (! $this->isValidApiKeyFormat($api_key)) {
            abort(404);
        }

        $event = FbEvent::query()
            ->whereNull('deleted_at')
            ->where('api_key', $api_key)
            ->with('feedback')
            ->first();

        if (! $event || ! $event->feedback) {
            abort(404);
        }

        $feedback = $event->feedback;
        if (empty($feedback->schema) || ! is_array($feedback->schema) || empty($feedback->schema['fields']) || ! is_array($feedback->schema['fields'])) {
            abort(404);
        }

        if ($feedback->isDraft()) {
            return response()->json([
                'status' => 'error',
                'message' => 'This feedback form is currently in draft mode and is not accepting responses.',
                'errors' => [
                    'general' => ['This feedback form is currently in draft mode and is not accepting responses.'],
                ],
            ], 422);
        }

        // Anti-tampering verification: ensure client is not attempting to target another event or form
        $submittedEventId = $request->input('event_id');
        if ($submittedEventId !== null && (! is_numeric($submittedEventId) || (string) $submittedEventId !== (string) $event->id)) {
            return response()->json([
                'message' => 'The submitted event identifier does not match the deployed form event.',
                'errors' => [
                    'event_id' => ['The submitted event identifier does not match the deployed form event.'],
                ],
            ], 422);
        }

        $submittedFeedbackId = $request->input('feedback_id', $request->input('form_id'));
        if ($submittedFeedbackId !== null && (! is_numeric($submittedFeedbackId) || (string) $submittedFeedbackId !== (string) $feedback->id)) {
            return response()->json([
                'message' => 'The submitted feedback form does not match the deployed form.',
                'errors' => [
                    'feedback_id' => ['The submitted feedback form does not match the deployed form.'],
                ],
            ], 422);
        }

        $pData = $request->getParticipantData();
        $answers = $request->getAnswers();

        $submission = $persistenceService->persistSubmission(
            $event,
            $feedback,
            $pData,
            $answers
        );

        return (new FbSubmissionResource($submission->load(['feedback', 'participant.function'])))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Validate structural format of the provided API key.
     */
    protected function isValidApiKeyFormat(string $apiKey): bool
    {
        $trimmed = trim($apiKey);
        if ($trimmed === '' || strlen($trimmed) > 64) {
            return false;
        }

        return (bool) preg_match('/^[a-zA-Z0-9_\-]+$/', $trimmed);
    }
}
