<?php

namespace App\Http\Controllers;

use App\Http\Requests\Api\SubmitFeedbackRequest;
use App\Http\Resources\FbSubmissionResource;
use App\Models\Agency;
use App\Models\Designation;
use App\Models\FbParticipant;
use App\Models\FbSubmission;
use App\Models\FeedbackEmbed;
use App\Services\FeedbackSchemaValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class FeedbackEmbedController extends Controller
{
    /**
     * Render the standalone embedded feedback form for a valid short-lived session token.
     */
    public function show(
        Request $request,
        string $publicId,
        FeedbackSchemaValidator $schemaValidator
    ): SymfonyResponse {
        $embed = FeedbackEmbed::query()
            ->with(['event' => fn ($q) => $q->whereNull('deleted_at')->with('feedback')])
            ->where('public_id', $publicId)
            ->where('is_active', true)
            ->first();

        if (! $embed) {
            abort(404);
        }

        $event = $embed->event;
        $feedback = $event?->feedback;

        if (! $event || ! $feedback) {
            abort(404);
        }

        $token = (string) $request->query('token', $request->input('token', ''));
        if (empty($token)) {
            abort(404);
        }

        $tokenHash = hash('sha256', $token);
        $session = $embed->sessions()
            ->valid()
            ->where('token_hash', $tokenHash)
            ->first();

        if (! $session) {
            abort(404);
        }

        $resolvedSchema = $schemaValidator->resolveSchemaForEvent($feedback->schema, $event);

        // Build Content-Security-Policy frame-ancestors directive
        $csp = $this->buildContentSecurityPolicy($embed->allowed_origins);

        $inertiaResponse = Inertia::render('Feedback/Embed', [
            'event' => [
                'id' => $event->id,
                'name' => $event->name,
                'details' => $event->details,
            ],
            'form' => [
                'id' => $feedback->id,
                'schema' => $resolvedSchema,
            ],
            'publicId' => $publicId,
            'sessionToken' => $token,
            'userDefaults' => $session->metadata['user_defaults'] ?? [],
            'allowedOrigins' => $embed->allowed_origins ?? [],
        ]);

        $response = $inertiaResponse->toResponse($request);
        $response->headers->set('Content-Security-Policy', $csp);
        $response->headers->remove('X-Frame-Options');

        return $response;
    }

    /**
     * Submit feedback for an embedded questionnaire session.
     */
    public function submit(
        SubmitFeedbackRequest $request,
        string $publicId,
        FeedbackSchemaValidator $schemaValidator
    ): JsonResponse {
        $embed = FeedbackEmbed::query()
            ->with(['event' => fn ($q) => $q->whereNull('deleted_at')->with('feedback')])
            ->where('public_id', $publicId)
            ->where('is_active', true)
            ->first();

        if (! $embed) {
            abort(404);
        }

        $event = $embed->event;
        $feedback = $event?->feedback;

        if (! $event || ! $feedback) {
            abort(404);
        }

        $token = (string) $request->input('token', $request->query('token', ''));
        if (empty($token)) {
            abort(404);
        }

        $tokenHash = hash('sha256', $token);
        $session = $embed->sessions()
            ->valid()
            ->where('token_hash', $tokenHash)
            ->first();

        if (! $session) {
            return response()->json([
                'status' => 'error',
                'message' => 'This feedback session has expired or has already been submitted.',
            ], 404);
        }

        $pData = $request->getParticipantData();
        $answers = $request->getAnswers();

        // Validate submission answers strictly in context of the resolved embed event
        $validatedData = $schemaValidator->validateSubmissionData($feedback->schema, $answers, $event);

        $submission = DB::transaction(function () use ($event, $feedback, $embed, $session, $pData, $validatedData) {
            // Invalidate session atomically to prevent reuse
            if (! $session->markAsUsed()) {
                abort(404, 'This feedback session has expired or has already been submitted.');
            }

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

            // 4. Create Participant
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

            // 5. Create Submission with embed tracking
            return FbSubmission::create([
                'feedback_id' => $feedback->id,
                'participant_id' => $participant->id,
                'embed_id' => $embed->id,
                'embed_session_id' => $session->id,
                'respondent_hash' => $session->respondent_hash,
                'data' => $validatedData,
            ]);
        });

        return (new FbSubmissionResource($submission->load(['feedback', 'participant.function'])))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Build the CSP frame-ancestors directive from configured allowed origins.
     *
     * @param  array<int, string>|null  $allowedOrigins
     */
    protected function buildContentSecurityPolicy(?array $allowedOrigins): string
    {
        if (empty($allowedOrigins)) {
            return "frame-ancestors 'self';";
        }

        $sanitizedOrigins = [];
        foreach ($allowedOrigins as $origin) {
            $origin = trim((string) $origin);
            if (preg_match('/^https?:\/\/[a-zA-Z0-9.-]+(?::[1-9][0-9]{0,4})?$/', $origin)) {
                $sanitizedOrigins[] = $origin;
            }
        }

        if (empty($sanitizedOrigins)) {
            return "frame-ancestors 'self';";
        }

        $originList = implode(' ', array_unique($sanitizedOrigins));

        return "frame-ancestors 'self' {$originList};";
    }
}
