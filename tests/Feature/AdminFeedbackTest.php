<?php

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\AuditLog;
use App\Models\Designation;
use App\Models\FbEvent;
use App\Models\FbFunction;
use App\Models\FbParticipant;
use App\Models\FbSubmission;
use App\Models\Feedback;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminFeedbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_non_admin_cannot_access_feedback_setup(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $response = $this->actingAs($user)->get(route('admin.feedback.setup'));

        $response->assertForbidden();
    }

    public function test_admin_can_view_feedback_setup_page(): void
    {
        $admin = User::factory()->admin()->create();
        $event = FbEvent::factory()->create(['name' => 'Cybersecurity Summit 2026']);
        Agency::factory()->create(['name' => 'Department of Science and Technology']);
        Designation::factory()->create(['name' => 'Senior Developer']);

        $response = $this->actingAs($admin)->get(route('admin.feedback.setup'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Feedback/Setup')
            ->has('events')
            ->has('functions')
            ->has('agencies')
            ->has('designations')
            ->has('feedbackForms')
        );
    }

    public function test_admin_can_view_feedback_submissions_page(): void
    {
        $admin = User::factory()->admin()->create();
        $event = FbEvent::factory()->create();
        $feedback = Feedback::factory()->create(['event_id' => $event->id]);
        $participant = FbParticipant::factory()->create(['name' => 'Juan Dela Cruz']);
        FbSubmission::factory()->create([
            'feedback_id' => $feedback->id,
            'participant_id' => $participant->id,
            'data' => ['satisfaction' => 5],
        ]);

        $response = $this->actingAs($admin)->get(route('admin.feedback.submissions'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Feedback/Submissions')
            ->has('submissions.data')
            ->has('filterEvents')
        );
    }

    public function test_admin_can_create_update_and_delete_event(): void
    {
        $admin = User::factory()->admin()->create();

        // Create
        $response = $this->actingAs($admin)->post(route('admin.feedback.events.store'), [
            'name' => 'AI Governance Workshop',
            'details' => 'Annual government AI policies seminar.',
        ]);

        $response->assertSessionHas('success');
        $this->assertDatabaseHas('fb_events', [
            'name' => 'AI Governance Workshop',
            'created_by' => $admin->id,
        ]);

        $event = FbEvent::where('name', 'AI Governance Workshop')->firstOrFail();
        $this->assertNotEmpty($event->api_key);

        $auditLog = AuditLog::where('action', 'created_fb_event')->latest()->first();
        $this->assertNotNull($auditLog);
        $this->assertArrayNotHasKey('api_key', $auditLog->details);
        $this->assertEquals('AI Governance Workshop', $auditLog->details['name']);

        // Update
        $updateResponse = $this->actingAs($admin)->put(route('admin.feedback.events.update', $event), [
            'name' => 'AI Governance Workshop 2026',
            'details' => 'Updated details.',
        ]);

        $updateResponse->assertSessionHas('success');
        $this->assertDatabaseHas('fb_events', [
            'id' => $event->id,
            'name' => 'AI Governance Workshop 2026',
        ]);

        // Soft Delete
        $deleteResponse = $this->actingAs($admin)->delete(route('admin.feedback.events.destroy', $event));
        $deleteResponse->assertSessionHas('success');
        $this->assertSoftDeleted('fb_events', ['id' => $event->id]);
    }

    public function test_admin_can_assign_multiple_functions_to_an_event_and_sync(): void
    {
        $admin = User::factory()->admin()->create();
        $funcA = FbFunction::factory()->create(['function' => 'Facilitator']);
        $funcB = FbFunction::factory()->create(['function' => 'Observer']);
        $funcC = FbFunction::factory()->create(['function' => 'Technical Lead']);

        // Create event with assigned functions A and B
        $response = $this->actingAs($admin)->post(route('admin.feedback.events.store'), [
            'name' => 'Disaster Preparedness Drill',
            'details' => 'Citywide simulation.',
            'function_ids' => [$funcA->id, $funcB->id],
        ]);

        $response->assertSessionHas('success');
        $event = FbEvent::where('name', 'Disaster Preparedness Drill')->firstOrFail();

        $this->assertDatabaseHas('fb_event_function', [
            'event_id' => $event->id,
            'function_id' => $funcA->id,
        ]);
        $this->assertDatabaseHas('fb_event_function', [
            'event_id' => $event->id,
            'function_id' => $funcB->id,
        ]);
        $this->assertDatabaseMissing('fb_event_function', [
            'event_id' => $event->id,
            'function_id' => $funcC->id,
        ]);

        // Update event: synchronize to functions B and C
        $updateResponse = $this->actingAs($admin)->put(route('admin.feedback.events.update', $event), [
            'name' => 'Disaster Preparedness Drill',
            'details' => 'Citywide simulation.',
            'function_ids' => [$funcB->id, $funcC->id],
        ]);

        $updateResponse->assertSessionHas('success');
        $this->assertDatabaseMissing('fb_event_function', [
            'event_id' => $event->id,
            'function_id' => $funcA->id,
        ]);
        $this->assertDatabaseHas('fb_event_function', [
            'event_id' => $event->id,
            'function_id' => $funcB->id,
        ]);
        $this->assertDatabaseHas('fb_event_function', [
            'event_id' => $event->id,
            'function_id' => $funcC->id,
        ]);

        // Ensure function A was not deleted from fb_functions table
        $this->assertDatabaseHas('fb_functions', ['id' => $funcA->id]);
    }

    public function test_admin_can_assign_one_function_to_multiple_events(): void
    {
        $admin = User::factory()->admin()->create();
        $event1 = FbEvent::factory()->create(['name' => 'Event One']);
        $event2 = FbEvent::factory()->create(['name' => 'Event Two']);

        // Create function assigned to both event1 and event2
        $response = $this->actingAs($admin)->post(route('admin.feedback.functions.store'), [
            'function' => 'Keynote Speaker',
            'details' => 'Invited domain expert',
            'event_ids' => [$event1->id, $event2->id],
        ]);

        $response->assertSessionHas('success');
        $function = FbFunction::where('function', 'Keynote Speaker')->firstOrFail();

        $this->assertDatabaseHas('fb_event_function', [
            'event_id' => $event1->id,
            'function_id' => $function->id,
        ]);
        $this->assertDatabaseHas('fb_event_function', [
            'event_id' => $event2->id,
            'function_id' => $function->id,
        ]);

        $this->assertCount(2, $function->events);
    }

    public function test_deleting_event_does_not_delete_shared_functions(): void
    {
        $admin = User::factory()->admin()->create();
        $event = FbEvent::factory()->create(['name' => 'Event to be deleted']);
        $function = FbFunction::factory()->create(['function' => 'Shared Resource']);
        $event->functions()->attach($function->id);

        $this->assertDatabaseHas('fb_event_function', [
            'event_id' => $event->id,
            'function_id' => $function->id,
        ]);

        $response = $this->actingAs($admin)->delete(route('admin.feedback.events.destroy', $event));
        $response->assertSessionHas('success');

        $this->assertSoftDeleted('fb_events', ['id' => $event->id]);
        $this->assertDatabaseHas('fb_functions', ['id' => $function->id]);
    }

    public function test_admin_can_regenerate_event_api_key(): void
    {
        $admin = User::factory()->admin()->create();
        $event = FbEvent::factory()->create(['created_by' => $admin->id]);
        $oldKey = $event->api_key;

        $response = $this->actingAs($admin)->post(route('admin.feedback.events.regenerate-key', $event));

        $response->assertSessionHas('success');
        $event->refresh();
        $this->assertNotEquals($oldKey, $event->api_key);
        $this->assertStringStartsWith('fb_', $event->api_key);
    }

    public function test_admin_can_create_and_delete_functions(): void
    {
        $admin = User::factory()->admin()->create();
        $event = FbEvent::factory()->create();

        // Create normal function
        $response = $this->actingAs($admin)->post(route('admin.feedback.functions.store'), [
            'event_ids' => [$event->id],
            'function' => 'Speaker / Resource Person',
            'details' => null,
        ]);
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('fb_functions', [
            'function' => 'Speaker / Resource Person',
        ]);
        $this->assertDatabaseHas('fb_event_function', [
            'event_id' => $event->id,
        ]);

        // Create 'Others' function with custom details
        $othersResponse = $this->actingAs($admin)->post(route('admin.feedback.functions.store'), [
            'event_id' => $event->id,
            'function' => 'Others',
            'details' => 'Volunteer Marshall',
        ]);
        $othersResponse->assertSessionHas('success');
        $this->assertDatabaseHas('fb_functions', [
            'function' => 'Others',
            'details' => 'Volunteer Marshall',
        ]);

        $function = FbFunction::where('function', 'Speaker / Resource Person')->firstOrFail();
        $deleteResponse = $this->actingAs($admin)->delete(route('admin.feedback.functions.destroy', $function));
        $deleteResponse->assertSessionHas('success');
        $this->assertDatabaseMissing('fb_functions', ['id' => $function->id]);
        $this->assertDatabaseMissing('fb_event_function', ['function_id' => $function->id]);
    }

    public function test_admin_can_manage_agencies_and_designations(): void
    {
        $admin = User::factory()->admin()->create();

        // Agency Store & Delete
        $this->actingAs($admin)->post(route('admin.feedback.agencies.store'), [
            'name' => 'Department of Budget and Management',
        ])->assertSessionHas('success');

        $agency = Agency::where('name', 'Department of Budget and Management')->firstOrFail();

        $this->actingAs($admin)->delete(route('admin.feedback.agencies.destroy', $agency))
            ->assertSessionHas('success');
        $this->assertSoftDeleted('agencies', ['id' => $agency->id]);

        // Storing deleted agency again restores it
        $this->actingAs($admin)->post(route('admin.feedback.agencies.store'), [
            'name' => 'Department of Budget and Management',
        ])->assertSessionHas('success');
        $this->assertDatabaseHas('agencies', ['id' => $agency->id, 'deleted_at' => null]);

        // Designation Store & Delete
        $this->actingAs($admin)->post(route('admin.feedback.designations.store'), [
            'name' => 'Project Manager',
        ])->assertSessionHas('success');

        $designation = Designation::where('name', 'Project Manager')->firstOrFail();

        $this->actingAs($admin)->delete(route('admin.feedback.designations.destroy', $designation))
            ->assertSessionHas('success');
        $this->assertSoftDeleted('designations', ['id' => $designation->id]);

        // Storing deleted designation again restores it
        $this->actingAs($admin)->post(route('admin.feedback.designations.store'), [
            'name' => 'Project Manager',
        ])->assertSessionHas('success');
        $this->assertDatabaseHas('designations', ['id' => $designation->id, 'deleted_at' => null]);
    }

    public function test_admin_can_save_feedback_form_with_weighted_fields_and_emoji_options(): void
    {
        $admin = User::factory()->admin()->create();
        $event = FbEvent::factory()->create();

        $schema = [
            'fields' => [
                [
                    'id' => 'overall_rating',
                    'particular' => 'How satisfied are you with the event?',
                    'type' => 'radio',
                    'weight' => 1,
                    'options' => [
                        ['value' => '5', 'label' => '⭐⭐⭐⭐⭐ Outstanding'],
                        ['value' => '4', 'label' => '⭐⭐⭐⭐ Very Satisfactory'],
                        ['value' => '3', 'label' => '⭐⭐⭐ Satisfactory'],
                        ['value' => '2', 'label' => '⭐⭐ Fair'],
                        ['value' => '1', 'label' => '⭐ Poor'],
                    ],
                ],
                [
                    'id' => 'topics_interested',
                    'particular' => 'Topics for future sessions',
                    'type' => 'checkbox',
                    'weight' => 2,
                    'options' => [
                        ['value' => 'cloud_security', 'label' => '🔒 Cloud Security & DevSecOps'],
                        ['value' => 'generative_ai', 'label' => '🤖 Applied Generative AI'],
                        ['value' => 'quantum_computing', 'label' => '⚛️ Quantum Computing'],
                    ],
                ],
                [
                    'id' => 'recommendations',
                    'particular' => 'Any suggestions for improvement?',
                    'type' => 'text',
                    'weight' => 3,
                    'options' => [],
                ],
            ],
        ];

        $response = $this->actingAs($admin)->post(route('admin.feedback.forms.save'), [
            'event_id' => $event->id,
            'schema' => $schema,
        ]);

        $response->assertSessionHas('success');
        $this->assertDatabaseHas('feedback', [
            'event_id' => $event->id,
        ]);

        $savedFeedback = Feedback::where('event_id', $event->id)->firstOrFail();
        $this->assertCount(3, $savedFeedback->schema['fields']);
        $this->assertEquals('⭐⭐⭐⭐⭐ Outstanding', $savedFeedback->schema['fields'][0]['options'][0]['label']);
    }

    public function test_admin_can_save_schema_dropdown_configured_with_fb_functions_source(): void
    {
        $admin = User::factory()->admin()->create();
        $event = FbEvent::factory()->create();
        $func1 = FbFunction::factory()->create(['function' => 'Lead Evaluator']);
        $func2 = FbFunction::factory()->create(['function' => 'Field Observer']);
        $event->functions()->attach([$func1->id, $func2->id]);

        $schema = [
            'fields' => [
                [
                    'id' => 'assigned_role',
                    'particular' => 'Participant Role / Function',
                    'type' => 'select',
                    'weight' => 1,
                    'option_source' => 'fb_functions',
                    'function_ids' => [$func1->id, $func2->id],
                ],
            ],
        ];

        $response = $this->actingAs($admin)->post(route('admin.feedback.forms.save'), [
            'event_id' => $event->id,
            'schema' => $schema,
        ]);

        $response->assertSessionHas('success');
        $savedFeedback = Feedback::where('event_id', $event->id)->firstOrFail();
        $field = $savedFeedback->schema['fields'][0];

        $this->assertEquals('select', $field['type']);
        $this->assertEquals('fb_functions', $field['option_source']);
        $this->assertEquals([$func1->id, $func2->id], $field['function_ids']);
    }

    public function test_feedback_form_rejects_invalid_schema(): void
    {
        $admin = User::factory()->admin()->create();
        $event = FbEvent::factory()->create();

        // Invalid: empty options on static radio type
        $response = $this->actingAs($admin)->post(route('admin.feedback.forms.save'), [
            'event_id' => $event->id,
            'schema' => [
                'fields' => [
                    [
                        'id' => 'broken_field',
                        'particular' => 'Broken Field',
                        'type' => 'radio',
                        'weight' => 1,
                        'options' => [],
                    ],
                ],
            ],
        ]);

        $response->assertSessionHasErrors();
    }
}
