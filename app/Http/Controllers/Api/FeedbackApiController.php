<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\SubmitFeedbackRequest;
use App\Http\Resources\FbSubmissionResource;
use App\Models\Agency;
use App\Models\Designation;
use App\Models\FbEvent;
use App\Services\FeedbackSchemaValidator;
use App\Services\FeedbackSubmissionPersistenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FeedbackApiController extends Controller
{
    /**
     * Retrieve active event feedback form schema, functions, and lookup lists.
     */
    public function form(Request $request, FeedbackSchemaValidator $schemaValidator): JsonResponse
    {
        /** @var FbEvent $event */
        $event = $request->attributes->get('feedback_event');

        $feedback = $event->feedback()->first();

        if (! $feedback) {
            return response()->json([
                'message' => 'No feedback form questionnaire has been configured for this event.',
            ], 404);
        }

        $functions = $event->functions()
            ->orderBy('function')
            ->get(['fb_functions.id', 'fb_functions.function', 'fb_functions.details']);

        $resolvedSchema = $schemaValidator->resolveSchemaForEvent($feedback->schema, $event);

        return response()->json([
            'status' => 'success',
            'data' => [
                'event' => [
                    'id' => $event->id,
                    'name' => $event->name,
                    'details' => $event->details,
                ],
                'form' => [
                    'id' => $feedback->id,
                    'schema' => $resolvedSchema,
                ],
                'functions' => $functions,
                'lookups' => [
                    'agencies' => Agency::getCachedList(),
                    'designations' => Designation::getCachedList(),
                ],
            ],
        ]);
    }

    /**
     * Retrieve cached lookup lists for agencies and designations.
     */
    public function lookups(): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => [
                'agencies' => Agency::getCachedList(),
                'designations' => Designation::getCachedList(),
            ],
        ]);
    }

    /**
     * Submit participant feedback for the authenticated event.
     */
    public function submit(
        SubmitFeedbackRequest $request,
        FeedbackSubmissionPersistenceService $persistenceService
    ): JsonResponse {
        /** @var FbEvent $event */
        $event = $request->attributes->get('feedback_event');

        $feedback = $event->feedback()->first();

        if (! $feedback) {
            return response()->json([
                'message' => 'No feedback form questionnaire has been configured for this event.',
            ], 404);
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
}
