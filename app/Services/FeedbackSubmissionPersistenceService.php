<?php

namespace App\Services;

use App\Models\Agency;
use App\Models\Designation;
use App\Models\FbEvent;
use App\Models\FbParticipant;
use App\Models\FbSubmission;
use App\Models\Feedback;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class FeedbackSubmissionPersistenceService
{
    public const STORAGE_DISK = 'local';

    public const STORAGE_BASE_DIR = 'feedback-submissions';

    public function __construct(
        protected FeedbackSchemaValidator $schemaValidator
    ) {}

    /**
     * Validate answers (including photo uploads), persist participant + submission,
     * store uploaded photos on the private local disk, and clean up newly written files on failure.
     *
     * @param  array<string, mixed>  $participantData
     * @param  array<string, mixed>  $extraSubmissionAttributes
     */
    public function persistSubmission(
        FbEvent $event,
        Feedback $feedback,
        array $participantData,
        mixed $rawAnswers,
        array $extraSubmissionAttributes = [],
        ?Closure $beforeCreateCallback = null
    ): FbSubmission {
        $validatedData = $this->schemaValidator->validateSubmissionData($feedback->schema, $rawAnswers, $event);

        $initialData = [];
        /** @var array<string, UploadedFile> $photoFiles */
        $photoFiles = [];

        foreach ($validatedData as $key => $value) {
            if ($value instanceof UploadedFile) {
                $photoFiles[$key] = $value;
                $initialData[$key] = null;
            } else {
                $initialData[$key] = $value;
            }
        }

        /** @var array<int, string> $storedPaths */
        $storedPaths = [];

        try {
            return DB::transaction(function () use (
                $event,
                $feedback,
                $participantData,
                $initialData,
                $photoFiles,
                $extraSubmissionAttributes,
                $beforeCreateCallback,
                &$storedPaths
            ): FbSubmission {
                if ($beforeCreateCallback !== null) {
                    $beforeCreateCallback();
                }

                $participant = $this->createParticipant($event, $participantData, $initialData);

                $submission = FbSubmission::create(array_merge([
                    'feedback_id' => $feedback->id,
                    'participant_id' => $participant->id,
                    'data' => $initialData,
                ], $extraSubmissionAttributes));

                if (! empty($photoFiles)) {
                    $finalData = $initialData;
                    foreach ($photoFiles as $fieldId => $file) {
                        $finalData[$fieldId] = $this->storeSubmissionPhoto(
                            $feedback->id,
                            $submission->id,
                            $fieldId,
                            $file,
                            $storedPaths
                        );
                    }

                    $submission->data = $finalData;
                    $submission->save();
                }

                return $submission;
            });
        } catch (Throwable $exception) {
            if (! empty($storedPaths)) {
                try {
                    Storage::disk(self::STORAGE_DISK)->delete($storedPaths);
                } catch (Throwable) {
                    // Preserve original exception when cleanup fails.
                }
            }

            throw $exception;
        }
    }

    /**
     * Resolve lookup attributes and create the submission participant record.
     *
     * @param  array<string, mixed>  $pData
     * @param  array<string, mixed>  $scalarAnswers
     */
    protected function createParticipant(FbEvent $event, array $pData, array $scalarAnswers): FbParticipant
    {
        $name = $pData['name'] ?? $this->scalarFallback($scalarAnswers, 'name');
        $agencyInput = $pData['agency'] ?? $this->scalarFallback($scalarAnswers, 'agency');
        $designationInput = $pData['designation'] ?? $this->scalarFallback($scalarAnswers, 'designation');
        $functionInput = $pData['function_id']
            ?? $this->scalarFallback($scalarAnswers, 'function')
            ?? $this->scalarFallback($scalarAnswers, 'function_id');
        $customFunctionInput = $pData['custom_function']
            ?? $this->scalarFallback($scalarAnswers, 'function_other')
            ?? $this->scalarFallback($scalarAnswers, 'custom_function');
        $yearsInDesignation = $pData['years_in_designation'] ?? ($this->scalarFallback($scalarAnswers, 'years_in_designation') ?? 0);
        $location = $pData['location'] ?? $this->scalarFallback($scalarAnswers, 'location');
        $noOfExercises = $pData['no_of_exercises'] ?? ($this->scalarFallback($scalarAnswers, 'no_of_exercises') ?? 0);

        $agencyName = Agency::findOrCreateByName(is_string($agencyInput) ? $agencyInput : null);
        $designationName = Designation::findOrCreateByName(is_string($designationInput) ? $designationInput : null);

        $functionId = null;
        if ($functionInput && is_numeric($functionInput)) {
            $func = $event->functions()->where('fb_functions.id', (int) $functionInput)->first();
            $functionId = $func?->id;
        }

        return FbParticipant::create([
            'name' => is_string($name) && trim($name) !== '' ? $name : null,
            'function_id' => $functionId,
            'custom_function' => is_string($customFunctionInput) ? $customFunctionInput : null,
            'agency' => $agencyName,
            'designation' => $designationName,
            'years_in_designation' => (int) $yearsInDesignation,
            'location' => is_string($location) ? $location : null,
            'no_of_exercises' => (int) $noOfExercises,
        ]);
    }

    /**
     * Store a single validated photo file on the private local disk and return its structured metadata.
     *
     * @param  array<int, string>  $storedPaths
     * @return array{type: string, disk: string, path: string, filename: string, mime_type: string, size: int, original_name: string}
     */
    protected function storeSubmissionPhoto(
        int $feedbackId,
        int $submissionId,
        string $fieldId,
        UploadedFile $file,
        array &$storedPaths
    ): array {
        $mimeType = strtolower((string) $file->getMimeType());
        $extension = match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => throw new RuntimeException("Unsupported photo MIME type '{$mimeType}'."),
        };

        $safeFieldId = preg_replace('/[^a-zA-Z0-9_-]/', '', $fieldId) ?: 'photo';
        $filename = Str::uuid()->toString().'.'.$extension;
        $directory = self::STORAGE_BASE_DIR."/{$feedbackId}/{$submissionId}/{$safeFieldId}";

        $storedPath = Storage::disk(self::STORAGE_DISK)->putFileAs($directory, $file, $filename);
        if (! is_string($storedPath) || $storedPath === '' || ! Storage::disk(self::STORAGE_DISK)->exists($storedPath)) {
            throw new RuntimeException('Failed to store uploaded feedback photo.');
        }

        $storedPaths[] = $storedPath;

        return [
            'type' => 'photo',
            'disk' => self::STORAGE_DISK,
            'path' => $storedPath,
            'filename' => $filename,
            'mime_type' => $mimeType,
            'size' => (int) $file->getSize(),
            'original_name' => $this->sanitizeOriginalFilename($file->getClientOriginalName(), $extension),
        ];
    }

    /**
     * Sanitize client original filename for display metadata only (never used for storage paths).
     */
    protected function sanitizeOriginalFilename(string $rawOriginalName, string $fallbackExtension): string
    {
        $base = basename(str_replace('\\', '/', trim($rawOriginalName)));
        $cleaned = preg_replace('/[^a-zA-Z0-9._ -]/', '_', $base) ?? '';
        $cleaned = trim($cleaned, '. _-');

        if ($cleaned === '') {
            return 'photo.'.$fallbackExtension;
        }

        return Str::limit($cleaned, 120, '');
    }

    /**
     * Return a scalar answer fallback or null when the answer is non-scalar.
     *
     * @param  array<string, mixed>  $answers
     */
    protected function scalarFallback(array $answers, string $key): string|int|float|null
    {
        $value = $answers[$key] ?? null;

        if (is_string($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        return null;
    }
}
