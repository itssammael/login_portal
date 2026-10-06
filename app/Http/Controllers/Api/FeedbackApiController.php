<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\SubmitFeedbackRequest;
use App\Http\Resources\FbSubmissionResource;
use App\Models\Agency;
use App\Models\Designation;
use App\Models\FbEvent;
use App\Models\FbParticipant;
use App\Models\FbSubmission;
use App\Services\FeedbackSchemaValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
        FeedbackSchemaValidator $schemaValidator
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

        // Validate submission answers against schema in the context of this event
        $validatedData = $schemaValidator->validateSubmissionData($feedback->schema, $answers, $event);

        $submission = DB::transaction(function () use ($event, $feedback, $pData, $validatedData) {
            // 1. Dynamic / Cached Agency creation
            $agencyName = Agency::findOrCreateByName($pData['agency'] ?? null);

            // 2. Dynamic / Cached Designation creation
            $designationName = Designation::findOrCreateByName($pData['designation'] ?? null);

            // 3. Resolve Function (must belong to this event)
            $functionId = $pData['function_id'] ?? null;
            if ($functionId) {
                $func = $event->functions()->where('fb_functions.id', $functionId)->first();
                $functionId = $func?->id;
            }

            // 4. Create Participant (name is nullable for anonymous participants)
            $participant = FbParticipant::create([
                'name' => ! empty($pData['name']) ? $pData['name'] : null,
                'function_id' => $functionId,
                'agency' => $agencyName,
                'designation' => $designationName,
                'years_in_designation' => $pData['years_in_designation'] ?? 0,
                'location' => $pData['location'] ?? null,
                'no_of_exercises' => $pData['no_of_exercises'] ?? 0,
            ]);

            // 5. Create Submission
            return FbSubmission::create([
                'feedback_id' => $feedback->id,
                'participant_id' => $participant->id,
                'data' => $validatedData,
            ]);
        });

        return (new FbSubmissionResource($submission->load(['feedback', 'participant.function'])))
            ->response()
            ->setStatusCode(201);
    }
}
