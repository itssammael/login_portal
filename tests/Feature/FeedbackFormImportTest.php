<?php

namespace Tests\Feature;

use App\Models\FbEvent;
use App\Models\User;
use App\Services\FeedbackSchemaValidator;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;
use ZipArchive;

class FeedbackFormImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_non_admin_cannot_import_form(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $file = UploadedFile::fake()->create('survey.docx', 100);

        $response = $this->actingAs($user)->postJson(route('admin.feedback.forms.import'), [
            'file' => $file,
        ]);

        $response->assertForbidden();
    }

    public function test_guest_cannot_import_form(): void
    {
        $file = UploadedFile::fake()->create('survey.docx', 100);

        $response = $this->postJson(route('admin.feedback.forms.import'), [
            'file' => $file,
        ]);

        $response->assertUnauthorized();
    }

    public function test_file_is_required_for_import(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->postJson(route('admin.feedback.forms.import'), []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }

    public function test_unsupported_file_extension_is_rejected(): void
    {
        $admin = User::factory()->admin()->create();

        $file = UploadedFile::fake()->create('malicious.exe', 100);

        $response = $this->actingAs($admin)->postJson(route('admin.feedback.forms.import'), [
            'file' => $file,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }

    public function test_oversized_file_is_rejected(): void
    {
        $admin = User::factory()->admin()->create();

        // 12MB is over 10MB limit
        $file = UploadedFile::fake()->create('huge_survey.docx', 12288);

        $response = $this->actingAs($admin)->postJson(route('admin.feedback.forms.import'), [
            'file' => $file,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }

    public function test_admin_can_import_and_extract_docx_questionnaire(): void
    {
        $admin = User::factory()->admin()->create();
        $event = FbEvent::factory()->create();

        $tempPath = tempnam(sys_get_temp_dir(), 'docx_test_').'.docx';
        $zip = new ZipArchive;
        $zip->open($tempPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $documentXml = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
    <w:body>
        <w:p><w:r><w:t>1. How satisfied were you with the activity? *</w:t></w:r></w:p>
        <w:p><w:r><w:t>☐ Very Satisfied</w:t></w:r></w:p>
        <w:p><w:r><w:t>☐ Satisfied</w:t></w:r></w:p>
        <w:p><w:r><w:t>☐ Neutral</w:t></w:r></w:p>
        <w:p><w:r><w:t>☐ Dissatisfied</w:t></w:r></w:p>
        <w:p><w:r><w:t>☐ Very Dissatisfied</w:t></w:r></w:p>
        <w:p><w:r><w:t>2. Please provide comments or recommendations for improvement:</w:t></w:r></w:p>
    </w:body>
</w:document>
XML;
        $zip->addFromString('word/document.xml', $documentXml);
        $zip->close();

        $uploadedFile = new UploadedFile($tempPath, 'evaluation.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', null, true);

        $response = $this->actingAs($admin)->postJson(route('admin.feedback.forms.import'), [
            'file' => $uploadedFile,
            'event_id' => $event->id,
        ]);

        if (file_exists($tempPath)) {
            unlink($tempPath);
        }

        $response->assertOk()
            ->assertJson([
                'status' => 'success',
                'file_type' => 'docx',
            ])
            ->assertJsonStructure([
                'status',
                'filename',
                'file_type',
                'file_size',
                'summary' => [
                    'total_detected',
                    'high_confidence',
                    'medium_confidence',
                    'low_confidence',
                ],
                'candidates',
            ]);

        $candidates = $response->json('candidates');
        $this->assertCount(2, $candidates);

        // Question 1: Satisfaction rating
        $q1 = $candidates[0];
        $this->assertStringContainsString('How satisfied were you with the activity', $q1['particular']);
        $this->assertEquals('radio', $q1['type']);
        $this->assertTrue($q1['required']);
        $this->assertCount(5, $q1['options']);
        $this->assertEquals('very_satisfied', $q1['options'][0]['value']);
        $this->assertEquals('high', $q1['confidence']['level']);

        // Question 2: Comments textarea
        $q2 = $candidates[1];
        $this->assertStringContainsString('comments or recommendations', $q2['particular']);
        $this->assertEquals('textarea', $q2['type']);
    }

    public function test_admin_can_import_docx_rating_matrix_table(): void
    {
        $admin = User::factory()->admin()->create();

        $tempPath = tempnam(sys_get_temp_dir(), 'docx_tbl_').'.docx';
        $zip = new ZipArchive;
        $zip->open($tempPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $documentXml = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
    <w:body>
        <w:tbl>
            <w:tr>
                <w:tc><w:p><w:r><w:t>Criteria</w:t></w:r></w:p></w:tc>
                <w:tc><w:p><w:r><w:t>Excellent</w:t></w:r></w:p></w:tc>
                <w:tc><w:p><w:r><w:t>Good</w:t></w:r></w:p></w:tc>
                <w:tc><w:p><w:r><w:t>Fair</w:t></w:r></w:p></w:tc>
                <w:tc><w:p><w:r><w:t>Poor</w:t></w:r></w:p></w:tc>
            </w:tr>
            <w:tr>
                <w:tc><w:p><w:r><w:t>Communication &amp; Clarity</w:t></w:r></w:p></w:tc>
                <w:tc><w:p><w:r><w:t>○</w:t></w:r></w:p></w:tc>
                <w:tc><w:p><w:r><w:t>○</w:t></w:r></w:p></w:tc>
                <w:tc><w:p><w:r><w:t>○</w:t></w:r></w:p></w:tc>
                <w:tc><w:p><w:r><w:t>○</w:t></w:r></w:p></w:tc>
            </w:tr>
            <w:tr>
                <w:tc><w:p><w:r><w:t>Preparedness &amp; Punctuality</w:t></w:r></w:p></w:tc>
                <w:tc><w:p><w:r><w:t>○</w:t></w:r></w:p></w:tc>
                <w:tc><w:p><w:r><w:t>○</w:t></w:r></w:p></w:tc>
                <w:tc><w:p><w:r><w:t>○</w:t></w:r></w:p></w:tc>
                <w:tc><w:p><w:r><w:t>○</w:t></w:r></w:p></w:tc>
            </w:tr>
        </w:tbl>
    </w:body>
</w:document>
XML;
        $zip->addFromString('word/document.xml', $documentXml);
        $zip->close();

        $uploadedFile = new UploadedFile($tempPath, 'matrix.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', null, true);

        $response = $this->actingAs($admin)->postJson(route('admin.feedback.forms.import'), [
            'file' => $uploadedFile,
        ]);

        if (file_exists($tempPath)) {
            unlink($tempPath);
        }

        $response->assertOk();
        $candidates = $response->json('candidates');

        $this->assertCount(2, $candidates);
        $this->assertEquals('Communication & Clarity', $candidates[0]['particular']);
        $this->assertEquals('radio', $candidates[0]['type']);
        $this->assertCount(4, $candidates[0]['options']);
        $this->assertEquals('excellent', $candidates[0]['options'][0]['value']);
        $this->assertEquals('table_matrix', $candidates[0]['source']['type']);

        $this->assertEquals('Preparedness & Punctuality', $candidates[1]['particular']);
        $this->assertEquals('radio', $candidates[1]['type']);
        $this->assertCount(4, $candidates[1]['options']);
    }

    public function test_admin_can_import_csv_spreadsheet_matrix(): void
    {
        $admin = User::factory()->admin()->create();

        $csvContent = <<<'CSV'
Evaluation Question,Strongly Agree,Agree,Disagree,Strongly Disagree
The objectives of the drill were clearly met.,1,2,3,4
The scenario was realistic and challenging.,1,2,3,4
CSV;

        $tempPath = tempnam(sys_get_temp_dir(), 'csv_test_').'.csv';
        file_put_contents($tempPath, $csvContent);

        $uploadedFile = new UploadedFile($tempPath, 'evaluation.csv', 'text/csv', null, true);

        $response = $this->actingAs($admin)->postJson(route('admin.feedback.forms.import'), [
            'file' => $uploadedFile,
        ]);

        if (file_exists($tempPath)) {
            unlink($tempPath);
        }

        $response->assertOk()
            ->assertJson([
                'status' => 'success',
                'file_type' => 'csv',
            ]);

        $candidates = $response->json('candidates');
        $this->assertCount(2, $candidates);
        $this->assertEquals('The objectives of the drill were clearly met.', $candidates[0]['particular']);
        $this->assertEquals('radio', $candidates[0]['type']);
        $this->assertCount(4, $candidates[0]['options']);
        $this->assertEquals('strongly_agree', $candidates[0]['options'][0]['value']);
    }

    public function test_admin_can_import_xlsx_spreadsheet(): void
    {
        $admin = User::factory()->admin()->create();

        $tempPath = tempnam(sys_get_temp_dir(), 'xlsx_test_').'.xlsx';
        $zip = new ZipArchive;
        $zip->open($tempPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $sharedStringsXml = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="6" uniqueCount="6">
    <si><t>Question</t></si>
    <si><t>Yes</t></si>
    <si><t>No</t></si>
    <si><t>Did the event start on time?</t></si>
    <si><t>Were refreshments adequate?</t></si>
</sst>
XML;

        $sheet1Xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
    <sheetData>
        <row r="1">
            <c r="A1" t="s"><v>0</v></c>
            <c r="B1" t="s"><v>1</v></c>
            <c r="C1" t="s"><v>2</v></c>
        </row>
        <row r="2">
            <c r="A2" t="s"><v>3</v></c>
            <c r="B2"><v>1</v></c>
            <c r="C2"><v>0</v></c>
        </row>
        <row r="3">
            <c r="A3" t="s"><v>4</v></c>
            <c r="B3"><v>1</v></c>
            <c r="C3"><v>0</v></c>
        </row>
    </sheetData>
</worksheet>
XML;

        $zip->addFromString('xl/sharedStrings.xml', $sharedStringsXml);
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet1Xml);
        $zip->close();

        $uploadedFile = new UploadedFile($tempPath, 'survey.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $response = $this->actingAs($admin)->postJson(route('admin.feedback.forms.import'), [
            'file' => $uploadedFile,
        ]);

        if (file_exists($tempPath)) {
            unlink($tempPath);
        }

        $response->assertOk()
            ->assertJson([
                'status' => 'success',
                'file_type' => 'xlsx',
            ]);

        $candidates = $response->json('candidates');
        $this->assertCount(2, $candidates);
        $this->assertEquals('Did the event start on time?', $candidates[0]['particular']);
        $this->assertEquals('radio', $candidates[0]['type']);
        $this->assertCount(2, $candidates[0]['options']);
        $this->assertEquals('yes', $candidates[0]['options'][0]['value']);
        $this->assertEquals('no', $candidates[0]['options'][1]['value']);
    }

    public function test_admin_can_import_pdf_questionnaire(): void
    {
        $admin = User::factory()->admin()->create();

        $pdfStream = <<<'PDF'
%PDF-1.4
1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj
2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj
3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R >> endobj
4 0 obj << /Length 200 >> stream
BT
/F1 12 Tf
(1. How would you rate overall coordination?) Tj
T*
(Excellent) Tj
T*
(Good) Tj
T*
(Fair) Tj
T*
(Poor) Tj
ET
endstream endobj
xref
0 5
0000000000 65535 f
0000000010 00000 n
0000000060 00000 n
0000000119 00000 n
0000000213 00000 n
trailer << /Size 5 /Root 1 0 R >>
startxref
420
%%EOF
PDF;

        $tempPath = tempnam(sys_get_temp_dir(), 'pdf_test_').'.pdf';
        file_put_contents($tempPath, $pdfStream);

        $uploadedFile = new UploadedFile($tempPath, 'evaluation.pdf', 'application/pdf', null, true);

        $response = $this->actingAs($admin)->postJson(route('admin.feedback.forms.import'), [
            'file' => $uploadedFile,
        ]);

        if (file_exists($tempPath)) {
            unlink($tempPath);
        }

        $response->assertOk()
            ->assertJson([
                'status' => 'success',
                'file_type' => 'pdf',
            ]);

        $candidates = $response->json('candidates');
        $this->assertNotEmpty($candidates);
        $this->assertStringContainsString('coordination', $candidates[0]['particular']);
        $this->assertEquals('radio', $candidates[0]['type']);
        $this->assertCount(4, $candidates[0]['options']);
    }

    public function test_imported_candidates_pass_feedback_schema_validator(): void
    {
        $admin = User::factory()->admin()->create();

        $csvContent = <<<'CSV'
Criteria,Very Satisfied,Satisfied,Neutral,Dissatisfied,Very Dissatisfied
Instructor Knowledge,1,2,3,4,5
Material Relevance,1,2,3,4,5
CSV;

        $tempPath = tempnam(sys_get_temp_dir(), 'val_test_').'.csv';
        file_put_contents($tempPath, $csvContent);
        $uploadedFile = new UploadedFile($tempPath, 'eval.csv', 'text/csv', null, true);

        $response = $this->actingAs($admin)->postJson(route('admin.feedback.forms.import'), [
            'file' => $uploadedFile,
        ]);

        if (file_exists($tempPath)) {
            unlink($tempPath);
        }

        $candidates = $response->json('candidates');

        /** @var FeedbackSchemaValidator $validator */
        $validator = app(FeedbackSchemaValidator::class);

        // Validate that schema candidates match the application schema structure and pass validation
        $validated = $validator->validateSchema(['fields' => $candidates]);

        $this->assertArrayHasKey('fields', $validated);
        $this->assertCount(2, $validated['fields']);
        $this->assertEquals('instructor_knowledge', $validated['fields'][0]['id']);
        $this->assertEquals('radio', $validated['fields'][0]['type']);
    }
}
