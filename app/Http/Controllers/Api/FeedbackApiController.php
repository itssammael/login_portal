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
            $name = $pData['name'] ?? ($validatedData['name'] ?? null);
            $agencyInput = $pData['agency'] ?? ($validatedData['agency'] ?? null);
            $designationInput = $pData['designation'] ?? ($validatedData['designation'] ?? null);
            $functionInput = $pData['function_id'] ?? ($validatedData['function'] ?? ($validatedData['function_id'] ?? null));
            $customFunctionInput = $pData['custom_function'] ?? ($validatedData['function_other'] ?? ($validatedData['custom_function'] ?? null));
            $yearsInDesignation = $pData['years_in_designation'] ?? ($validatedData['years_in_designation'] ?? 0);
            $location = $pData['location'] ?? ($validatedData['location'] ?? null);
            $noOfExercises = $pData['no_of_exercises'] ?? ($validatedData['no_of_exercises'] ?? 0);

            // 1. Dynamic / Cached Agency creation
            $agencyName = Agency::findOrCreateByName($agencyInput);

            // 2. Dynamic / Cached Designation creation
            $designationName = Designation::findOrCreateByName($designationInput);

            // 3. Resolve Function (must belong to this event)
            $functionId = null;
            if ($functionInput && is_numeric($functionInput)) {
                $func = $event->functions()->where('fb_functions.id', (int) $functionInput)->first();
                $functionId = $func?->id;
            }

            // 4. Create Participant (name is nullable for anonymous participants)
            $participant = FbParticipant::create([
                'name' => ! empty($name) ? $name : null,
                'function_id' => $functionId,
                'custom_function' => $customFunctionInput,
                'agency' => $agencyName,
                'designation' => $designationName,
                'years_in_designation' => (int) $yearsInDesignation,
                'location' => $location,
                'no_of_exercises' => (int) $noOfExercises,
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
