<?php

namespace Database\Factories;

use App\Models\FbEvent;
use App\Models\Feedback;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Feedback>
 */
class FeedbackFactory extends Factory
{
    protected $model = Feedback::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => FbEvent::factory(),
            'schema' => [
                'fields' => [
                    [
                        'id' => 'overall_rating',
                        'particular' => 'Overall drill evaluation',
                        'type' => 'radio',
                        'weight' => 1,
                        'options' => [
                            ['value' => 'excellent', 'label' => '⭐⭐⭐⭐⭐ Excellent'],
                            ['value' => 'good', 'label' => '👍 Good'],
                            ['value' => 'fair', 'label' => '👌 Fair'],
                            ['value' => 'poor', 'label' => '👎 Poor'],
                        ],
                    ],
                    [
                        'id' => 'drill_clarity',
                        'particular' => 'Clarity of role instructions',
                        'type' => 'select',
                        'weight' => 2,
                        'options' => [
                            ['value' => 'very_clear', 'label' => '🎯 Very Clear'],
                            ['value' => 'clear', 'label' => 'Clear'],
                            ['value' => 'needs_clarification', 'label' => 'Needs Clarification'],
                        ],
                    ],
                    [
                        'id' => 'recommendations',
                        'particular' => 'Recommendations and Key Takeaways',
                        'type' => 'text',
                        'weight' => 3,
                        'options' => [],
                    ],
                ],
            ],
        ];
    }
}
