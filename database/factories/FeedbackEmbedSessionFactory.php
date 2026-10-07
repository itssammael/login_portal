<?php

namespace Database\Factories;

use App\Models\FeedbackEmbed;
use App\Models\FeedbackEmbedSession;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<FeedbackEmbedSession>
 */
class FeedbackEmbedSessionFactory extends Factory
{
    protected $model = FeedbackEmbedSession::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $token = Str::random(64);

        return [
            'embed_id' => FeedbackEmbed::factory(),
            'token_hash' => hash('sha256', $token),
            'respondent_id' => null,
            'respondent_hash' => null,
            'metadata' => null,
            'expires_at' => now()->addMinutes(5),
            'used_at' => null,
        ];
    }
}
