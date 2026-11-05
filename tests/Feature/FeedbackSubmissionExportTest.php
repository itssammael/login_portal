<?php

namespace Tests\Feature;

use App\Models\FbEvent;
use App\Models\FbFunction;
use App\Models\FbParticipant;
use App\Models\FbSubmission;
use App\Models\Feedback;
use App\Models\User;
use App\Services\FeedbackSchemaValidator;
use App\Services\FeedbackSubmissionExportService;
use App\Services\FeedbackSubmissionPdfBuilder;
use App\Services\FeedbackSubmissionPersistenceService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class FeedbackSubmissionExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Storage::fake('local');
    }

    /**
     * Parse a streamed CSV response body (removing the UTF-8 BOM) into an array of rows.
     *
     * @return array<int, array<int, string|null>>
     */
    private function parseCsvRows(string $csvContent): array
    {
        $withoutBom = str_starts_with($csvContent, "\xEF\xBB\xBF")
            ? substr($csvContent, 3)
            : $csvContent;

        $stream = fopen('php://temp', 'r+b');
        $this->assertNotFalse($stream);
        fwrite($stream, $withoutBom);
        rewind($stream);

        $rows = [];
        while (($row = fgetcsv($stream, 0, ',', '"', '')) !== false) {
            if ($row === [null]) {
                continue;
            }
            $rows[] = $row;
        }
        fclose($stream);

        return $rows;
    }

    public function test_export_endpoints_enforce_authentication_admin_authorization_and_format_constraints(): void
    {
        $regularUser = User::factory()->create();
        $admin = User::factory()->admin()->create();

        // Guest redirected to login
        $this->get(route('admin.feedback.submissions.export', ['format' => 'csv']))
            ->assertRedirect(route('login'));
        $this->get(route('admin.feedback.submissions.export', ['format' => 'pdf']))
            ->assertRedirect(route('login'));

        // Non-admin user receives 403 Forbidden
        $this->actingAs($regularUser)
            ->get(route('admin.feedback.submissions.export', ['format' => 'csv']))
            ->assertForbidden();
        $this->actingAs($regularUser)
            ->get(route('admin.feedback.submissions.export', ['format' => 'pdf']))
            ->assertForbidden();

        // Unsupported format constrained to 404
        $this->actingAs($admin)
            ->get('/admin/feedback/submissions/export/xml')
            ->assertNotFound();
    }

    public function test_exports_include_all_matching_submissions_beyond_fifteen_row_pagination(): void
    {
        $admin = User::factory()->admin()->create();
        $event = FbEvent::factory()->create(['name' => 'National Earthquake Drill']);
        $feedback = Feedback::factory()->create([
            'event_id' => $event->id,
            'status' => Feedback::STATUS_PUBLISHED,
            'schema' => [
                'fields' => [
                    [
                        'id' => 'q_comment',
                        'particular' => 'Drill Observation',
                        'type' => 'text',
                        'weight' => 1,
                    ],
                ],
            ],
        ]);

        for ($i = 1; $i <= 20; $i++) {
            $participant = FbParticipant::factory()->create([
                'name' => sprintf('Responder #%02d', $i),
                'agency' => 'OCD',
                'designation' => 'Evaluator',
            ]);

            FbSubmission::factory()->create([
                'feedback_id' => $feedback->id,
                'participant_id' => $participant->id,
                'data' => ['q_comment' => "Observation entry {$i}"],
            ]);
        }

        // Listing paginates at 15 rows while reporting total = 20
        $listing = $this->actingAs($admin)->get(route('admin.feedback.submissions', [
            'event_id' => $event->id,
            'tab' => 'individual',
        ]));
        $listing->assertOk();
        $listing->assertInertia(fn ($page) => $page
            ->has('submissions.data', 15)
            ->where('submissions.total', 20)
        );

        // CSV export contains header + all 20 matching submission rows
        $csvResponse = $this->actingAs($admin)->get(route('admin.feedback.submissions.export', [
            'format' => 'csv',
            'event_id' => $event->id,
            'tab' => 'individual',
        ]));
        $csvResponse->assertOk();
        $this->assertStringContainsString('text/csv', (string) $csvResponse->headers->get('Content-Type'));

        $csvBody = $csvResponse->streamedContent();
        $this->assertTrue(str_starts_with($csvBody, "\xEF\xBB\xBF"));
        $rows = $this->parseCsvRows($csvBody);
        $this->assertCount(21, $rows); // 1 header + 20 data rows

        // PDF export contains all 20 submissions across multiple pages
        $pdfResponse = $this->actingAs($admin)->get(route('admin.feedback.submissions.export', [
            'format' => 'pdf',
            'event_id' => $event->id,
            'tab' => 'individual',
        ]));
        $pdfResponse->assertOk();
        $pdfResponse->assertHeader('Content-Type', 'application/pdf');

        $pdfContent = $pdfResponse->getContent();
        $this->assertNotFalse($pdfContent);
        $this->assertStringStartsWith('%PDF-1.4', $pdfContent);
        $this->assertStringContainsString('Total Matching Submissions: 20', $pdfContent);
        $this->assertStringContainsString('Submission 20 of 20', $pdfContent);
        $this->assertStringContainsString('Observation entry 1', $pdfContent);
        $this->assertStringContainsString('Observation entry 20', $pdfContent);
    }

    public function test_exports_apply_identical_filters_for_individual_and_compiled_views(): void
    {
        $admin = User::factory()->admin()->create();

        $eventA = FbEvent::factory()->create(['name' => 'Incident Command Summit']);
        $eventB = FbEvent::factory()->create(['name' => 'Logistics Readiness Forum']);

        $fnComms = FbFunction::factory()->create(['function' => 'Communications Unit']);
        $fnMed = FbFunction::factory()->create(['function' => 'Medical Triage']);
        $eventA->functions()->attach([$fnComms->id, $fnMed->id]);

        $feedbackA = Feedback::factory()->create([
            'event_id' => $eventA->id,
            'status' => Feedback::STATUS_PUBLISHED,
            'schema' => [
                'fields' => [
                    [
                        'id' => 'rating',
                        'particular' => 'Readiness Rating',
                        'type' => 'radio',
                        'weight' => 1,
                        'options' => [
                            ['value' => 'high', 'label' => 'High Readiness'],
                            ['value' => 'low', 'label' => 'Needs Work'],
                        ],
                    ],
                ],
            ],
        ]);

        $feedbackB = Feedback::factory()->create([
            'event_id' => $eventB->id,
            'status' => Feedback::STATUS_PUBLISHED,
            'schema' => [
                'fields' => [
                    [
                        'id' => 'rating',
                        'particular' => 'Forum Rating',
                        'type' => 'text',
                        'weight' => 1,
                    ],
                ],
            ],
        ]);

        // Matching submission 1 (Event A, Comms, DILG, Officer, Name "Elena Cruz")
        $subMatchElena = FbSubmission::factory()->create([
            'feedback_id' => $feedbackA->id,
            'participant_id' => FbParticipant::factory()->create([
                'name' => 'Elena Cruz',
                'function_id' => $fnComms->id,
                'agency' => 'DILG',
                'designation' => 'Operations Officer',
                'location' => 'Quezon City',
            ])->id,
            'data' => ['rating' => 'high'],
        ]);

        // Matching Event/Function/Agency/Designation, different participant name ("Marco Reyes")
        $subMatchMarco = FbSubmission::factory()->create([
            'feedback_id' => $feedbackA->id,
            'participant_id' => FbParticipant::factory()->create([
                'name' => 'Marco Reyes',
                'function_id' => $fnComms->id,
                'agency' => 'DILG',
                'designation' => 'Operations Officer',
                'location' => 'Manila',
            ])->id,
            'data' => ['rating' => 'low'],
        ]);

        // Non-matching agency
        FbSubmission::factory()->create([
            'feedback_id' => $feedbackA->id,
            'participant_id' => FbParticipant::factory()->create([
                'name' => 'Elena Different Agency',
                'function_id' => $fnComms->id,
                'agency' => 'DOST',
                'designation' => 'Operations Officer',
            ])->id,
            'data' => ['rating' => 'high'],
        ]);

        // Non-matching function
        FbSubmission::factory()->create([
            'feedback_id' => $feedbackA->id,
            'participant_id' => FbParticipant::factory()->create([
                'name' => 'Elena Different Function',
                'function_id' => $fnMed->id,
                'agency' => 'DILG',
                'designation' => 'Operations Officer',
            ])->id,
            'data' => ['rating' => 'high'],
        ]);

        // Non-matching event
        FbSubmission::factory()->create([
            'feedback_id' => $feedbackB->id,
            'participant_id' => FbParticipant::factory()->create([
                'name' => 'Elena Other Event',
                'function_id' => $fnComms->id,
                'agency' => 'DILG',
                'designation' => 'Operations Officer',
            ])->id,
            'data' => ['rating' => 'Good'],
        ]);

        // 1. Individual view with all 5 filters (including search="Elena") -> matches ONLY $subMatchElena
        $individualCsv = $this->actingAs($admin)->get(route('admin.feedback.submissions.export', [
            'format' => 'csv',
            'tab' => 'individual',
            'event_id' => $eventA->id,
            'function_id' => $fnComms->id,
            'agency' => 'DILG',
            'designation' => 'Operations Officer',
            'search' => 'Elena',
        ]));
        $individualCsv->assertOk();
        $indRows = $this->parseCsvRows($individualCsv->streamedContent());
        $this->assertCount(2, $indRows);
        $this->assertSame($subMatchElena->reference_code, $indRows[1][0]);

        // 2. Compiled view requires event_id; rejects missing event_id
        $this->actingAs($admin)->getJson(route('admin.feedback.submissions.export', [
            'format' => 'csv',
            'tab' => 'compiled',
        ]))->assertStatus(422)->assertJsonValidationErrors(['event_id']);

        // 3. Compiled view with event/function/agency/designation exports BOTH DILG Comms Officers (ignores search)
        $compiledCsv = $this->actingAs($admin)->get(route('admin.feedback.submissions.export', [
            'format' => 'csv',
            'tab' => 'compiled',
            'event_id' => $eventA->id,
            'function_id' => $fnComms->id,
            'agency' => 'DILG',
            'designation' => 'Operations Officer',
            'search' => 'Elena',
        ]));
        $compiledCsv->assertOk();
        $compRows = $this->parseCsvRows($compiledCsv->streamedContent());
        $this->assertCount(3, $compRows); // header + Elena + Marco
        $exportedRefs = [$compRows[1][0], $compRows[2][0]];
        $this->assertContains($subMatchElena->reference_code, $exportedRefs);
        $this->assertContains($subMatchMarco->reference_code, $exportedRefs);

        // 4. Compiled PDF includes compiled breakdown summary + per-submission cards
        $compiledPdf = $this->actingAs($admin)->get(route('admin.feedback.submissions.export', [
            'format' => 'pdf',
            'tab' => 'compiled',
            'event_id' => $eventA->id,
            'function_id' => $fnComms->id,
            'agency' => 'DILG',
            'designation' => 'Operations Officer',
        ]));
        $compiledPdf->assertOk();
        $pdfText = (string) $compiledPdf->getContent();
        $this->assertStringContainsString('COMPILED QUESTION BREAKDOWN SUMMARY', $pdfText);
        $this->assertStringContainsString('High Readiness: 1 \(50.0%\)', $pdfText);
        $this->assertStringContainsString('Needs Work: 1 \(50.0%\)', $pdfText);
        $this->assertStringContainsString($subMatchElena->reference_code, $pdfText);
        $this->assertStringContainsString($subMatchMarco->reference_code, $pdfText);
    }

    public function test_schema_formatting_handles_all_field_types_labels_csv_photo_links_and_pdf_embedded_images(): void
    {
        $admin = User::factory()->admin()->create();
        $event = FbEvent::factory()->create(['name' => 'Regional Interoperability Exercise']);
        $fnLogistics = FbFunction::factory()->create(['function' => 'Supply Chain Unit']);
        $event->functions()->attach([$fnLogistics->id]);

        $feedback = Feedback::factory()->create([
            'event_id' => $event->id,
            'status' => Feedback::STATUS_PUBLISHED,
            'schema' => [
                'fields' => [
                    [
                        'id' => 'sec_intro',
                        'particular' => 'Section 1: Overview',
                        'type' => 'section',
                        'weight' => 1,
                    ],
                    [
                        'id' => 'txt_instructions',
                        'particular' => 'Read carefully',
                        'description' => 'Instructional block only',
                        'type' => 'text_only',
                        'weight' => 2,
                    ],
                    [
                        'id' => 'f_internal_radio',
                        'particular' => '<p><strong>Overall Assessment</strong> &amp; Clarity</p>',
                        'type' => 'radio',
                        'weight' => 3,
                        'allow_other' => true,
                        'options' => [
                            ['value' => 'great', 'label' => 'Exceeds Expectations'],
                            ['value' => 'other', 'label' => 'Other Rating', 'is_other' => true],
                        ],
                    ],
                    [
                        'id' => 'f_internal_select',
                        'particular' => 'Assigned Cluster Function',
                        'type' => 'select',
                        'weight' => 4,
                        'option_source' => 'fb_functions',
                    ],
                    [
                        'id' => 'f_internal_checkbox',
                        'particular' => 'Strengths Observed',
                        'type' => 'checkbox',
                        'weight' => 5,
                        'options' => [
                            ['value' => 'speed', 'label' => 'Rapid Dispatch'],
                            ['value' => 'coord', 'label' => 'Inter-Agency Sync'],
                            ['value' => 'safety', 'label' => 'Field Safety'],
                        ],
                    ],
                    [
                        'id' => 'f_internal_number',
                        'particular' => 'Net Variance Score',
                        'type' => 'number',
                        'weight' => 6,
                        'min' => -10,
                        'max' => 10,
                    ],
                    [
                        'id' => 'f_internal_date',
                        'particular' => 'Observation Date',
                        'type' => 'date',
                        'weight' => 7,
                    ],
                    [
                        'id' => 'f_internal_unanswered',
                        'particular' => 'Optional Follow-up Notes',
                        'type' => 'textarea',
                        'weight' => 8,
                        'required' => false,
                    ],
                    [
                        'id' => 'f_internal_photo',
                        'particular' => 'Field Evidence Photo',
                        'type' => 'photo',
                        'weight' => 9,
                        'required' => false,
                    ],
                ],
            ],
        ]);

        /** @var FeedbackSubmissionPersistenceService $persistence */
        $persistence = app(FeedbackSubmissionPersistenceService::class);

        $uploadedPng = UploadedFile::fake()->image('command-tent.png', 240, 160);

        $submission = $persistence->persistSubmission(
            $event,
            $feedback,
            [
                'name' => '', // Anonymous participant
                'function_id' => $fnLogistics->id,
                'agency' => 'NDRRMC',
                'designation' => 'Field Inspector',
                'years_in_designation' => 6,
                'location' => 'Cebu',
                'no_of_exercises' => 4,
            ],
            [
                'f_internal_radio' => 'other',
                'f_internal_radio_other' => 'Conditional Pass with Commendation',
                'f_internal_select' => (string) $fnLogistics->id,
                'f_internal_checkbox' => ['speed', 'safety'],
                'f_internal_number' => -3,
                'f_internal_date' => '2026-11-05',
                'f_internal_photo' => $uploadedPng,
            ]
        );

        // Verify CSV headers & formatted values
        $csvResponse = $this->actingAs($admin)->get(route('admin.feedback.submissions.export', [
            'format' => 'csv',
            'event_id' => $event->id,
            'tab' => 'individual',
        ]));
        $csvResponse->assertOk();

        $rows = $this->parseCsvRows($csvResponse->streamedContent());
        $this->assertCount(2, $rows);
        $headers = $rows[0];
        $values = $rows[1];

        // Internal field IDs and layout blocks (`section`, `text_only`) must NOT appear in headers
        $this->assertNotContains('f_internal_radio', $headers);
        $this->assertNotContains('Section 1: Overview', $headers);
        $this->assertNotContains('Read carefully', $headers);

        // Clean stripped question labels ARE used as column headers
        $headerMap = array_flip($headers);
        $this->assertArrayHasKey('Overall Assessment & Clarity', $headerMap);
        $this->assertArrayHasKey('Assigned Cluster Function', $headerMap);
        $this->assertArrayHasKey('Strengths Observed', $headerMap);
        $this->assertArrayHasKey('Net Variance Score', $headerMap);
        $this->assertArrayHasKey('Observation Date', $headerMap);
        $this->assertArrayHasKey('Optional Follow-up Notes', $headerMap);
        $this->assertArrayHasKey('Field Evidence Photo', $headerMap);

        // Participant & answer values
        $this->assertSame('Anonymous Participant', $values[$headerMap['Participant Name']]);
        $this->assertSame('Supply Chain Unit', $values[$headerMap['Function']]);
        $this->assertSame(
            'Other Rating: Conditional Pass with Commendation',
            $values[$headerMap['Overall Assessment & Clarity']]
        );
        $this->assertSame('Supply Chain Unit', $values[$headerMap['Assigned Cluster Function']]);
        $this->assertSame('Rapid Dispatch; Field Safety', $values[$headerMap['Strengths Observed']]);
        $this->assertSame('-3', $values[$headerMap['Net Variance Score']]);
        $this->assertSame('2026-11-05', $values[$headerMap['Observation Date']]);
        $this->assertSame('', $values[$headerMap['Optional Follow-up Notes']]);

        // CSV photo column includes safe filename + authorized route URL, without leaking storage disk paths
        $csvPhotoCell = (string) $values[$headerMap['Field Evidence Photo']];
        $this->assertStringContainsString('command-tent.png', $csvPhotoCell);
        $this->assertStringContainsString('/admin/feedback/submissions/'.$submission->id.'/photos/f_internal_photo/', $csvPhotoCell);
        $this->assertStringNotContainsString('feedback-submissions/', $csvPhotoCell);

        // Verify PDF embeds the actual raster photo as a `/Subtype /Image` `/Filter /DCTDecode` XObject
        $pdfResponse = $this->actingAs($admin)->get(route('admin.feedback.submissions.export', [
            'format' => 'pdf',
            'event_id' => $event->id,
            'tab' => 'individual',
        ]));
        $pdfResponse->assertOk();
        $pdfBinary = (string) $pdfResponse->getContent();

        $this->assertStringStartsWith('%PDF-1.4', $pdfBinary);
        $this->assertStringContainsString('/Subtype /Image', $pdfBinary);
        $this->assertStringContainsString('/Filter /DCTDecode', $pdfBinary);
        $this->assertStringContainsString('/Im1 Do', $pdfBinary);
        $this->assertStringContainsString('Photo: command-tent.png', $pdfBinary);
        $this->assertStringContainsString('Other Rating: Conditional Pass with Commendation', $pdfBinary);
        $this->assertStringContainsString('Rapid Dispatch; Field Safety', $pdfBinary);

        // If the stored photo file is later removed from disk, PDF export gracefully falls back without failing
        $storedPath = (string) ($submission->data['f_internal_photo']['path'] ?? '');
        Storage::disk('local')->delete($storedPath);

        $fallbackPdf = $this->actingAs($admin)->get(route('admin.feedback.submissions.export', [
            'format' => 'pdf',
            'event_id' => $event->id,
            'tab' => 'individual',
        ]));
        $fallbackPdf->assertOk();
        $this->assertStringContainsString(
            '[Photo file unavailable on disk: command-tent.png]',
            (string) $fallbackPdf->getContent()
        );
    }

    public function test_multi_event_export_disambiguates_duplicate_question_headers(): void
    {
        $admin = User::factory()->admin()->create();
        $eventOne = FbEvent::factory()->create(['name' => 'Alpha Exercise']);
        $eventTwo = FbEvent::factory()->create(['name' => 'Bravo Exercise']);

        $fbOne = Feedback::factory()->create([
            'event_id' => $eventOne->id,
            'schema' => [
                'fields' => [
                    ['id' => 'q1', 'particular' => 'General Remarks', 'type' => 'text', 'weight' => 1],
                ],
            ],
        ]);
        $fbTwo = Feedback::factory()->create([
            'event_id' => $eventTwo->id,
            'schema' => [
                'fields' => [
                    ['id' => 'q1', 'particular' => 'General Remarks', 'type' => 'text', 'weight' => 1],
                ],
            ],
        ]);

        FbSubmission::factory()->create(['feedback_id' => $fbOne->id, 'data' => ['q1' => 'Alpha remark']]);
        FbSubmission::factory()->create(['feedback_id' => $fbTwo->id, 'data' => ['q1' => 'Bravo remark']]);

        $csvResponse = $this->actingAs($admin)->get(route('admin.feedback.submissions.export', [
            'format' => 'csv',
            'tab' => 'individual',
        ]));
        $csvResponse->assertOk();

        $rows = $this->parseCsvRows($csvResponse->streamedContent());
        $this->assertCount(3, $rows);
        $headers = $rows[0];
        $this->assertContains('Alpha Exercise — General Remarks', $headers);
        $this->assertContains('Bravo Exercise — General Remarks', $headers);
    }

    public function test_csv_escapes_commas_quotes_newlines_and_neutralizes_formula_injection(): void
    {
        $admin = User::factory()->admin()->create();
        $event = FbEvent::factory()->create(['name' => 'CSV Security Audit Event']);
        $feedback = Feedback::factory()->create([
            'event_id' => $event->id,
            'schema' => [
                'fields' => [
                    ['id' => 'q_formula', 'particular' => 'Formula Input', 'type' => 'text', 'weight' => 1],
                    ['id' => 'q_multiline', 'particular' => 'Complex Notes', 'type' => 'textarea', 'weight' => 2],
                ],
            ],
        ]);

        $participant = FbParticipant::factory()->create([
            'name' => '=HYPERLINK("http://evil.test","Click")',
            'agency' => '+SUM(1,2)',
            'designation' => '  @IMPORTXML()',
        ]);

        FbSubmission::factory()->create([
            'feedback_id' => $feedback->id,
            'participant_id' => $participant->id,
            'data' => [
                'q_formula' => '=CMD|\' /C calc\'!A0',
                'q_multiline' => "Line 1, with comma\nLine 2 with \"quotes\" and UTF-8 ñ",
            ],
        ]);

        $response = $this->actingAs($admin)->get(route('admin.feedback.submissions.export', [
            'format' => 'csv',
            'event_id' => $event->id,
            'tab' => 'individual',
        ]));
        $response->assertOk();

        $rows = $this->parseCsvRows($response->streamedContent());
        $this->assertCount(2, $rows);

        $headerMap = array_flip($rows[0]);
        $row = $rows[1];

        // Formula prefixes neutralized with leading apostrophe
        $this->assertSame('\'=HYPERLINK("http://evil.test","Click")', $row[$headerMap['Participant Name']]);
        $this->assertSame("'+SUM(1,2)", $row[$headerMap['Agency']]);
        $this->assertSame("'  @IMPORTXML()", $row[$headerMap['Designation']]);
        $this->assertSame("'=CMD|' /C calc'!A0", $row[$headerMap['Formula Input']]);

        // Multiline, comma, quote, and UTF-8 content preserved intact as a single cell
        $this->assertSame(
            "Line 1, with comma\nLine 2 with \"quotes\" and UTF-8 ñ",
            $row[$headerMap['Complex Notes']]
        );
    }

    public function test_empty_results_and_long_multiline_pdf_answers_render_cleanly(): void
    {
        $admin = User::factory()->admin()->create();
        $event = FbEvent::factory()->create(['name' => 'Long Narrative Evaluation']);
        $feedback = Feedback::factory()->create([
            'event_id' => $event->id,
            'schema' => [
                'fields' => [
                    ['id' => 'narrative', 'particular' => 'After-Action Narrative', 'type' => 'textarea', 'weight' => 1],
                ],
            ],
        ]);

        // 1. Empty results (0 matching rows)
        $emptyCsv = $this->actingAs($admin)->get(route('admin.feedback.submissions.export', [
            'format' => 'csv',
            'event_id' => $event->id,
            'tab' => 'individual',
        ]));
        $emptyCsv->assertOk();
        $emptyCsvRows = $this->parseCsvRows($emptyCsv->streamedContent());
        $this->assertCount(1, $emptyCsvRows); // Header row only
        $this->assertContains('After-Action Narrative', $emptyCsvRows[0]);

        $emptyPdf = $this->actingAs($admin)->get(route('admin.feedback.submissions.export', [
            'format' => 'pdf',
            'event_id' => $event->id,
            'tab' => 'individual',
        ]));
        $emptyPdf->assertOk();
        $emptyPdfBody = (string) $emptyPdf->getContent();
        $this->assertStringStartsWith('%PDF-1.4', $emptyPdfBody);
        $this->assertStringContainsString('No feedback submissions matched the selected filter criteria.', $emptyPdfBody);
        $this->assertStringEndsWith('%%EOF', $emptyPdfBody);

        // 2. Very long multi-paragraph answer + unbroken URL triggers multi-page PDF pagination
        $longParagraph = str_repeat(
            'Inter-agency radio relay checkpoints maintained continuous situational awareness across all sectors. ',
            45
        );
        $unbrokenToken = 'https://portal.example.gov/archive/after-action/'.str_repeat('segment-', 20).'final';

        FbSubmission::factory()->create([
            'feedback_id' => $feedback->id,
            'data' => [
                'narrative' => $longParagraph."\n\n".$unbrokenToken."\n\n".$longParagraph,
            ],
        ]);

        $longPdf = $this->actingAs($admin)->get(route('admin.feedback.submissions.export', [
            'format' => 'pdf',
            'event_id' => $event->id,
            'tab' => 'individual',
        ]));
        $longPdf->assertOk();
        $longPdfBody = (string) $longPdf->getContent();

        $this->assertStringStartsWith('%PDF-1.4', $longPdfBody);
        $this->assertStringEndsWith('%%EOF', $longPdfBody);
        $this->assertMatchesRegularExpression('/\/Type \/Pages \/Kids \[[^\]]+\] \/Count ([2-9]|\d{2,})/', $longPdfBody);
        $this->assertStringContainsString('Continued', $longPdfBody);
    }

    public function test_pdf_export_renders_emoji_responses_and_unicode_without_question_marks(): void
    {
        $admin = User::factory()->admin()->create();
        $event = FbEvent::factory()->create(['name' => 'Emoji & Rating Drill']);

        $feedback = Feedback::factory()->create([
            'event_id' => $event->id,
            'status' => Feedback::STATUS_PUBLISHED,
            'schema' => [
                'fields' => [
                    [
                        'id' => 'overall_rating',
                        'particular' => 'Overall exercise rating ⭐',
                        'type' => 'radio',
                        'weight' => 1,
                        'options' => [
                            ['value' => 'excellent', 'label' => '⭐⭐⭐⭐⭐ Outstanding / Excellent'],
                            ['value' => 'warn', 'label' => '⚠️ Needs Improvement'],
                        ],
                    ],
                    [
                        'id' => 'mood',
                        'particular' => 'Respondent Sentiment',
                        'type' => 'text',
                        'weight' => 2,
                    ],
                    [
                        'id' => 'comments',
                        'particular' => 'Field Remarks',
                        'type' => 'textarea',
                        'weight' => 3,
                    ],
                ],
            ],
        ]);

        FbSubmission::factory()->create([
            'feedback_id' => $feedback->id,
            'participant_id' => FbParticipant::factory()->create([
                'name' => 'José Niño',
                'agency' => 'OCD',
                'designation' => 'Evaluator',
            ])->id,
            'data' => [
                'overall_rating' => 'excellent',
                'mood' => '😊',
                'comments' => 'Great coordination 👍🏽 ❤️ 🇵🇭 1️⃣ — budget ₱1,500 with 🚒 support!',
            ],
        ]);

        $pdfResponse = $this->actingAs($admin)->get(route('admin.feedback.submissions.export', [
            'format' => 'pdf',
            'event_id' => $event->id,
            'tab' => 'compiled',
        ]));
        $pdfResponse->assertOk();

        $pdfBody = (string) $pdfResponse->getContent();
        $this->assertStringStartsWith('%PDF-1.4', $pdfBody);
        $this->assertStringEndsWith('%%EOF', $pdfBody);

        // Emojis must never degrade into `????` or `??`
        $this->assertStringNotContainsString('????', $pdfBody);
        $this->assertStringNotContainsString('??', $pdfBody);

        // Vector star & smiley face drawing commands are present alongside clean WinAnsi text
        $this->assertStringContainsString('0.980 0.750 0.120 rg', $pdfBody); // Gold star vector fill
        $this->assertStringContainsString('0.990 0.805 0.180 rg', $pdfBody); // Smiley face vector fill
        $this->assertStringContainsString('Outstanding / Excellent', $pdfBody);
        $this->assertStringContainsString('Needs Improvement', $pdfBody);
        $this->assertStringContainsString('Jos\351 Ni\361o', $pdfBody); // José Niño in WinAnsi octal
        $this->assertStringContainsString('PHP 1,500', $pdfBody);
        $this->assertStringContainsString('[fire engine]', $pdfBody);
    }

    public function test_submissions_listing_exposes_exportable_fields_catalog_excluding_layout_blocks(): void
    {
        $admin = User::factory()->admin()->create();
        $event = FbEvent::factory()->create(['name' => 'Field Builder Exercise']);
        $feedback = Feedback::factory()->create([
            'event_id' => $event->id,
            'status' => Feedback::STATUS_PUBLISHED,
            'schema' => [
                'fields' => [
                    ['id' => 'sec_1', 'particular' => 'Header Block', 'type' => 'section', 'weight' => 1],
                    ['id' => 'info_1', 'particular' => 'Guidance Note', 'type' => 'text_only', 'weight' => 2],
                    ['id' => 'q_alpha', 'particular' => '<strong>Alpha Question</strong>', 'type' => 'radio', 'weight' => 3, 'options' => [['value' => 'yes', 'label' => 'Yes']]],
                    ['id' => 'q_beta', 'particular' => 'Beta Remarks', 'type' => 'textarea', 'weight' => 4],
                ],
            ],
        ]);

        FbSubmission::factory()->create([
            'feedback_id' => $feedback->id,
            'data' => ['q_alpha' => 'yes', 'q_beta' => 'Noted'],
        ]);

        $response = $this->actingAs($admin)->get(route('admin.feedback.submissions', [
            'event_id' => $event->id,
            'tab' => 'individual',
        ]));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Feedback/Submissions')
            ->has('exportableFields.metadata', 10)
            ->where('exportableFields.metadata.0.token', 'meta:reference_code')
            ->where('exportableFields.metadata.0.label', 'Reference Code')
            ->where('exportableFields.metadata.9.token', 'meta:submitted_at')
            ->has('exportableFields.questions', 2)
            ->where('exportableFields.questions.0.token', "q:{$feedback->id}:q_alpha")
            ->where('exportableFields.questions.0.label', 'Alpha Question')
            ->where('exportableFields.questions.0.type', 'radio')
            ->where('exportableFields.questions.1.token', "q:{$feedback->id}:q_beta")
            ->where('exportableFields.questions.1.label', 'Beta Remarks')
            ->where('exportableFields.questions.1.event_name', 'Field Builder Exercise')
        );
    }

    public function test_field_builder_selective_and_reordered_csv_export_outputs_exact_requested_columns(): void
    {
        $admin = User::factory()->admin()->create();
        $event = FbEvent::factory()->create(['name' => 'Custom Column Order Drill']);
        $feedback = Feedback::factory()->create([
            'event_id' => $event->id,
            'status' => Feedback::STATUS_PUBLISHED,
            'schema' => [
                'fields' => [
                    [
                        'id' => 'q_score',
                        'particular' => 'Readiness Score',
                        'type' => 'radio',
                        'weight' => 1,
                        'options' => [
                            ['value' => 'pass', 'label' => 'Operational Pass'],
                        ],
                    ],
                    [
                        'id' => 'q_remarks',
                        'particular' => 'Actionable Remarks',
                        'type' => 'textarea',
                        'weight' => 2,
                    ],
                    [
                        'id' => 'q_internal',
                        'particular' => 'Internal Unselected Question',
                        'type' => 'text',
                        'weight' => 3,
                    ],
                ],
            ],
        ]);

        $participant = FbParticipant::factory()->create([
            'name' => 'Confidential Responder',
            'agency' => 'Restricted Agency',
            'designation' => 'Lead Planner',
        ]);

        $submission = FbSubmission::factory()->create([
            'feedback_id' => $feedback->id,
            'participant_id' => $participant->id,
            'data' => [
                'q_score' => 'pass',
                'q_remarks' => 'Deploy backup VHF repeaters',
                'q_internal' => 'Should stay excluded',
            ],
        ]);

        $selectedFields = implode(',', [
            "q:{$feedback->id}:q_remarks",
            'meta:reference_code',
            "q:{$feedback->id}:q_score",
        ]);

        $csvResponse = $this->actingAs($admin)->get(route('admin.feedback.submissions.export', [
            'format' => 'csv',
            'event_id' => $event->id,
            'tab' => 'individual',
            'fields' => $selectedFields,
        ]));
        $csvResponse->assertOk();

        $rows = $this->parseCsvRows($csvResponse->streamedContent());
        $this->assertCount(2, $rows);

        // Exact custom column order preserved; unselected PII & questions omitted
        $this->assertSame(
            ['Actionable Remarks', 'Reference Code', 'Readiness Score'],
            $rows[0]
        );
        $this->assertSame(
            ['Deploy backup VHF repeaters', $submission->reference_code, 'Operational Pass'],
            $rows[1]
        );

        $rawCsv = $csvResponse->streamedContent();
        $this->assertStringNotContainsString('Confidential Responder', $rawCsv);
        $this->assertStringNotContainsString('Restricted Agency', $rawCsv);
        $this->assertStringNotContainsString('Should stay excluded', $rawCsv);
    }

    public function test_field_builder_selective_pdf_export_supports_questions_only_participant_only_and_compiled_subset(): void
    {
        $admin = User::factory()->admin()->create();
        $event = FbEvent::factory()->create(['name' => 'Redacted PDF Exercise']);
        $feedback = Feedback::factory()->create([
            'event_id' => $event->id,
            'status' => Feedback::STATUS_PUBLISHED,
            'schema' => [
                'fields' => [
                    [
                        'id' => 'q_first',
                        'particular' => 'Primary Sector Rating',
                        'type' => 'radio',
                        'weight' => 1,
                        'options' => [
                            ['value' => 'ready', 'label' => 'Sector Ready'],
                        ],
                    ],
                    [
                        'id' => 'q_second',
                        'particular' => 'Key Observation',
                        'type' => 'textarea',
                        'weight' => 2,
                    ],
                    [
                        'id' => 'q_omitted',
                        'particular' => 'Omitted Follow-Up',
                        'type' => 'text',
                        'weight' => 3,
                    ],
                ],
            ],
        ]);

        $participant = FbParticipant::factory()->create([
            'name' => 'Redacted Officer Name',
            'agency' => 'Secretariat Unit',
            'designation' => 'Chief Evaluator',
        ]);

        $submission = FbSubmission::factory()->create([
            'feedback_id' => $feedback->id,
            'participant_id' => $participant->id,
            'data' => [
                'q_first' => 'ready',
                'q_second' => 'Staging area signage was clear',
                'q_omitted' => 'Hidden answer payload',
            ],
        ]);

        // 1. Questions-Only PDF (redacts all participant & reference metadata; reverses question order)
        $questionsOnlyPdf = $this->actingAs($admin)->get(route('admin.feedback.submissions.export', [
            'format' => 'pdf',
            'event_id' => $event->id,
            'tab' => 'individual',
            'fields' => "q:{$feedback->id}:q_second,q:{$feedback->id}:q_first",
        ]));
        $questionsOnlyPdf->assertOk();
        $qPdfBody = (string) $questionsOnlyPdf->getContent();

        $this->assertStringContainsString('Fields Included: 2 of 13 selected', $qPdfBody);
        $this->assertStringContainsString('Key Observation', $qPdfBody);
        $this->assertStringContainsString('Staging area signage was clear', $qPdfBody);
        $this->assertStringContainsString('Primary Sector Rating', $qPdfBody);
        $this->assertStringContainsString('Sector Ready', $qPdfBody);

        // Verify custom question order (`Key Observation` appears before `Primary Sector Rating`)
        $posSecond = strpos($qPdfBody, 'Key Observation');
        $posFirst = strpos($qPdfBody, 'Primary Sector Rating');
        $this->assertNotFalse($posSecond);
        $this->assertNotFalse($posFirst);
        $this->assertLessThan($posFirst, $posSecond);

        // Unselected PII, reference code, and omitted question are absent
        $this->assertStringNotContainsString('Redacted Officer Name', $qPdfBody);
        $this->assertStringNotContainsString('Secretariat Unit', $qPdfBody);
        $this->assertStringNotContainsString($submission->reference_code, $qPdfBody);
        $this->assertStringNotContainsString('Omitted Follow-Up', $qPdfBody);
        $this->assertStringNotContainsString('Hidden answer payload', $qPdfBody);

        // 2. Participant-Info-Only PDF (renders metadata card cleanly without questionnaire answers or empty-schema warning)
        $participantOnlyPdf = $this->actingAs($admin)->get(route('admin.feedback.submissions.export', [
            'format' => 'pdf',
            'event_id' => $event->id,
            'tab' => 'individual',
            'fields' => 'meta:reference_code,meta:participant_name,meta:agency',
        ]));
        $participantOnlyPdf->assertOk();
        $pPdfBody = (string) $participantOnlyPdf->getContent();

        $this->assertStringContainsString('Fields Included: 3 of 13 selected', $pPdfBody);
        $this->assertStringContainsString($submission->reference_code, $pPdfBody);
        $this->assertStringContainsString('Participant: Redacted Officer Name', $pPdfBody);
        $this->assertStringContainsString('Agency: Secretariat Unit', $pPdfBody);
        $this->assertStringNotContainsString('Primary Sector Rating', $pPdfBody);
        $this->assertStringNotContainsString('Staging area signage was clear', $pPdfBody);
        $this->assertStringNotContainsString('No questionnaire fields configured', $pPdfBody);

        // 3. Compiled PDF with Question Subset filters COMPILED QUESTION BREAKDOWN SUMMARY to selected questions
        $compiledSubsetPdf = $this->actingAs($admin)->get(route('admin.feedback.submissions.export', [
            'format' => 'pdf',
            'event_id' => $event->id,
            'tab' => 'compiled',
            'fields' => "q:{$feedback->id}:q_first",
        ]));
        $compiledSubsetPdf->assertOk();
        $cPdfBody = (string) $compiledSubsetPdf->getContent();

        $this->assertStringContainsString('COMPILED QUESTION BREAKDOWN SUMMARY', $cPdfBody);
        $this->assertStringContainsString('Primary Sector Rating', $cPdfBody);
        $this->assertStringContainsString('Sector Ready: 1 \(100.0%\)', $cPdfBody);
        $this->assertStringNotContainsString('Key Observation', $cPdfBody);
        $this->assertStringNotContainsString('Omitted Follow-Up', $cPdfBody);
    }

    public function test_export_rejects_empty_or_unrecognized_field_builder_selections_with_validation_error(): void
    {
        $admin = User::factory()->admin()->create();
        $event = FbEvent::factory()->create(['name' => 'Validation Guard Event']);
        Feedback::factory()->create([
            'event_id' => $event->id,
            'schema' => [
                'fields' => [
                    ['id' => 'q1', 'particular' => 'Prompt', 'type' => 'text', 'weight' => 1],
                ],
            ],
        ]);

        // Empty `fields=` query parameter
        $this->actingAs($admin)->getJson(route('admin.feedback.submissions.export', [
            'format' => 'csv',
            'event_id' => $event->id,
            'fields' => '',
        ]))->assertStatus(422)->assertJsonValidationErrors(['fields']);

        // Syntactically invalid tokens
        $this->actingAs($admin)->getJson(route('admin.feedback.submissions.export', [
            'format' => 'pdf',
            'event_id' => $event->id,
            'fields' => 'bad-token,another$bad',
        ]))->assertStatus(422)->assertJsonValidationErrors(['fields']);

        // Syntactically valid tokens that do not exist in the resolved catalog for the filtered scope
        $this->actingAs($admin)->getJson(route('admin.feedback.submissions.export', [
            'format' => 'csv',
            'event_id' => $event->id,
            'fields' => 'meta:nonexistent_column,q:999999:missing_field',
        ]))->assertStatus(422)->assertJsonValidationErrors(['fields']);
    }

    public function test_pdf_export_enforces_submission_and_embedded_image_bounds_before_building_report(): void
    {
        $admin = User::factory()->admin()->create();
        $event = FbEvent::factory()->create(['name' => 'Large Scale Exercise']);
        $feedback = Feedback::factory()->create([
            'event_id' => $event->id,
            'status' => Feedback::STATUS_PUBLISHED,
            'schema' => [
                'fields' => [
                    ['id' => 'q_note', 'particular' => 'Observation Note', 'type' => 'text', 'weight' => 1],
                    ['id' => 'q_photo', 'particular' => 'Site Photo', 'type' => 'photo', 'weight' => 2, 'required' => false],
                ],
            ],
        ]);

        /** @var FeedbackSubmissionPersistenceService $persistence */
        $persistence = app(FeedbackSubmissionPersistenceService::class);

        for ($i = 1; $i <= 4; $i++) {
            $persistence->persistSubmission(
                $event,
                $feedback,
                [
                    'name' => "Responder {$i}",
                    'agency' => $i <= 2 ? 'OCD' : 'DILG',
                    'designation' => 'Evaluator',
                ],
                [
                    'q_note' => "Note {$i}",
                    'q_photo' => UploadedFile::fake()->image("evidence-{$i}.jpg", 120, 90),
                ]
            );
        }

        // 1. Exceeding submission ceiling aborts BEFORE `buildReport` is invoked and directs admin to CSV or narrower filters
        $neverCalledBuilder = Mockery::mock(FeedbackSubmissionPdfBuilder::class);
        $neverCalledBuilder->shouldNotReceive('buildReport');

        $this->app->instance(
            FeedbackSubmissionExportService::class,
            new FeedbackSubmissionExportService(
                app(FeedbackSchemaValidator::class),
                $neverCalledBuilder,
                maxPdfSubmissions: 3,
                maxPdfEmbeddedImages: 10
            )
        );

        $subLimitResponse = $this->actingAs($admin)->getJson(route('admin.feedback.submissions.export', [
            'format' => 'pdf',
            'event_id' => $event->id,
            'tab' => 'individual',
        ]));
        $subLimitResponse->assertStatus(422)->assertJsonValidationErrors(['export']);
        $this->assertStringContainsString('CSV', (string) $subLimitResponse->json('errors.export.0'));
        $this->assertStringContainsString('narrower filters', (string) $subLimitResponse->json('errors.export.0'));

        // CSV remains unaffected when PDF submission limit is exceeded
        $csvOk = $this->actingAs($admin)->get(route('admin.feedback.submissions.export', [
            'format' => 'csv',
            'event_id' => $event->id,
            'tab' => 'individual',
        ]));
        $csvOk->assertOk();
        $this->assertCount(5, $this->parseCsvRows($csvOk->streamedContent()));

        // Narrowing filters to 2 matching submissions (`agency=OCD`) brings PDF within the 3-submission cap
        $this->app->instance(
            FeedbackSubmissionExportService::class,
            new FeedbackSubmissionExportService(
                app(FeedbackSchemaValidator::class),
                app(FeedbackSubmissionPdfBuilder::class),
                maxPdfSubmissions: 3,
                maxPdfEmbeddedImages: 10
            )
        );
        $narrowedPdf = $this->actingAs($admin)->get(route('admin.feedback.submissions.export', [
            'format' => 'pdf',
            'event_id' => $event->id,
            'agency' => 'OCD',
            'tab' => 'individual',
        ]));
        $narrowedPdf->assertOk();
        $this->assertStringStartsWith('%PDF-1.4', (string) $narrowedPdf->getContent());

        // 2. Exceeding embedded image ceiling aborts BEFORE `buildReport` is invoked
        $neverCalledForPhotosBuilder = Mockery::mock(FeedbackSubmissionPdfBuilder::class);
        $neverCalledForPhotosBuilder->shouldNotReceive('buildReport');

        $this->app->instance(
            FeedbackSubmissionExportService::class,
            new FeedbackSubmissionExportService(
                app(FeedbackSchemaValidator::class),
                $neverCalledForPhotosBuilder,
                maxPdfSubmissions: 50,
                maxPdfEmbeddedImages: 2
            )
        );

        $imgLimitResponse = $this->actingAs($admin)->getJson(route('admin.feedback.submissions.export', [
            'format' => 'pdf',
            'event_id' => $event->id,
            'tab' => 'individual',
        ]));
        $imgLimitResponse->assertStatus(422)->assertJsonValidationErrors(['export']);
        $this->assertStringContainsString('CSV', (string) $imgLimitResponse->json('errors.export.0'));
        $this->assertStringContainsString('narrower filters', (string) $imgLimitResponse->json('errors.export.0'));

        // Excluding the `photo` field in the Export Field Builder reduces embedded images to 0 and succeeds
        $this->app->instance(
            FeedbackSubmissionExportService::class,
            new FeedbackSubmissionExportService(
                app(FeedbackSchemaValidator::class),
                app(FeedbackSubmissionPdfBuilder::class),
                maxPdfSubmissions: 50,
                maxPdfEmbeddedImages: 2
            )
        );
        $textOnlyPdf = $this->actingAs($admin)->get(route('admin.feedback.submissions.export', [
            'format' => 'pdf',
            'event_id' => $event->id,
            'tab' => 'individual',
            'fields' => "meta:reference_code,q:{$feedback->id}:q_note",
        ]));
        $textOnlyPdf->assertOk();
        $this->assertStringStartsWith('%PDF-1.4', (string) $textOnlyPdf->getContent());
    }
}
