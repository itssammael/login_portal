<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\CreateEmbedSessionRequest;
use App\Models\FeedbackEmbed;
use Illuminate\Http\JsonResponse;

class FeedbackEmbedSessionApiController extends Controller
{
    /**
     * Create a secure short-lived iframe session token for an authenticated external backend.
     */
    public function store(CreateEmbedSessionRequest $request): JsonResponse
    {
        $clientId = $request->getClientId();
        $clientSecret = $request->getClientSecret();

        $embed = FeedbackEmbed::query()
            ->with(['event' => fn ($q) => $q->whereNull('deleted_at')->with('feedback')])
            ->where('client_id', $clientId)
            ->where('is_active', true)
            ->first();

        if (! $embed || ! $embed->verifySecret($clientSecret)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid or inactive feedback embed credentials.',
            ], 401);
        }

        $event = $embed->event;
        if (! $event || ! $event->feedback) {
            return response()->json([
                'status' => 'error',
                'message' => 'No feedback questionnaire has been configured for this event.',
            ], 422);
        }

        $respondentId = $request->input('respondent_id');
        $metadata = (array) $request->input('metadata', []);

        if ($request->filled('user_defaults')) {
            $metadata['user_defaults'] = $request->input('user_defaults');
        }

        $sessionData = $embed->createSession($respondentId, $metadata);

        return response()->json([
            'status' => 'success',
            'data' => $sessionData,
        ], 201);
    }
}
