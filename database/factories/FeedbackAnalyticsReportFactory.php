<?php

namespace Database\Factories;

use App\Models\Feedback;
use App\Models\FeedbackAnalyticsReport;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FeedbackAnalyticsReport> */
class FeedbackAnalyticsReportFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'feedback_id' => Feedback::factory(),
            'configuration' => [
                'title' => 'Evaluation results', 'description' => '', 'footer' => '',
                'columns' => 2, 'show_submission_count' => true, 'refresh_seconds' => 300,
                'period_start' => null, 'period_end' => null, 'sections' => [],
                'charts' => [[
                    'id' => 'rating', 'title' => 'Overall rating', 'question' => 'overall_rating',
                    'type' => 'bar', 'aggregation' => 'count', 'color' => '#24543c',
                    'legend' => true, 'labels' => true, 'annotation' => '', 'width' => 1,
                ]],
            ],
        ];
    }
}
