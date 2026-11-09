<?php

namespace App\Http\Controllers;

use App\Http\Requests\Api\SubmitFeedbackRequest;
use App\Http\Resources\FbSubmissionResource;
use App\Models\FeedbackEmbed;
use App\Services\FeedbackSchemaValidator;
use App\Services\FeedbackSubmissionPersistenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

        // When unpublished or saved as draft, public users/embeds see a proper "Not Available" message
        if ($feedback->isDraft() && ! $request->user()?->isAdmin()) {
            $csp = $this->buildContentSecurityPolicy($embed->allowed_origins, $request->isSecure());

            $token = (string) $request->query('token', $request->input('token', ''));

            $inertiaResponse = Inertia::render('Feedback/Embed', [
                'event' => [
                    'id' => $event->id,
                    'name' => $event->name,
                    'details' => $event->details,
                ],
                'form' => null,
                'isAvailable' => false,
                'unavailableReason' => 'draft',
                'unavailableMessage' => 'This embedded feedback form is currently unpublished or still saved as draft and is not available for submissions.',
                'publicId' => $publicId,
                'sessionToken' => $token,
                'userDefaults' => [],
                'allowedOrigins' => $embed->allowed_origins ?? [],
            ]);

            $response = $inertiaResponse->toResponse($request);
            $response->headers->set('Content-Security-Policy', $csp);
            $response->headers->remove('X-Frame-Options');

            return $response;
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
        $csp = $this->buildContentSecurityPolicy($embed->allowed_origins, $request->isSecure());

        $inertiaResponse = Inertia::render('Feedback/Embed', [
            'event' => [
                'id' => $event->id,
                'name' => $event->name,
                'details' => $event->details,
            ],
            'form' => [
                'id' => $feedback->id,
                'status' => $feedback->status ?? 'published',
                'is_draft' => $feedback->isDraft(),
                'schema' => $resolvedSchema,
            ],
            'isAvailable' => true,
            'unavailableReason' => null,
            'unavailableMessage' => null,
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
        FeedbackSubmissionPersistenceService $persistenceService
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

        if ($feedback->isDraft()) {
            return response()->json([
                'status' => 'error',
                'message' => 'This feedback form is currently in draft mode and is not accepting responses.',
                'errors' => [
                    'general' => ['This feedback form is currently in draft mode and is not accepting responses.'],
                ],
            ], 422);
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

        $submission = $persistenceService->persistSubmission(
            $event,
            $feedback,
            $pData,
            $answers,
            [
                'embed_id' => $embed->id,
                'embed_session_id' => $session->id,
                'respondent_hash' => $session->respondent_hash,
            ],
            function () use ($session): void {
                if (! $session->markAsUsed()) {
                    abort(404, 'This feedback session has expired or has already been submitted.');
                }
            }
        );

        return (new FbSubmissionResource($submission->load(['feedback', 'participant.function'])))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Build the CSP frame-ancestors directive from configured allowed origins.
     *
     * @param  array<int, string>|null  $allowedOrigins
     */
    protected function buildContentSecurityPolicy(?array $allowedOrigins, bool $isSecure = false): string
    {
        $directives = [];

        if (empty($allowedOrigins)) {
            $directives[] = "frame-ancestors 'self'";
        } else {
            $sanitizedOrigins = [];
            foreach ($allowedOrigins as $origin) {
                $origin = trim((string) $origin);
                if (preg_match('/^https?:\/\/(?:\*\.)?[a-zA-Z0-9.-]+(?::[1-9][0-9]{0,4})?$/', $origin)) {
                    $sanitizedOrigins[] = $origin;
                }
            }

            if (empty($sanitizedOrigins)) {
                $directives[] = "frame-ancestors 'self'";
            } else {
                $directives[] = "frame-ancestors 'self' ".implode(' ', array_unique($sanitizedOrigins));
            }
        }

        if ($isSecure) {
            $directives[] = 'upgrade-insecure-requests';
        }

        return implode('; ', $directives).';';
    }
}
