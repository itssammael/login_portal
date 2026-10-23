<?php

namespace Tests\Feature;

use App\Models\FbEvent;
use App\Models\FbSubmission;
use App\Models\Feedback;
use App\Models\FeedbackAnalyticsReport;
use App\Models\User;
use App\Policies\FeedbackPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class FeedbackAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private function publish(FeedbackAnalyticsReport $report, ?User $user = null, ?int $replace = null): void
    {
        $this->actingAs($user ?? User::factory()->admin()->create())
            ->postJson(route('feedback.analytics.publish', [$report->feedback_id, $report->id]), ['confirm_public' => true, 'replace_report_id' => $replace])
            ->assertOk()->assertJsonPath('has_published_report', true);
    }

    private function publicUrl(FeedbackAnalyticsReport $report): string
    {
        return route('forms.analytics.public', $report->feedback->event->api_key);
    }

    public function test_published_report_renders_saved_charts_and_only_aggregates(): void
    {
        $report = FeedbackAnalyticsReport::factory()->create();
        FbSubmission::factory()->count(5)->create(['feedback_id' => $report->feedback_id, 'data' => ['overall_rating' => 'excellent', 'recommendations' => 'PRIVATE TEXT']]);
        $this->publish($report);
        auth()->forgetGuards();

        $this->get($this->publicUrl($report))->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertInertia(fn (Assert $page) => $page->component('Feedback/Analytics')
                ->where('report.charts.0.type', 'bar')->where('report.charts.0.color', '#24543c')
                ->where('data.submission_count', 5)->where('data.charts.rating.rows.0.value', 5)
                ->missing('submissions')->missing('participants')->missing('auth')->missing('flash'))
            ->assertDontSee('PRIVATE TEXT');
    }

    #[TestWith(['draft'])]
    #[TestWith(['saved'])]
    #[TestWith(['unpublished'])]
    #[TestWith(['archived'])]
    public function test_nonpublished_states_return_404(string $status): void
    {
        $report = FeedbackAnalyticsReport::factory()->create(['status' => $status]);
        $this->get($this->publicUrl($report))->assertNotFound();
    }

    public function test_missing_report_and_invalid_key_return_404_even_with_submissions(): void
    {
        $form = Feedback::factory()->create();
        FbSubmission::factory()->create(['feedback_id' => $form->id]);
        $this->get(route('forms.analytics.public', $form->event->api_key))->assertNotFound();
        $this->get(route('forms.analytics.public', 'invalid'))->assertNotFound();
    }

    public function test_form_creator_can_publish_and_policy_rejects_unrelated_users_and_event_creator(): void
    {
        $creator = User::factory()->create();
        $form = Feedback::factory()->create(['created_by' => $creator->id]);
        $report = FeedbackAnalyticsReport::factory()->create(['feedback_id' => $form->id]);
        $policy = new FeedbackPolicy;
        $this->assertTrue($policy->manageAnalytics($creator, $form));
        $this->assertTrue($policy->manageAnalytics(User::factory()->admin()->create(), $form));
        $this->assertFalse($policy->manageAnalytics(User::factory()->create(), $form));
        $this->assertFalse($policy->manageAnalytics($form->event->creator, $form));

        $this->publish($report, $creator);
        $this->assertDatabaseHas('feedback_analytics_reports', ['id' => $report->id, 'status' => 'published', 'published_by' => $creator->id]);
    }

    public function test_unauthorized_users_cannot_read_edit_publish_or_unpublish_reports(): void
    {
        $report = FeedbackAnalyticsReport::factory()->create();
        $this->actingAs(User::factory()->create());
        $args = [$report->feedback_id, $report->id];
        $this->getJson(route('feedback.analytics.publication', $report->feedback_id))->assertForbidden();
        $this->putJson(route('feedback.analytics.update', $args), ['configuration' => $report->configuration])->assertForbidden();
        $this->postJson(route('feedback.analytics.publish', $args), ['confirm_public' => true, 'replace_report_id' => null])->assertForbidden();
        $this->postJson(route('feedback.analytics.unpublish', $args))->assertForbidden();
        $this->assertDatabaseHas('feedback_analytics_reports', ['id' => $report->id, 'status' => 'draft']);
    }

    public function test_guests_cannot_access_builder_or_raw_submissions_or_write_reports(): void
    {
        $report = FeedbackAnalyticsReport::factory()->create();
        $this->getJson(route('feedback.analytics.publication', $report->feedback_id))->assertUnauthorized();
        $this->postJson(route('feedback.analytics.store', $report->feedback_id), ['configuration' => $report->configuration])->assertUnauthorized();
        $this->postJson(route('feedback.analytics.publish', [$report->feedback_id, $report->id]))->assertUnauthorized();
        $this->get('/admin/feedback/submissions')->assertRedirect(route('login'));
        $this->post($this->publicUrl($report), ['configuration' => []])->assertMethodNotAllowed();
    }

    public function test_report_must_belong_to_form(): void
    {
        $report = FeedbackAnalyticsReport::factory()->create();
        $other = Feedback::factory()->create();
        $this->actingAs(User::factory()->admin()->create());
        $this->postJson(route('feedback.analytics.publish', [$other->id, $report->id]), ['confirm_public' => true, 'replace_report_id' => null])->assertNotFound();
        $this->putJson(route('feedback.analytics.update', [$other->id, $report->id]), ['configuration' => $report->configuration])->assertNotFound();
        $this->postJson(route('feedback.analytics.unpublish', [$other->id, $report->id]))->assertNotFound();
    }

    public function test_replacement_requires_explicit_selection_and_only_selected_report_resolves(): void
    {
        $first = FeedbackAnalyticsReport::factory()->create();
        $second = FeedbackAnalyticsReport::factory()->create(['feedback_id' => $first->feedback_id, 'configuration' => array_replace($first->configuration, ['title' => 'Replacement'])]);
        $this->publish($first);
        $this->postJson(route('feedback.analytics.publish', [$second->feedback_id, $second->id]), ['confirm_public' => true, 'replace_report_id' => null])->assertConflict();
        $this->get($this->publicUrl($first))->assertInertia(fn (Assert $page) => $page->where('report.title', 'Evaluation results'));
        $this->publish($second, replace: $first->id);
        $this->assertDatabaseHas('feedback_analytics_reports', ['id' => $first->id, 'status' => 'unpublished']);
        $this->get($this->publicUrl($first))->assertInertia(fn (Assert $page) => $page->where('report.title', 'Replacement'));
    }

    public function test_saving_and_editing_drafts_does_not_change_publication_snapshot(): void
    {
        $form = Feedback::factory()->create();
        $configuration = FeedbackAnalyticsReport::factory()->make()->configuration;
        $this->actingAs(User::factory()->admin()->create());
        $id = $this->postJson(route('feedback.analytics.store', $form->id), ['configuration' => $configuration])->assertCreated()->json('report.id');
        $report = FeedbackAnalyticsReport::findOrFail($id);
        $this->get($this->publicUrl($report))->assertNotFound();
        $this->publish($report);
        $configuration['title'] = 'Private draft edits';
        $this->putJson(route('feedback.analytics.update', [$form->id, $id]), ['configuration' => $configuration])->assertOk();
        $this->get($this->publicUrl($report))->assertInertia(fn (Assert $page) => $page->where('report.title', 'Evaluation results'))->assertDontSee('Private draft edits');
        $this->publish($report);
        $this->get($this->publicUrl($report))->assertInertia(fn (Assert $page) => $page->where('report.title', 'Private draft edits'));
    }

    public function test_new_submissions_refresh_without_republication_and_unpublish_disables_cached_page(): void
    {
        $this->freezeTime();
        $report = FeedbackAnalyticsReport::factory()->create();
        FbSubmission::factory()->count(5)->create(['feedback_id' => $report->feedback_id]);
        $this->publish($report);
        $this->get($this->publicUrl($report))->assertInertia(fn (Assert $page) => $page->where('data.submission_count', 5));
        FbSubmission::factory()->count(5)->create(['feedback_id' => $report->feedback_id]);
        $this->travel(301)->seconds();
        $this->get($this->publicUrl($report))->assertInertia(fn (Assert $page) => $page->where('data.submission_count', 10)->where('data.charts.rating.rows.0.value', 10));
        $this->postJson(route('feedback.analytics.unpublish', [$report->feedback_id, $report->id]))->assertOk()->assertJsonPath('public_url', null);
        $this->get($this->publicUrl($report))->assertNotFound();
    }

    public function test_small_groups_are_suppressed_including_complementary_cells(): void
    {
        $report = FeedbackAnalyticsReport::factory()->create();
        FbSubmission::factory()->count(5)->create(['feedback_id' => $report->feedback_id]);
        FbSubmission::factory()->create(['feedback_id' => $report->feedback_id, 'data' => ['overall_rating' => 'poor']]);
        $this->publish($report);
        $this->get($this->publicUrl($report))->assertInertia(fn (Assert $page) => $page
            ->where('data.charts.rating.suppressed', true)->where('data.charts.rating.rows', [])->where('data.charts.rating.answered', null));
    }

    public function test_listing_exposes_only_publication_metadata_and_keeps_builder_available(): void
    {
        $report = FeedbackAnalyticsReport::factory()->create();
        $this->actingAs(User::factory()->admin()->create())->get(route('feedback.form'))->assertInertia(fn (Assert $page) => $page
            ->where('forms.0.analytics.has_published_report', false)->where('forms.0.analytics.public_url', null)->where('forms.0.can_manage_analytics', true));
        $this->publish($report);
        $this->get(route('feedback.form'))->assertInertia(fn (Assert $page) => $page
            ->where('forms.0.analytics.has_published_report', true)->where('forms.0.analytics.public_url', '/forms/anal/'.$report->feedback->event->api_key)
            ->missing('forms.0.analytics.configuration'));
    }

    #[TestWith(['private'])]
    #[TestWith(['deleted'])]
    #[TestWith(['archived'])]
    #[TestWith(['schema'])]
    #[TestWith(['event'])]
    public function test_invalidated_publications_are_unavailable(string $change): void
    {
        $report = FeedbackAnalyticsReport::factory()->create();
        $this->publish($report);
        $report->refresh();
        match ($change) {
            'private' => $report->forceFill(['visibility' => 'private'])->save(),
            'deleted' => $report->delete(),
            'archived' => $report->forceFill(['status' => 'archived'])->save(),
            'schema' => $report->feedback->update(['schema' => ['fields' => []]]),
            'event' => $report->feedback->event->delete(),
        };
        $this->get($this->publicUrl($report))->assertNotFound();
    }

    #[TestWith(['recommendations'])]
    #[TestWith(['missing_question'])]
    public function test_rejects_free_text_and_nonexistent_mappings(string $question): void
    {
        $report = FeedbackAnalyticsReport::factory()->create();
        $configuration = $report->configuration;
        $configuration['charts'][0]['question'] = $question;
        $this->actingAs(User::factory()->admin()->create())->putJson(route('feedback.analytics.update', [$report->feedback_id, $report->id]), ['configuration' => $configuration])
            ->assertUnprocessable()->assertJsonValidationErrors('configuration.charts.0.question');
    }

    public function test_rejects_unapproved_sensitive_breakdowns_and_unknown_configuration_keys(): void
    {
        $report = FeedbackAnalyticsReport::factory()->create();
        $schema = $report->feedback->schema;
        $schema['fields'][0]['particular'] = 'Age group';
        $report->feedback->update(['schema' => $schema]);
        $this->actingAs(User::factory()->admin()->create());
        $this->postJson(route('feedback.analytics.publish', [$report->feedback_id, $report->id]), ['confirm_public' => true, 'replace_report_id' => null])->assertUnprocessable();
        $this->postJson(route('feedback.analytics.publish', [$report->feedback_id, $report->id]), ['confirm_public' => true, 'replace_report_id' => null, 'approve_sensitive' => true])->assertOk();
        $configuration = $report->configuration;
        $configuration['raw_submissions'] = true;
        $this->putJson(route('feedback.analytics.update', [$report->feedback_id, $report->id]), ['configuration' => $configuration])->assertUnprocessable()->assertJsonValidationErrors('configuration');
    }

    #[TestWith(['mean', 3])]
    #[TestWith(['sum', 15])]
    #[TestWith(['median', 3])]
    #[TestWith(['min', 1])]
    #[TestWith(['max', 5])]
    #[TestWith(['stddev', 1.41])]
    public function test_numeric_calculations_use_saved_period_and_ignore_public_filters(string $aggregation, int|float $expected): void
    {
        $this->freezeTime();
        $form = Feedback::factory()->create(['schema' => ['fields' => [
            ['id' => 'score', 'type' => 'number', 'particular' => 'Score', 'weight' => 1],
        ]]]);
        $configuration = FeedbackAnalyticsReport::factory()->make()->configuration;
        $configuration['charts'][0]['question'] = 'score';
        $configuration['charts'][0]['aggregation'] = $aggregation;
        $configuration['period_start'] = now()->toDateString();
        $report = FeedbackAnalyticsReport::factory()->create(['feedback_id' => $form->id, 'configuration' => $configuration]);
        foreach ([1, 2, 3, 4, 5] as $value) {
            FbSubmission::factory()->create(['feedback_id' => $form->id, 'data' => ['score' => $value]]);
        }
        FbSubmission::factory()->create(['feedback_id' => $form->id, 'data' => ['score' => 100], 'created_at' => now()->subDay()]);
        $this->publish($report);

        $this->get($this->publicUrl($report).'?question=name&participant_id=1&period_start=2000-01-01')
            ->assertInertia(fn (Assert $page) => $page->where('data.charts.rating.rows.0.value', $expected)->where('data.submission_count', 5));
    }

    public function test_percentage_aggregations_exclude_unknown_and_custom_answers(): void
    {
        $report = FeedbackAnalyticsReport::factory()->create();
        $configuration = $report->configuration;
        $configuration['charts'][0]['aggregation'] = 'percentage';
        $report->update(['configuration' => $configuration]);
        FbSubmission::factory()->count(5)->create(['feedback_id' => $report->feedback_id]);
        FbSubmission::factory()->count(5)->create(['feedback_id' => $report->feedback_id, 'data' => ['overall_rating' => 'poor']]);
        FbSubmission::factory()->create(['feedback_id' => $report->feedback_id, 'data' => ['overall_rating' => 'PRIVATE CUSTOM NAME']]);
        $this->publish($report);

        $this->get($this->publicUrl($report))->assertInertia(fn (Assert $page) => $page
            ->where('data.charts.rating.rows.0.value', 50)->where('data.charts.rating.rows.3.value', 50)->where('data.charts.rating.answered', 10))
            ->assertDontSee('PRIVATE CUSTOM NAME');
    }

    public function test_identifiers_are_rejected_even_with_administrator_approval(): void
    {
        $report = FeedbackAnalyticsReport::factory()->create();
        $schema = $report->feedback->schema;
        $schema['fields'][0]['particular'] = 'Participant name';
        $report->feedback->update(['schema' => $schema]);
        $this->actingAs(User::factory()->admin()->create())->postJson(route('feedback.analytics.publish', [$report->feedback_id, $report->id]), [
            'confirm_public' => true, 'replace_report_id' => null, 'approve_sensitive' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('configuration.charts.0.question');
        $this->get($this->publicUrl($report))->assertNotFound();
    }

    public function test_owner_cannot_approve_sensitive_questions_and_confirmation_is_required(): void
    {
        $creator = User::factory()->create();
        $form = Feedback::factory()->create(['created_by' => $creator->id]);
        $report = FeedbackAnalyticsReport::factory()->create(['feedback_id' => $form->id]);
        $this->actingAs($creator);
        $this->postJson(route('feedback.analytics.publish', [$form->id, $report->id]), ['confirm_public' => true, 'replace_report_id' => null, 'approve_sensitive' => true])->assertForbidden();
        $this->postJson(route('feedback.analytics.publish', [$form->id, $report->id]), ['replace_report_id' => null])->assertUnprocessable()->assertJsonValidationErrors('confirm_public');
    }

    public function test_form_ownership_is_recorded_on_creation_and_preserved_on_edit(): void
    {
        $admin = User::factory()->admin()->create();
        $event = FbEvent::factory()->create();
        $schema = Feedback::factory()->make()->schema;
        $this->actingAs($admin)->post(route('admin.feedback.forms.save'), ['event_id' => $event->id, 'schema' => $schema])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('feedback', ['event_id' => $event->id, 'created_by' => $admin->id]);
        $other = User::factory()->admin()->create();
        $this->actingAs($other)->post(route('admin.feedback.forms.save'), ['event_id' => $event->id, 'schema' => $schema])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('feedback', ['event_id' => $event->id, 'created_by' => $admin->id]);
    }

    public function test_reporting_period_can_have_only_an_end_but_cannot_be_reversed(): void
    {
        $report = FeedbackAnalyticsReport::factory()->create();
        $configuration = $report->configuration;
        $configuration['period_end'] = '2026-10-20';
        $this->actingAs(User::factory()->admin()->create());
        $this->putJson(route('feedback.analytics.update', [$report->feedback_id, $report->id]), ['configuration' => $configuration])->assertOk();
        $configuration['period_start'] = '2026-10-21';
        $this->putJson(route('feedback.analytics.update', [$report->feedback_id, $report->id]), ['configuration' => $configuration])
            ->assertUnprocessable()->assertJsonValidationErrors('configuration.period_end');
    }

    public function test_public_route_refuses_a_cross_form_selected_report(): void
    {
        $report = FeedbackAnalyticsReport::factory()->create();
        $this->publish($report);
        $other = Feedback::factory()->create();
        $other->published_analytics_report_id = $report->id;
        $other->save();

        $this->get(route('forms.analytics.public', $other->event->api_key))->assertNotFound();
    }

    public function test_visual_builder_layout_positions_and_section_width_are_saved(): void
    {
        $report = FeedbackAnalyticsReport::factory()->create();
        $configuration = $report->configuration;
        $configuration['charts'][0]['position'] = 1;
        $configuration['charts'][0]['width'] = 2;
        $configuration['sections'] = [[
            'id' => 'intro-section',
            'title' => 'Executive summary',
            'body' => 'Published report context.',
            'width' => 3,
            'position' => 0,
        ]];

        $this->actingAs(User::factory()->admin()->create())
            ->putJson(route('feedback.analytics.update', [$report->feedback_id, $report->id]), ['configuration' => $configuration])
            ->assertOk()
            ->assertJsonPath('report.configuration.sections.0.position', 0)
            ->assertJsonPath('report.configuration.charts.0.width', 2);

        $report->refresh();
        $this->assertSame('intro-section', $report->configuration['sections'][0]['id']);
        $this->assertSame(1, $report->configuration['charts'][0]['position']);
    }

    public function test_blank_section_container_and_nested_blocks_are_saved_and_validated(): void
    {
        $report = FeedbackAnalyticsReport::factory()->create();
        $configuration = $report->configuration;
        $configuration['sections'] = [
            [
                'id' => 'container-1',
                'kind' => 'blank',
                'title' => 'Main Grid Container',
                'show_heading' => false,
                'body' => '',
                'width' => 12,
                'height' => 6,
                'rows' => 2,
                'columns' => 12,
                'border_width' => 2,
                'border_radius' => 16,
                'border_style' => 'dashed',
                'border_color' => '#28704a',
                'parent_id' => null,
                'position' => 0,
            ],
            [
                'id' => 'inner-container-1',
                'kind' => 'blank',
                'title' => 'Nested Blank Section',
                'show_heading' => true,
                'body' => '',
                'width' => 12,
                'height' => 5,
                'rows' => 1,
                'columns' => 12,
                'border_width' => 1,
                'border_radius' => 12,
                'border_style' => 'solid',
                'border_color' => '#e7dcc3',
                'parent_id' => 'container-1',
                'position' => 0,
            ],
            [
                'id' => 'nested-note',
                'kind' => 'text',
                'title' => 'Nested Note',
                'body' => 'Inside nested blank section',
                'width' => 6,
                'height' => 3,
                'parent_id' => 'inner-container-1',
                'position' => 0,
            ],
        ];
        $configuration['charts'][0]['parent_id'] = 'inner-container-1';
        $configuration['charts'][0]['position'] = 1;

        $this->actingAs(User::factory()->admin()->create())
            ->putJson(route('feedback.analytics.update', [$report->feedback_id, $report->id]), ['configuration' => $configuration])
            ->assertOk()
            ->assertJsonPath('report.configuration.sections.0.kind', 'blank')
            ->assertJsonPath('report.configuration.sections.0.show_heading', false)
            ->assertJsonPath('report.configuration.sections.0.rows', 2)
            ->assertJsonPath('report.configuration.sections.0.columns', 12)
            ->assertJsonPath('report.configuration.sections.0.border_width', 2)
            ->assertJsonPath('report.configuration.sections.0.border_radius', 16)
            ->assertJsonPath('report.configuration.sections.0.border_style', 'dashed')
            ->assertJsonPath('report.configuration.sections.1.id', 'inner-container-1')
            ->assertJsonPath('report.configuration.sections.1.kind', 'blank')
            ->assertJsonPath('report.configuration.sections.1.parent_id', 'container-1')
            ->assertJsonPath('report.configuration.sections.2.parent_id', 'inner-container-1')
            ->assertJsonPath('report.configuration.charts.0.parent_id', 'inner-container-1');

        $invalidConfig = $configuration;
        $invalidConfig['sections'][0]['border_style'] = 'invalid-style';
        $this->putJson(route('feedback.analytics.update', [$report->feedback_id, $report->id]), ['configuration' => $invalidConfig])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('configuration.sections.0.border_style');
    }

    public function test_custom_graph_block_calculates_sum_and_average_rows_in_custom_data_table(): void
    {
        $report = FeedbackAnalyticsReport::factory()->create();
        // FeedbackFactory default overall_rating options:
        // [0] excellent (score 4), [1] good (score 3), [2] fair (score 2), [3] poor (score 1)
        FbSubmission::factory()->count(5)->create([
            'feedback_id' => $report->feedback_id,
            'data' => ['overall_rating' => 'excellent', 'drill_clarity' => 'very_clear'],
        ]);

        $configuration = $report->configuration;
        $configuration['charts'][] = [
            'id' => 'custom-ratings-graph',
            'kind' => 'custom',
            'title' => 'Ratings Summary',
            'type' => 'column',
            'aggregation' => 'mean',
            'color' => '#24543c',
            'legend' => true,
            'labels' => true,
            'annotation' => 'Combined rating metrics',
            'width' => 6,
            'height' => 5,
            'position' => 1,
            'rows' => [
                [
                    'id' => 'row-avg',
                    'label' => 'Overall Evaluation (Avg)',
                    'source' => 'question',
                    'question' => 'overall_rating',
                    'operation' => 'average',
                ],
                [
                    'id' => 'row-sum',
                    'label' => 'Overall Evaluation (Sum)',
                    'source' => 'question',
                    'question' => 'overall_rating',
                    'operation' => 'sum',
                ],
                [
                    'id' => 'row-manual',
                    'label' => 'Target Benchmark',
                    'source' => 'manual',
                    'value' => 3.5,
                ],
            ],
        ];

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)
            ->putJson(route('feedback.analytics.update', [$report->feedback_id, $report->id]), ['configuration' => $configuration])
            ->assertOk()
            ->assertJsonPath('report.configuration.charts.1.kind', 'custom')
            ->assertJsonPath('report.configuration.charts.1.rows.0.operation', 'average')
            ->assertJsonPath('report.configuration.charts.1.rows.1.operation', 'sum');

        $this->postJson(route('feedback.analytics.preview', $report->feedback_id), ['configuration' => $configuration])
            ->assertOk()
            ->assertJsonPath('charts.custom-ratings-graph.rows.0.label', 'Overall Evaluation (Avg)')
            ->assertJsonPath('charts.custom-ratings-graph.rows.0.value', 4)
            ->assertJsonPath('charts.custom-ratings-graph.rows.1.label', 'Overall Evaluation (Sum)')
            ->assertJsonPath('charts.custom-ratings-graph.rows.1.value', 20)
            ->assertJsonPath('charts.custom-ratings-graph.rows.2.label', 'Target Benchmark')
            ->assertJsonPath('charts.custom-ratings-graph.rows.2.value', 3.5);
    }
}
