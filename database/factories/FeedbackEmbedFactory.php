<?php

namespace Database\Factories;

use App\Models\FbEvent;
use App\Models\FeedbackEmbed;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<FeedbackEmbed>
 */
class FeedbackEmbedFactory extends Factory
{
    protected $model = FeedbackEmbed::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => FbEvent::factory(),
            'public_id' => (string) Str::uuid(),
            'client_id' => 'emb_'.Str::random(32),
            'client_secret' => Hash::make('secret_'.Str::random(32)),
            'allowed_origins' => ['https://trusted.example.gov.ph'],
            'is_active' => true,
        ];
    }
}
