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

    public function test_accepts_titles_and_labels_up_to_500_characters(): void
    {
        $report = FeedbackAnalyticsReport::factory()->create();
        $longText = str_repeat('a', 500);
        $configuration = $report->configuration;
        $configuration['title'] = $longText;
        $configuration['charts'][0]['title'] = $longText;
        $configuration['charts'][] = [
            'id' => 'custom-chart',
            'kind' => 'custom',
            'title' => $longText,
            'type' => 'column',
            'aggregation' => 'mean',
            'color' => '#24543c',
            'legend' => true,
            'labels' => true,
            'width' => 6,
            'rows' => [[
                'id' => 'row-1',
                'label' => $longText,
                'source' => 'manual',
                'value' => 1,
            ]],
        ];
        $configuration['sections'] = [[
            'id' => 'sec-1',
            'title' => $longText,
            'body' => 'Body',
            'width' => 12,
        ]];

        $this->actingAs(User::factory()->admin()->create())
            ->putJson(route('feedback.analytics.update', [$report->feedback_id, $report->id]), ['configuration' => $configuration])
            ->assertOk()
            ->assertJsonPath('report.configuration.title', $longText)
            ->assertJsonPath('report.configuration.charts.0.title', $longText)
            ->assertJsonPath('report.configuration.charts.1.rows.0.label', $longText)
            ->assertJsonPath('report.configuration.sections.0.title', $longText);

        $report->refresh();
        $this->assertSame($longText, $report->configuration['charts'][0]['title']);
    }

    public function test_rejects_titles_and_labels_exceeding_500_characters_with_422(): void
    {
        $report = FeedbackAnalyticsReport::factory()->create();
        $tooLong = str_repeat('a', 501);
        $configuration = $report->configuration;
        $configuration['title'] = $tooLong;
        $configuration['charts'][0]['title'] = $tooLong;
        $configuration['charts'][] = [
            'id' => 'custom-chart',
            'kind' => 'custom',
            'title' => 'Valid',
            'type' => 'column',
            'aggregation' => 'mean',
            'color' => '#24543c',
            'legend' => true,
            'labels' => true,
            'width' => 6,
            'rows' => [[
                'id' => 'row-1',
                'label' => $tooLong,
                'source' => 'manual',
                'value' => 1,
            ]],
        ];
        $configuration['sections'] = [[
            'id' => 'sec-1',
            'title' => $tooLong,
            'body' => 'Body',
            'width' => 12,
        ]];

        $this->actingAs(User::factory()->admin()->create())
            ->putJson(route('feedback.analytics.update', [$report->feedback_id, $report->id]), ['configuration' => $configuration])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'configuration.title' => 'The configuration.title field must not be greater than 500 characters.',
                'configuration.charts.0.title' => 'The configuration.charts.0.title field must not be greater than 500 characters.',
                'configuration.charts.1.rows.0.label' => 'The configuration.charts.1.rows.0.label field must not be greater than 500 characters.',
                'configuration.sections.0.title' => 'The configuration.sections.0.title field must not be greater than 500 characters.',
            ]);

        $report->refresh();
        $this->assertSame('Overall rating', $report->configuration['charts'][0]['title']);
    }

    public function test_rich_text_html_lists_and_tables_in_analytics_fields_are_saved_and_published(): void
    {
        $form = Feedback::factory()->create(['schema' => ['fields' => [
            ['id' => 'score', 'type' => 'number', 'particular' => '<b>Overall</b> Score', 'weight' => 1],
        ]]]);
        FbSubmission::factory()->count(5)->create(['feedback_id' => $form->id, 'data' => ['score' => 4]]);

        $richTable = str_repeat('<p>Detailed section notes with bullet points and merged table cells.</p>', 80)
            .'<ul><li>Point A</li><li>Point B</li></ul>'
            .'<table class="form-rich-table" width="100%"><thead><tr><th colspan="2">Summary</th></tr></thead><tbody><tr><td>Metric</td><td>4.0</td></tr></tbody></table>';

        $configuration = FeedbackAnalyticsReport::factory()->make()->configuration;
        $configuration['title'] = '<b>2026</b> <u>Annual</u> Evaluation Report';
        $configuration['description'] = '<p style="text-align: center;"><strong>Executive Overview</strong></p><ul><li>Item 1</li><li>Item 2</li></ul>';
        $configuration['footer'] = '<i>Confidential — For internal &amp; partner review</i>';
        $configuration['charts'][0]['question'] = 'score';
        $configuration['charts'][0]['aggregation'] = 'mean';
        $configuration['charts'][0]['title'] = '<b>Overall</b> <i>Score</i>';
        $configuration['charts'][0]['annotation'] = '<p><strong>Note:</strong> Based on 5 verified responses.</p><table class="form-rich-table"><tr><td>Target</td><td>4.0</td></tr></table>';
        $configuration['sections'] = [[
            'id' => 'rich-sec-1',
            'kind' => 'text',
            'title' => '<u>Key Findings</u> &amp; Takeaways',
            'body' => $richTable,
            'width' => 12,
            'height' => 4,
            'position' => 0,
        ]];

        $report = FeedbackAnalyticsReport::factory()->create([
            'feedback_id' => $form->id,
            'configuration' => $configuration,
        ]);

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)
            ->putJson(route('feedback.analytics.update', [$form->id, $report->id]), ['configuration' => $configuration])
            ->assertOk()
            ->assertJsonPath('report.configuration.title', '<b>2026</b> <u>Annual</u> Evaluation Report')
            ->assertJsonPath('report.configuration.sections.0.body', $richTable);

        $this->publish($report, $admin);

        $this->get($this->publicUrl($report))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('report.title', '<b>2026</b> <u>Annual</u> Evaluation Report')
                ->where('report.description', $configuration['description'])
                ->where('report.footer', $configuration['footer'])
                ->where('report.sections.0.title', '<u>Key Findings</u> &amp; Takeaways')
                ->where('report.sections.0.body', $richTable)
                ->where('report.charts.0.title', '<b>Overall</b> <i>Score</i>')
                ->where('report.charts.0.annotation', $configuration['charts'][0]['annotation'])
                ->where('data.charts.rating.rows.0.label', 'Overall Score')
                ->where('data.charts.rating.rows.0.value', 4));
    }

    public function test_returns_questions_by_section_and_calculated_and_grouped_form_data_for_editor(): void
    {
        $form = Feedback::factory()->create(['schema' => ['fields' => [
            ['id' => 'general_q', 'type' => 'number', 'particular' => 'General Rating', 'weight' => 1],
            ['id' => 'sec_speaker', 'type' => 'section', 'particular' => '<b>Part I:</b> Speaker Evaluation'],
            ['id' => 'speaker_score', 'type' => 'number', 'particular' => 'Speaker Rating', 'weight' => 2],
            [
                'id' => 'sec_venue',
                'type' => 'section',
                'particular' => 'Part II: Venue & Logistics',
            ],
            [
                'id' => 'satisfaction',
                'type' => 'radio',
                'particular' => 'Venue Satisfaction',
                'options' => [
                    ['value' => '5', 'label' => 'Very Satisfied', 'score' => 5],
                    ['value' => '3', 'label' => 'Neutral', 'score' => 3],
                    ['value' => '1', 'label' => 'Dissatisfied', 'score' => 1],
                ],
            ],
            [
                'id' => 'topics',
                'type' => 'checkbox',
                'particular' => 'Useful Topics',
                'options' => [
                    ['value' => 'ai', 'label' => 'AI Automation'],
                    ['value' => 'sec', 'label' => 'Cybersecurity'],
                ],
            ],
            [
                'id' => 'recommendations',
                'type' => 'textarea',
                'particular' => 'Recommendations & Observations',
                'weight' => 5,
            ],
        ]]]);

        FbSubmission::factory()->create([
            'feedback_id' => $form->id,
            'data' => [
                'general_q' => 10,
                'speaker_score' => 4,
                'satisfaction' => '5',
                'topics' => ['ai', 'sec'],
                'recommendations' => 'Improve radio communication',
            ],
            'created_at' => '2026-10-10 10:00:00',
        ]);
        FbSubmission::factory()->create([
            'feedback_id' => $form->id,
            'data' => [
                'general_q' => 20,
                'speaker_score' => 6,
                'satisfaction' => '5',
                'topics' => ['ai'],
                'recommendations' => 'Deploy fire units earlier',
            ],
            'created_at' => '2026-10-15 10:00:00',
        ]);
        FbSubmission::factory()->create([
            'feedback_id' => $form->id,
            'data' => [
                'general_q' => 30,
                'speaker_score' => 8,
                'satisfaction' => '3',
                'topics' => ['sec'],
                'recommendations' => 'More signage for evacuation routes',
            ],
            'created_at' => '2026-10-20 10:00:00',
        ]);

        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)
            ->getJson(route('feedback.analytics.publication', $form->id))
            ->assertOk()
            ->assertJsonPath('questions.0.id', 'general_q')
            ->assertJsonPath('questions.0.section_id', null)
            ->assertJsonPath('questions.0.section_title', null)
            ->assertJsonPath('questions.1.id', 'speaker_score')
            ->assertJsonPath('questions.1.section_id', 'sec_speaker')
            ->assertJsonPath('questions.1.section_title', 'Part I: Speaker Evaluation')
            ->assertJsonPath('questions.2.id', 'satisfaction')
            ->assertJsonPath('questions.2.section_id', 'sec_venue')
            ->assertJsonPath('questions.2.section_title', 'Part II: Venue & Logistics')
            ->assertJsonPath('questions.3.id', 'topics')
            ->assertJsonPath('questions.3.section_id', 'sec_venue')
            ->assertJsonPath('questions.3.section_title', 'Part II: Venue & Logistics')
            ->assertJsonPath('questions.4.id', 'recommendations')
            ->assertJsonPath('questions.4.type', 'textarea')
            ->assertJsonPath('questions.4.section_id', 'sec_venue')
            ->assertJsonPath('questions.4.section_title', 'Part II: Venue & Logistics')
            ->assertJsonPath('form_data.submission_count', 3)
            ->assertJsonPath('form_data.questions.speaker_score.calculated.mean', 6)
            ->assertJsonPath('form_data.questions.speaker_score.calculated.sum', 18)
            ->assertJsonPath('form_data.questions.speaker_score.calculated.median', 6)
            ->assertJsonPath('form_data.questions.speaker_score.calculated.min', 4)
            ->assertJsonPath('form_data.questions.speaker_score.calculated.max', 8)
            ->assertJsonPath('form_data.questions.speaker_score.calculated.count', 3)
            ->assertJsonPath('form_data.questions.satisfaction.grouped.0.label', 'Very Satisfied')
            ->assertJsonPath('form_data.questions.satisfaction.grouped.0.count', 2)
            ->assertJsonPath('form_data.questions.satisfaction.grouped.0.percentage', 66.67)
            ->assertJsonPath('form_data.questions.satisfaction.grouped.1.label', 'Neutral')
            ->assertJsonPath('form_data.questions.satisfaction.grouped.1.count', 1)
            ->assertJsonPath('form_data.questions.satisfaction.grouped.1.percentage', 33.33)
            ->assertJsonPath('form_data.questions.topics.grouped.0.label', 'AI Automation')
            ->assertJsonPath('form_data.questions.topics.grouped.0.count', 2)
            ->assertJsonPath('form_data.questions.topics.grouped.0.percentage', 66.67)
            ->assertJsonPath('form_data.questions.topics.grouped.1.label', 'Cybersecurity')
            ->assertJsonPath('form_data.questions.topics.grouped.1.count', 2)
            ->assertJsonPath('form_data.questions.topics.grouped.1.percentage', 66.67)
            ->assertJsonPath('form_data.questions.recommendations.answered', 3)
            ->assertJsonPath('form_data.questions.recommendations.responses.0.value', 'Improve radio communication')
            ->assertJsonPath('form_data.questions.recommendations.responses.1.value', 'Deploy fire units earlier')
            ->assertJsonPath('form_data.questions.recommendations.responses.2.value', 'More signage for evacuation routes');

        $this->assertEqualsWithDelta(
            4.33,
            $response->json('form_data.questions.satisfaction.calculated.mean'),
            0.01
        );

        // Verify preview endpoint filters form_data by period_start and period_end
        $configuration = FeedbackAnalyticsReport::factory()->make()->configuration;
        $configuration['period_start'] = '2026-10-12';
        $configuration['period_end'] = '2026-10-25';
        $configuration['charts'][0]['question'] = 'speaker_score';
        $configuration['charts'][0]['aggregation'] = 'mean';

        $this->actingAs($admin)
            ->postJson(route('feedback.analytics.preview', $form->id), ['configuration' => $configuration])
            ->assertOk()
            ->assertJsonPath('form_data.submission_count', 2)
            ->assertJsonPath('form_data.questions.speaker_score.calculated.mean', 7)
            ->assertJsonPath('form_data.questions.speaker_score.calculated.sum', 14)
            ->assertJsonPath('form_data.questions.satisfaction.grouped.0.count', 1)
            ->assertJsonPath('form_data.questions.satisfaction.grouped.0.percentage', 50)
            ->assertJsonCount(2, 'form_data.questions.recommendations.responses')
            ->assertJsonPath('form_data.questions.recommendations.responses.0.value', 'Deploy fire units earlier')
            ->assertJsonPath('form_data.questions.recommendations.responses.1.value', 'More signage for evacuation routes');
    }
}
