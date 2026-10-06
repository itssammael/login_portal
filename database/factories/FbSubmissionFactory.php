<?php

namespace Database\Factories;

use App\Models\FbParticipant;
use App\Models\FbSubmission;
use App\Models\Feedback;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FbSubmission>
 */
class FbSubmissionFactory extends Factory
{
    protected $model = FbSubmission::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'feedback_id' => Feedback::factory(),
            'participant_id' => FbParticipant::factory(),
            'data' => [
                'overall_rating' => 'excellent',
                'drill_clarity' => 'very_clear',
                'recommendations' => 'Great experience.',
            ],
        ];
    }
}
