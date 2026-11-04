<?php

namespace Tests\Feature;

use App\Models\FbEvent;
use App\Models\FbSubmission;
use App\Models\Feedback;
use App\Models\FeedbackEmbed;
use App\Models\User;
use App\Services\FeedbackAnalyticsService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class FeedbackPhotoUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Storage::fake('local');
    }

    /**
     * Build a real WebP image upload in memory for raster validation testing.
     */
    private function makeWebpUpload(string $filename = 'evidence.webp'): UploadedFile
    {
        $image = imagecreatetruecolor(64, 48);
        $bg = imagecolorallocate($image, 36, 84, 60);
        imagefill($image, 0, 0, $bg);

        ob_start();
        imagewebp($image, null, 85);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return UploadedFile::fake()->createWithContent($filename, $bytes);
    }

    /**
     * Create a published feedback form containing choice, text, required photo, and optional photo questions.
     *
     * @return array{0: FbEvent, 1: Feedback}
     */
    private function createFeedbackWithPhotoQuestions(bool $photoRequired = true): array
    {
        $event = FbEvent::factory()->create(['name' => 'Field Command Drill 2026']);

        $feedback = Feedback::factory()->create([
            'event_id' => $event->id,
            'status' => Feedback::STATUS_PUBLISHED,
            'schema' => [
                'title' => 'Drill Observation Form',
                'description' => 'Submit ratings and field photo documentation.',
                'fields' => [
                    [
                        'id' => 'overall_rating',
                        'particular' => 'Overall Rating',
                        'type' => 'radio',
                        'weight' => 1,
                        'required' => true,
                        'allow_other' => true,
                        'options' => [
                            ['value' => 'excellent', 'label' => 'Excellent'],
                            ['value' => 'other', 'label' => 'Other', 'is_other' => true],
                        ],
                    ],
                    [
                        'id' => 'focus_areas',
                        'particular' => 'Observed Focus Areas',
                        'type' => 'checkbox',
                        'weight' => 2,
                        'required' => false,
                        'options' => [
                            ['value' => 'comms', 'label' => 'Communications'],
                            ['value' => 'logistics', 'label' => 'Logistics'],
                        ],
                    ],
                    [
                        'id' => 'site_photo',
                        'particular' => 'Command Post Photo',
                        'type' => 'photo',
                        'weight' => 3,
                        'required' => $photoRequired,
                    ],
                    [
                        'id' => 'remarks',
                        'particular' => 'Additional Remarks',
                        'type' => 'text',
                        'weight' => 4,
                        'required' => false,
                    ],
                ],
            ],
        ]);

        return [$event, $feedback];
    }

    public function test_admin_can_save_feedback_schema_with_ordered_photo_questions(): void
    {
        $admin = User::factory()->admin()->create();
        $event = FbEvent::factory()->create();

        $response = $this->actingAs($admin)->post(route('admin.feedback.forms.save'), [
            'event_id' => $event->id,
            'status' => 'published',
            'schema' => [
                'title' => 'Inspection Form',
                'description' => 'With photo evidence.',
                'fields' => [
                    [
                        'id' => 'inspector_notes',
                        'particular' => 'Inspector Notes',
                        'type' => 'text',
                        'weight' => 1,
                        'required' => true,
                    ],
                    [
                        'id' => 'primary_photo',
                        'particular' => 'Primary Evidence Photo',
                        'type' => 'photo',
                        'weight' => 2,
                        'required' => true,
                    ],
                    [
                        'id' => 'secondary_photo',
                        'particular' => 'Optional Follow-up Photo',
                        'type' => 'photo',
                        'weight' => 3,
                        'required' => false,
                    ],
                ],
            ],
        ]);

        $response->assertSessionHasNoErrors();

        $saved = Feedback::where('event_id', $event->id)->firstOrFail();
        $fields = $saved->schema['fields'];

        $this->assertCount(3, $fields);
        $this->assertSame('primary_photo', $fields[1]['id']);
        $this->assertSame('photo', $fields[1]['type']);
        $this->assertTrue($fields[1]['required']);
        $this->assertSame(2, $fields[1]['weight']);

        $this->assertSame('secondary_photo', $fields[2]['id']);
        $this->assertSame('photo', $fields[2]['type']);
        $this->assertFalse($fields[2]['required']);
        $this->assertSame(3, $fields[2]['weight']);
    }

    public function test_admin_save_rejects_unsupported_question_types_and_malformed_photo_schemas(): void
    {
        $admin = User::factory()->admin()->create();
        $event = FbEvent::factory()->create();

        // 1. Unsupported type
        $unsupportedResponse = $this->actingAs($admin)->post(route('admin.feedback.forms.save'), [
            'event_id' => $event->id,
            'schema' => [
                'fields' => [
                    [
                        'id' => 'video_upload',
                        'particular' => 'Upload Video',
                        'type' => 'video',
                    ],
                ],
            ],
        ]);
        $unsupportedResponse->assertSessionHasErrors(['schema.fields.0.type']);

        // 2. Malformed photo question (options, multiple, non-static source, empty stripped prompt)
        $malformedResponse = $this->actingAs($admin)->post(route('admin.feedback.forms.save'), [
            'event_id' => $event->id,
            'schema' => [
                'fields' => [
                    [
                        'id' => 'bad_photo',
                        'particular' => '   <b>   </b>   ',
                        'type' => 'photo',
                        'required' => 'not_a_boolean',
                        'multiple' => true,
                        'option_source' => 'fb_functions',
                        'allow_other' => true,
                        'min' => 1,
                        'options' => [
                            ['value' => 'opt_1', 'label' => 'Option 1'],
                        ],
                    ],
                ],
            ],
        ]);

        $malformedResponse->assertSessionHasErrors([
            'schema.fields.0.particular',
            'schema.fields.0.required',
            'schema.fields.0.multiple',
            'schema.fields.0.option_source',
            'schema.fields.0.allow_other',
            'schema.fields.0.min',
            'schema.fields.0.options',
        ]);
    }

    public function test_live_and_embedded_forms_accept_jpeg_png_and_webp_photos_and_preserve_all_answers(): void
    {
        [$event, $feedback] = $this->createFeedbackWithPhotoQuestions(true);

        // 1. Live form submission with JPEG + checkbox array + _other text + participant metadata
        $jpegUpload = UploadedFile::fake()->image('Original Client Name!!.JPG', 320, 240);

        $liveResponse = $this->post(route('forms.live.submit', $event->api_key), [
            'name' => 'Maria Santos',
            'agency' => 'NDRRMC',
            'answers' => [
                'overall_rating' => 'other',
                'overall_rating_other' => 'Very well coordinated',
                'focus_areas' => ['comms', 'logistics'],
                'site_photo' => $jpegUpload,
                'remarks' => 'Clear signage on site.',
            ],
        ], ['Accept' => 'application/json']);

        $liveResponse->assertCreated();
        $liveSubmission = FbSubmission::latest('id')->firstOrFail();

        $photoEntry = $liveSubmission->data['site_photo'];
        $this->assertIsArray($photoEntry);
        $this->assertSame('photo', $photoEntry['type']);
        $this->assertSame('local', $photoEntry['disk']);
        $this->assertSame('image/jpeg', $photoEntry['mime_type']);
        $this->assertStringNotContainsString('Original Client Name', $photoEntry['path']);
        $this->assertStringStartsWith(
            "feedback-submissions/{$feedback->id}/{$liveSubmission->id}/site_photo/",
            $photoEntry['path']
        );
        Storage::disk('local')->assertExists($photoEntry['path']);

        // Ensure no internal disk/path leaked in public JSON resource
        $liveJson = $liveResponse->json();
        $resourcePayload = isset($liveJson['data']['site_photo']) ? $liveJson['data'] : ($liveJson['data']['data'] ?? []);
        $this->assertArrayNotHasKey('path', $resourcePayload['site_photo']);
        $this->assertArrayNotHasKey('disk', $resourcePayload['site_photo']);
        $this->assertTrue($resourcePayload['site_photo']['uploaded']);

        // Ensure non-file answers and participant fields were preserved
        $this->assertSame('other', $liveSubmission->data['overall_rating']);
        $this->assertSame('Very well coordinated', $liveSubmission->data['overall_rating_other']);
        $this->assertSame(['comms', 'logistics'], $liveSubmission->data['focus_areas']);
        $this->assertSame('Clear signage on site.', $liveSubmission->data['remarks']);
        $this->assertSame('Maria Santos', $liveSubmission->participant->name);

        // 2. Embedded form submission with PNG
        /** @var FeedbackEmbed $embed */
        $embed = $event->embed;
        $embed->update([
            'client_id' => 'emb_photo_client',
            'client_secret' => Hash::make('secret-abc-123'),
            'is_active' => true,
        ]);
        $sessionOne = $embed->createSession('respondent_png');

        $pngUpload = UploadedFile::fake()->image('command-map.png', 200, 150);
        $embedPngResponse = $this->post(route('feedback.embed.submit', $embed->public_id), [
            'token' => $sessionOne['session_token'],
            'answers' => [
                'overall_rating' => 'excellent',
                'site_photo' => $pngUpload,
            ],
        ], ['Accept' => 'application/json']);

        $embedPngResponse->assertCreated();
        $pngSubmission = FbSubmission::findOrFail($embedPngResponse->json('id') ?? $embedPngResponse->json('data.id'));
        $this->assertSame('image/png', $pngSubmission->data['site_photo']['mime_type']);
        Storage::disk('local')->assertExists($pngSubmission->data['site_photo']['path']);

        // 3. Embedded form submission with WebP
        $sessionTwo = $embed->createSession('respondent_webp');
        $webpUpload = $this->makeWebpUpload('triage-tent.webp');

        $embedWebpResponse = $this->post(route('feedback.embed.submit', $embed->public_id), [
            'token' => $sessionTwo['session_token'],
            'answers' => [
                'overall_rating' => 'excellent',
                'site_photo' => $webpUpload,
            ],
        ], ['Accept' => 'application/json']);

        $embedWebpResponse->assertCreated();
        $webpSubmission = FbSubmission::findOrFail($embedWebpResponse->json('id') ?? $embedWebpResponse->json('data.id'));
        $this->assertSame('image/webp', $webpSubmission->data['site_photo']['mime_type']);
        Storage::disk('local')->assertExists($webpSubmission->data['site_photo']['path']);

        // 4. Analytics service ignores photo answers rather than treating them as text/choice
        $analytics = app(FeedbackAnalyticsService::class);
        $chartQuestions = $analytics->questions($feedback);
        $sectionQuestions = $analytics->questionsWithSections($feedback);

        $this->assertArrayNotHasKey('site_photo', $chartQuestions);
        $this->assertArrayHasKey('overall_rating', $chartQuestions);

        $this->assertArrayNotHasKey('site_photo', $sectionQuestions);
        $this->assertArrayHasKey('overall_rating', $sectionQuestions);
        $this->assertArrayHasKey('remarks', $sectionQuestions);
    }

    public function test_optional_photo_question_can_be_omitted(): void
    {
        [$event] = $this->createFeedbackWithPhotoQuestions(false);

        $response = $this->postJson(route('forms.live.submit', $event->api_key), [
            'answers' => [
                'overall_rating' => 'excellent',
                'remarks' => 'Submitted without photo.',
            ],
        ]);

        $response->assertCreated();
        $submission = FbSubmission::latest('id')->firstOrFail();
        $this->assertNull($submission->data['site_photo']);
        $this->assertSame([], Storage::disk('local')->allFiles('feedback-submissions'));
    }

    public function test_required_photo_missing_or_empty_is_rejected_and_preserves_embed_session(): void
    {
        [$event] = $this->createFeedbackWithPhotoQuestions(true);

        // Live form missing required photo
        $liveMissing = $this->postJson(route('forms.live.submit', $event->api_key), [
            'answers' => [
                'overall_rating' => 'excellent',
                'site_photo' => '',
            ],
        ]);

        $liveMissing->assertUnprocessable()
            ->assertJsonValidationErrors(['answers.site_photo']);
        $this->assertStringContainsString(
            'Command Post Photo',
            $liveMissing->json('errors')['answers.site_photo'][0]
        );

        // Embed form missing required photo does NOT consume single-use session token
        /** @var FeedbackEmbed $embed */
        $embed = $event->embed;
        $embed->update(['is_active' => true]);
        $session = $embed->createSession('retryable_respondent');

        $embedMissing = $this->post(route('feedback.embed.submit', $embed->public_id), [
            'token' => $session['session_token'],
            'answers' => [
                'overall_rating' => 'excellent',
            ],
        ], ['Accept' => 'application/json']);

        $embedMissing->assertUnprocessable()
            ->assertJsonValidationErrors(['answers.site_photo']);

        // Retry with the SAME single-use token and a valid photo now succeeds
        $embedRetry = $this->post(route('feedback.embed.submit', $embed->public_id), [
            'token' => $session['session_token'],
            'answers' => [
                'overall_rating' => 'excellent',
                'site_photo' => UploadedFile::fake()->image('valid.jpg', 100, 100),
            ],
        ], ['Accept' => 'application/json']);

        $embedRetry->assertCreated();
    }

    public function test_svg_non_images_and_files_exceeding_5mb_are_rejected_without_storing_files(): void
    {
        [$event] = $this->createFeedbackWithPhotoQuestions(true);

        // 1. SVG file rejected
        $svgFile = UploadedFile::fake()->createWithContent(
            'vector.svg',
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'
        );

        $svgResponse = $this->post(route('forms.live.submit', $event->api_key), [
            'answers' => [
                'overall_rating' => 'excellent',
                'site_photo' => $svgFile,
            ],
        ], ['Accept' => 'application/json']);

        $svgResponse->assertUnprocessable()
            ->assertJsonValidationErrors(['answers.site_photo']);

        // 2. Non-image disguised with .jpg extension rejected
        $disguisedText = UploadedFile::fake()->createWithContent('fake.jpg', 'not an actual image binary');
        $disguisedResponse = $this->post(route('forms.live.submit', $event->api_key), [
            'answers' => [
                'overall_rating' => 'excellent',
                'site_photo' => $disguisedText,
            ],
        ], ['Accept' => 'application/json']);

        $disguisedResponse->assertUnprocessable()
            ->assertJsonValidationErrors(['answers.site_photo']);

        // 3. Oversized raster file (> 5120 KB) rejected
        $oversizedJpg = UploadedFile::fake()->image('huge.jpg', 100, 100)->size(5121);
        $oversizedResponse = $this->post(route('forms.live.submit', $event->api_key), [
            'answers' => [
                'overall_rating' => 'excellent',
                'site_photo' => $oversizedJpg,
            ],
        ], ['Accept' => 'application/json']);

        $oversizedResponse->assertUnprocessable()
            ->assertJsonValidationErrors(['answers.site_photo']);

        $this->assertDatabaseCount('fb_submissions', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('feedback-submissions'));
    }

    public function test_rejects_files_for_unknown_or_non_photo_fields_multiple_files_and_base64_strings(): void
    {
        [$event] = $this->createFeedbackWithPhotoQuestions(false);
        $validPhoto = UploadedFile::fake()->image('valid.png', 80, 80);

        // 1. Unknown question ID with uploaded file
        $unknownFieldResponse = $this->post(route('forms.live.submit', $event->api_key), [
            'answers' => [
                'overall_rating' => 'excellent',
                'unknown_photo_id' => $validPhoto,
            ],
        ], ['Accept' => 'application/json']);

        $unknownFieldResponse->assertUnprocessable()
            ->assertJsonValidationErrors(['answers.unknown_photo_id']);

        // 2. Non-photo text field given an uploaded file
        $nonPhotoFieldResponse = $this->post(route('forms.live.submit', $event->api_key), [
            'answers' => [
                'overall_rating' => 'excellent',
                'remarks' => $validPhoto,
            ],
        ], ['Accept' => 'application/json']);

        $nonPhotoFieldResponse->assertUnprocessable()
            ->assertJsonValidationErrors(['answers.remarks']);

        // 3. Unexpected top-level file key outside answers
        $unexpectedTopLevelFileResponse = $this->post(route('forms.live.submit', $event->api_key), [
            'unexpected_upload' => $validPhoto,
            'answers' => [
                'overall_rating' => 'excellent',
            ],
        ], ['Accept' => 'application/json']);

        $unexpectedTopLevelFileResponse->assertUnprocessable()
            ->assertJsonValidationErrors(['unexpected_upload']);

        // 4. Multiple files array for a single photo question
        $multiFileResponse = $this->post(route('forms.live.submit', $event->api_key), [
            'answers' => [
                'overall_rating' => 'excellent',
                'site_photo' => [
                    UploadedFile::fake()->image('one.jpg', 50, 50),
                    UploadedFile::fake()->image('two.jpg', 50, 50),
                ],
            ],
        ], ['Accept' => 'application/json']);

        $multiFileResponse->assertUnprocessable()
            ->assertJsonValidationErrors(['answers.site_photo']);

        // 5. Base64 / data URL string submitted for a photo question
        $base64Response = $this->postJson(route('forms.live.submit', $event->api_key), [
            'answers' => [
                'overall_rating' => 'excellent',
                'site_photo' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            ],
        ]);

        $base64Response->assertUnprocessable()
            ->assertJsonValidationErrors(['answers.site_photo']);

        $this->assertDatabaseCount('fb_submissions', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('feedback-submissions'));
    }

    public function test_cleanup_removes_newly_written_photo_if_persistence_fails_without_touching_existing_submissions(): void
    {
        [$event] = $this->createFeedbackWithPhotoQuestions(true);

        // First create a valid submission whose stored photo MUST survive
        $firstResponse = $this->post(route('forms.live.submit', $event->api_key), [
            'answers' => [
                'overall_rating' => 'excellent',
                'site_photo' => UploadedFile::fake()->image('keep-me.jpg', 90, 90),
            ],
        ], ['Accept' => 'application/json']);

        $firstResponse->assertCreated();
        $existingSubmission = FbSubmission::firstOrFail();
        $existingPath = $existingSubmission->data['site_photo']['path'];
        Storage::disk('local')->assertExists($existingPath);

        // Simulate failure during final FbSubmission->update() AFTER the new photo has been written to disk
        Event::listen('eloquent.updating: '.FbSubmission::class, function (): void {
            throw new RuntimeException('Simulated database persistence failure after file upload.');
        });

        $failedResponse = $this->post(route('forms.live.submit', $event->api_key), [
            'answers' => [
                'overall_rating' => 'excellent',
                'site_photo' => UploadedFile::fake()->image('should-be-cleaned-up.png', 90, 90),
            ],
        ], ['Accept' => 'application/json']);

        $failedResponse->assertStatus(500);

        // Only the original submission and its single photo file remain
        $this->assertDatabaseCount('fb_submissions', 1);
        Storage::disk('local')->assertExists($existingPath);
        $this->assertSame([$existingPath], Storage::disk('local')->allFiles('feedback-submissions'));
    }

    public function test_authorized_admin_can_view_submission_photo_and_compiled_photo_summary(): void
    {
        $admin = User::factory()->admin()->create();
        [$event] = $this->createFeedbackWithPhotoQuestions(true);

        $submitResponse = $this->post(route('forms.live.submit', $event->api_key), [
            'answers' => [
                'overall_rating' => 'excellent',
                'site_photo' => UploadedFile::fake()->image('command-post.png', 160, 120),
            ],
        ], ['Accept' => 'application/json']);

        $submitResponse->assertCreated();
        $submission = FbSubmission::firstOrFail();
        $photoMeta = $submission->data['site_photo'];

        $photoResponse = $this->actingAs($admin)->get(route('admin.feedback.submissions.photo', [
            'submission' => $submission->id,
            'field' => 'site_photo',
            'filename' => $photoMeta['filename'],
        ]));

        $photoResponse->assertOk();
        $this->assertSame('image/png', $photoResponse->headers->get('Content-Type'));
        $this->assertSame('nosniff', $photoResponse->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('inline;', (string) $photoResponse->headers->get('Content-Disposition'));

        // Compiled tab exposes photo question with is_photo = true and answered_count = 1
        $compiledResponse = $this->actingAs($admin)->get(route('admin.feedback.submissions', [
            'event_id' => $event->id,
            'tab' => 'compiled',
        ]));

        $compiledResponse->assertOk();
        $compiledResponse->assertInertia(fn ($page) => $page
            ->component('Admin/Feedback/Submissions')
            ->where('compiledResponses.2.id', 'site_photo')
            ->where('compiledResponses.2.is_photo', true)
            ->where('compiledResponses.2.is_choice', false)
            ->where('compiledResponses.2.answered_count', 1)
        );
    }

    public function test_unauthorized_users_and_cross_submission_photo_requests_are_denied(): void
    {
        $admin = User::factory()->admin()->create();
        $regularUser = User::factory()->create(['is_admin' => false]);
        [$event] = $this->createFeedbackWithPhotoQuestions(true);

        $this->post(route('forms.live.submit', $event->api_key), [
            'answers' => [
                'overall_rating' => 'excellent',
                'site_photo' => UploadedFile::fake()->image('sub1.jpg', 80, 80),
            ],
        ], ['Accept' => 'application/json'])->assertCreated();

        $this->post(route('forms.live.submit', $event->api_key), [
            'answers' => [
                'overall_rating' => 'excellent',
                'site_photo' => UploadedFile::fake()->image('sub2.jpg', 80, 80),
            ],
        ], ['Accept' => 'application/json'])->assertCreated();

        [$submissionOne, $submissionTwo] = FbSubmission::orderBy('id')->get();
        $subOneFilename = $submissionOne->data['site_photo']['filename'];
        $subTwoFilename = $submissionTwo->data['site_photo']['filename'];

        $photoRoute = route('admin.feedback.submissions.photo', [
            'submission' => $submissionOne->id,
            'field' => 'site_photo',
            'filename' => $subOneFilename,
        ]);

        // 1. Guest redirected to login
        $this->get($photoRoute)->assertRedirect(route('login'));

        // 2. Authenticated non-admin denied with 403
        $this->actingAs($regularUser)->get($photoRoute)->assertForbidden();

        // 3. Admin requesting submissionTwo's filename under submissionOne's route is denied with 403
        $crossSubmissionRoute = route('admin.feedback.submissions.photo', [
            'submission' => $submissionOne->id,
            'field' => 'site_photo',
            'filename' => $subTwoFilename,
        ]);
        $this->actingAs($admin)->get($crossSubmissionRoute)->assertForbidden();

        // 4. Non-photo field is denied with 403
        $nonPhotoFieldRoute = route('admin.feedback.submissions.photo', [
            'submission' => $submissionOne->id,
            'field' => 'overall_rating',
            'filename' => $subOneFilename,
        ]);
        $this->actingAs($admin)->get($nonPhotoFieldRoute)->assertForbidden();
    }
}
