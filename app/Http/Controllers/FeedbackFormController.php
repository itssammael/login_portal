<?php

namespace App\Http\Controllers;

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
use Inertia\Inertia;
use Inertia\Response;

class FeedbackFormController extends Controller
{
    /**
     * Display the feedback form questionnaire for the active system event.
     */
    public function show(Request $request, FeedbackSchemaValidator $schemaValidator): Response
    {
        $user = $request->user();

        // Resolve event bound to this application identity's configured API key
        $event = $this->resolveConfiguredEvent();
        if ($event) {
            $event->load(['functions:id,function,details']);
        }

        if (! $event) {
            return Inertia::render('Feedback/Form', [
                'event' => null,
                'form' => null,
                'functions' => [],
                'lookups' => [
                    'agencies' => Agency::getCachedList(),
                    'designations' => Designation::getCachedList(),
                ],
                'userDefaults' => [
                    'name' => $user?->name,
                    'agency' => $user?->section?->department?->name ?? '',
                    'designation' => '',
                ],
            ]);
        }

        $feedback = $event->feedback()->first();
        $resolvedSchema = $feedback ? $schemaValidator->resolveSchemaForEvent($feedback->schema, $event) : null;

        $functions = $event->functions()
            ->orderBy('function')
            ->get(['fb_functions.id', 'fb_functions.function', 'fb_functions.details']);

        return Inertia::render('Feedback/Form', [
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
            'userDefaults' => [
                'name' => $user?->name,
                'agency' => $user?->section?->department?->name ?? '',
                'designation' => '',
            ],
        ]);
    }

    /**
     * Submit feedback for the active system evaluation event.
     */
    public function submit(
        SubmitFeedbackRequest $request,
        FeedbackSchemaValidator $schemaValidator
    ): JsonResponse {
        $event = $this->resolveConfiguredEvent();

        if (! $event) {
            return response()->json([
                'message' => 'No active feedback evaluation event is configured for this application.',
            ], 404);
        }

        $eventId = $request->input('event_id');
        if ($eventId !== null && (! is_numeric($eventId) || (string) $eventId !== (string) $event->id)) {
            return response()->json([
                'message' => 'The submitted event identifier does not match the configured application event.',
            ], 422);
        }

        $feedbackId = $request->input('feedback_id', $request->input('form_id'));
        if ($feedbackId !== null) {
            if (! is_numeric($feedbackId)) {
                return response()->json([
                    'message' => 'The submitted feedback form does not match the configured evaluation event.',
                ], 422);
            }

            $feedback = $event->feedback()->where('id', (int) $feedbackId)->first();
            if (! $feedback) {
                return response()->json([
                    'message' => 'The submitted feedback form does not match the configured evaluation event.',
                ], 422);
            }
        } else {
            $feedback = $event->feedback()->first();
            if (! $feedback) {
                return response()->json([
                    'message' => 'No questionnaire has been configured for this event.',
                ], 404);
            }
        }

        $pData = $request->getParticipantData();
        $answers = $request->getAnswers();

        // Validate submission answers against schema in context of this event
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

            // 1. Resolve or dynamically create Agency
            $agencyName = Agency::findOrCreateByName($agencyInput);

            // 2. Resolve or dynamically create Designation
            $designationName = Designation::findOrCreateByName($designationInput);

            // 3. Resolve Function (must belong to this event)
            $functionId = null;
            if ($functionInput && is_numeric($functionInput)) {
                $func = $event->functions()->where('fb_functions.id', (int) $functionInput)->first();
                $functionId = $func?->id;
            }

            // 4. Create Participant (name is nullable for anonymous submissions, custom_function persisted)
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

    /**
     * Resolve the configured feedback event for this application identity.
     */
    private function resolveConfiguredEvent(): ?FbEvent
    {
        $apiKey = config('services.feedback.api_key');

        if (empty($apiKey)) {
            return null;
        }

        return FbEvent::query()
            ->whereNull('deleted_at')
            ->where('api_key', $apiKey)
            ->whereHas('feedback')
            ->first();
    }
}
