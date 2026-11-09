<?php

namespace App\Services;

use App\Models\FbEvent;
use App\Models\FbFunction;
use App\Models\FbSubmission;
use App\Models\Feedback;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FeedbackSubmissionExportService
{
    /**
     * Ordered registry of submission & participant metadata fields available in the Export Field Builder.
     *
     * @var array<string, string>
     */
    public const METADATA_FIELD_DEFINITIONS = [
        'reference_code' => 'Reference Code',
        'event' => 'Event',
        'participant_name' => 'Participant Name',
        'function' => 'Function',
        'agency' => 'Agency',
        'designation' => 'Designation',
        'years_in_designation' => 'Years in Designation',
        'location' => 'Location',
        'no_of_exercises' => 'No. of Exercises',
        'submitted_at' => 'Submitted At',
    ];

    /**
     * Fixed submission & participant metadata column headers in CSV exports.
     *
     * @var array<int, string>
     */
    public const CSV_METADATA_HEADERS = [
        'Reference Code',
        'Event',
        'Participant Name',
        'Function',
        'Agency',
        'Designation',
        'Years in Designation',
        'Location',
        'No. of Exercises',
        'Submitted At',
    ];

    /**
     * Maximum number of submissions allowed in a single in-memory PDF export report.
     */
    public const MAX_PDF_SUBMISSIONS = 500;

    /**
     * Maximum number of embedded raster images allowed in a single PDF export report.
     */
    public const MAX_PDF_EMBEDDED_IMAGES = 100;

    /**
     * Cache of resolved answerable schema fields keyed by `feedback_id`.
     *
     * @var array<int, array<int, array<string, mixed>>>
     */
    protected array $resolvedFieldsCache = [];

    public function __construct(
        protected FeedbackSchemaValidator $schemaValidator,
        protected FeedbackSubmissionPdfBuilder $pdfBuilder,
        protected int $maxPdfSubmissions = self::MAX_PDF_SUBMISSIONS,
        protected int $maxPdfEmbeddedImages = self::MAX_PDF_EMBEDDED_IMAGES
    ) {}

    /**
     * Build the base filtered submissions query shared by both the Inertia listing and exports.
     *
     * @param  array{
     *     event_id?: int|null,
     *     function_id?: int|null,
     *     agency?: string|null,
     *     designation?: string|null,
     *     search?: string|null,
     *     tab?: string
     * }  $filters
     * @return Builder<FbSubmission>
     */
    public function buildFilteredQuery(array $filters, bool $includeIndividualSearch = true): Builder
    {
        $query = FbSubmission::query();

        if (! empty($filters['event_id'])) {
            $eventId = (int) $filters['event_id'];
            $query->whereHas('feedback', fn (Builder $q) => $q->where('event_id', $eventId));
        }

        if (! empty($filters['function_id'])) {
            $functionId = (int) $filters['function_id'];
            $query->whereHas('participant', fn (Builder $q) => $q->where('function_id', $functionId));
        }

        if (isset($filters['agency']) && $filters['agency'] !== '') {
            $agency = (string) $filters['agency'];
            $query->whereHas('participant', fn (Builder $q) => $q->where('agency', $agency));
        }

        if (isset($filters['designation']) && $filters['designation'] !== '') {
            $designation = (string) $filters['designation'];
            $query->whereHas('participant', fn (Builder $q) => $q->where('designation', $designation));
        }

        $activeTab = $filters['tab'] ?? 'individual';
        $search = isset($filters['search']) ? (string) $filters['search'] : '';

        if ($includeIndividualSearch && $activeTab === 'individual' && $search !== '') {
            $escapedSearch = addcslashes($search, '%_\\');
            $likeTerm = "%{$escapedSearch}%";

            $query->where(function (Builder $subQuery) use ($likeTerm): void {
                $subQuery->where('reference_code', 'like', $likeTerm)
                    ->orWhereHas('participant', function (Builder $pQuery) use ($likeTerm): void {
                        $pQuery->where('name', 'like', $likeTerm)
                            ->orWhere('agency', 'like', $likeTerm)
                            ->orWhere('designation', 'like', $likeTerm)
                            ->orWhere('location', 'like', $likeTerm);
                    });
            });
        }

        return $query;
    }

    /**
     * Attach export/listing eager-loads and chronological ordering.
     *
     * @param  Builder<FbSubmission>  $query
     * @return Builder<FbSubmission>
     */
    public function withSubmissionRelations(Builder $query): Builder
    {
        return $query->with([
            'feedback.event' => fn ($q) => $q->withTrashed(),
            'participant.function',
        ])->latest('created_at')->orderByDesc('id');
    }

    /**
     * Compile question-level analytics for an event against a filtered submission query.
     *
     * @param  Builder<FbSubmission>  $filteredQuery
     * @return array{
     *     compiled_responses: array<int, array<string, mixed>>|null,
     *     meta: array{event_id: int, event_name: string, total_filtered_submissions: int, has_schema: bool}
     * }
     */
    public function buildCompiledAnalytics(int $eventId, Builder $filteredQuery): array
    {
        $event = FbEvent::withTrashed()->find($eventId);
        $feedback = Feedback::where('event_id', $eventId)->first();
        $totalFilteredSubmissions = (clone $filteredQuery)->count();

        $compiledResponses = null;

        if ($event && $feedback && is_array($feedback->schema)) {
            $resolvedSchema = $this->schemaValidator->resolveSchemaForEvent($feedback->schema, $event);
            $fields = $resolvedSchema['fields'] ?? [];
            $allSubmissionsData = (clone $filteredQuery)->pluck('data');

            $compiledQuestions = [];

            foreach ($fields as $field) {
                $type = $field['type'] ?? 'text';
                if (in_array($type, ['section', 'text_only'], true)) {
                    continue;
                }

                $fieldId = (string) ($field['id'] ?? '');
                $particular = (string) ($field['particular'] ?? $fieldId);
                $rawOptions = $field['options'] ?? [];

                $isChoice = in_array($type, ['radio', 'select', 'checkbox'], true);
                $isCheckbox = $type === 'checkbox';

                if ($isChoice) {
                    $optionsMap = [];
                    foreach ($rawOptions as $opt) {
                        $val = (string) ($opt['value'] ?? '');
                        $lbl = (string) ($opt['label'] ?? $val);
                        $optionsMap[$val] = [
                            'value' => $val,
                            'label' => $lbl,
                            'count' => 0,
                            'percentage' => 0.0,
                        ];
                    }

                    $answeredCount = 0;

                    foreach ($allSubmissionsData as $submissionData) {
                        if (! is_array($submissionData) || ! array_key_exists($fieldId, $submissionData)) {
                            continue;
                        }

                        $val = $submissionData[$fieldId];

                        if ($isCheckbox) {
                            if (is_array($val) && count($val) > 0) {
                                $answeredCount++;
                                foreach ($val as $selectedOpt) {
                                    $strVal = (string) $selectedOpt;
                                    if (! isset($optionsMap[$strVal])) {
                                        $optionsMap[$strVal] = [
                                            'value' => $strVal,
                                            'label' => $strVal,
                                            'count' => 0,
                                            'percentage' => 0.0,
                                        ];
                                    }
                                    $optionsMap[$strVal]['count']++;
                                }
                            }
                        } elseif ($val !== null && $val !== '') {
                            $answeredCount++;
                            $strVal = (string) $val;
                            if (! isset($optionsMap[$strVal])) {
                                $optionsMap[$strVal] = [
                                    'value' => $strVal,
                                    'label' => $strVal,
                                    'count' => 0,
                                    'percentage' => 0.0,
                                ];
                            }
                            $optionsMap[$strVal]['count']++;
                        }
                    }

                    $optionsList = array_values($optionsMap);
                    foreach ($optionsList as &$optItem) {
                        $optItem['percentage'] = $answeredCount > 0
                            ? round(($optItem['count'] / $answeredCount) * 100, 1)
                            : 0.0;
                    }
                    unset($optItem);

                    $compiledQuestions[] = [
                        'id' => $fieldId,
                        'particular' => $particular,
                        'type' => $type,
                        'is_choice' => true,
                        'is_photo' => false,
                        'is_multiselect' => $isCheckbox,
                        'total_submissions' => $totalFilteredSubmissions,
                        'answered_count' => $answeredCount,
                        'unanswered_count' => max(0, $totalFilteredSubmissions - $answeredCount),
                        'options' => $optionsList,
                    ];
                } elseif ($type === 'photo') {
                    $answeredCount = 0;
                    foreach ($allSubmissionsData as $submissionData) {
                        if (is_array($submissionData) && array_key_exists($fieldId, $submissionData)) {
                            $val = $submissionData[$fieldId];
                            if (is_array($val) && ($val['type'] ?? null) === 'photo' && ! empty($val['path'])) {
                                $answeredCount++;
                            }
                        }
                    }

                    $compiledQuestions[] = [
                        'id' => $fieldId,
                        'particular' => $particular,
                        'type' => $type,
                        'is_choice' => false,
                        'is_photo' => true,
                        'is_multiselect' => false,
                        'total_submissions' => $totalFilteredSubmissions,
                        'answered_count' => $answeredCount,
                        'unanswered_count' => max(0, $totalFilteredSubmissions - $answeredCount),
                        'options' => [],
                    ];
                } else {
                    $answeredCount = 0;
                    foreach ($allSubmissionsData as $submissionData) {
                        if (is_array($submissionData) && array_key_exists($fieldId, $submissionData)) {
                            $val = $submissionData[$fieldId];
                            if ($val !== null && $val !== '' && (! is_array($val) || count($val) > 0)) {
                                $answeredCount++;
                            }
                        }
                    }

                    $compiledQuestions[] = [
                        'id' => $fieldId,
                        'particular' => $particular,
                        'type' => $type,
                        'is_choice' => false,
                        'is_photo' => false,
                        'is_multiselect' => false,
                        'total_submissions' => $totalFilteredSubmissions,
                        'answered_count' => $answeredCount,
                        'unanswered_count' => max(0, $totalFilteredSubmissions - $answeredCount),
                        'options' => [],
                    ];
                }
            }

            $compiledResponses = $compiledQuestions;
        }

        return [
            'compiled_responses' => $compiledResponses,
            'meta' => [
                'event_id' => $event?->id ?? $eventId,
                'event_name' => $event?->name ?? 'Unknown Event',
                'total_filtered_submissions' => $totalFilteredSubmissions,
                'has_schema' => ! empty($feedback?->schema),
            ],
        ];
    }

    /**
     * Return the available metadata and questionnaire export fields for the Export Field Builder UI.
     *
     * @param  array<string, mixed>  $filters
     * @param  Builder<FbSubmission>|null  $filteredQuery
     * @return array{
     *     metadata: array<int, array{token: string, key: string, label: string}>,
     *     questions: array<int, array{
     *         token: string,
     *         feedback_id: int,
     *         field_id: string,
     *         label: string,
     *         prompt: string,
     *         type: string,
     *         event_name: string
     *     }>
     * }
     */
    public function getAvailableExportFields(array $filters, ?Builder $filteredQuery = null): array
    {
        $this->resolvedFieldsCache = [];

        $metadata = [];
        foreach (self::METADATA_FIELD_DEFINITIONS as $key => $label) {
            $metadata[] = [
                'token' => 'meta:'.$key,
                'key' => $key,
                'label' => $label,
            ];
        }

        if (($filters['tab'] ?? 'individual') === 'compiled' && empty($filters['event_id'])) {
            return [
                'metadata' => $metadata,
                'questions' => [],
            ];
        }

        $query = $filteredQuery ?? $this->buildFilteredQuery($filters);
        $questionColumns = $this->resolveCsvQuestionColumns($filters, $query);

        $questions = [];
        foreach ($questionColumns as $col) {
            $field = $col['field'];
            $fieldId = (string) ($field['id'] ?? '');
            if ($fieldId === '') {
                continue;
            }

            $questions[] = [
                'token' => sprintf('q:%d:%s', $col['feedback_id'], $fieldId),
                'feedback_id' => $col['feedback_id'],
                'field_id' => $fieldId,
                'label' => $col['header'],
                'prompt' => $this->cleanQuestionLabel((string) ($field['particular'] ?? $fieldId)),
                'type' => (string) ($field['type'] ?? 'text'),
                'event_name' => $col['event_name'],
            ];
        }

        return [
            'metadata' => $metadata,
            'questions' => $questions,
        ];
    }

    /**
     * Stream an Excel-compatible UTF-8 BOM CSV containing all filtered submissions for the selected fields.
     *
     * @param  array{
     *     event_id: int|null,
     *     function_id: int|null,
     *     agency: string|null,
     *     designation: string|null,
     *     search: string|null,
     *     tab: string
     * }  $filters
     * @param  array<int, string>|null  $selectedTokens
     */
    public function streamCsv(array $filters, ?array $selectedTokens = null): StreamedResponse
    {
        $this->resolvedFieldsCache = [];
        $filteredQuery = $this->buildFilteredQuery($filters);
        $plan = $this->resolveRequestedExportPlan($filters, $filteredQuery, $selectedTokens);
        $orderedColumns = $plan['ordered_items'];
        $filename = $this->buildExportFilename($filters, 'csv');

        return response()->streamDownload(function () use ($filteredQuery, $orderedColumns): void {
            $handle = fopen('php://output', 'wb');
            if ($handle === false) {
                return;
            }

            // Write UTF-8 Byte Order Mark so Microsoft Excel opens UTF-8 characters properly
            fwrite($handle, "\xEF\xBB\xBF");

            $headerRow = array_map(
                fn (array $item): string => $this->sanitizeCsvCell($item['header']),
                $orderedColumns
            );
            fputcsv($handle, $headerRow, ',', '"', '');

            $this->withSubmissionRelations(clone $filteredQuery)
                ->chunk(200, function ($submissionsChunk) use ($handle, $orderedColumns): void {
                    foreach ($submissionsChunk as $submission) {
                        $row = $this->buildOrderedCsvSubmissionRow($submission, $orderedColumns);
                        fputcsv($handle, $row, ',', '"', '');
                    }

                    if (function_exists('fflush')) {
                        @fflush($handle);
                    }
                });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }

    /**
     * Generate and return a downloadable PDF 1.4 report for all matching submissions and selected fields.
     *
     * @param  array{
     *     event_id: int|null,
     *     function_id: int|null,
     *     agency: string|null,
     *     designation: string|null,
     *     search: string|null,
     *     tab: string
     * }  $filters
     * @param  array<int, string>|null  $selectedTokens
     */
    public function downloadPdf(array $filters, ?array $selectedTokens = null): Response
    {
        $this->resolvedFieldsCache = [];
        $filteredQuery = $this->buildFilteredQuery($filters);
        $plan = $this->resolveRequestedExportPlan($filters, $filteredQuery, $selectedTokens);
        $totalMatching = (clone $filteredQuery)->count();

        $this->ensurePdfExportWithinLimits($filteredQuery, $plan, $totalMatching);

        $filename = $this->buildExportFilename($filters, 'pdf');

        $event = ! empty($filters['event_id'])
            ? FbEvent::withTrashed()->find((int) $filters['event_id'])
            : null;

        $activeTab = $filters['tab'] ?? 'individual';
        $isCompiled = $activeTab === 'compiled';

        $compiledQuestionsForPdf = null;
        if ($isCompiled && $event !== null) {
            $compiled = $this->buildCompiledAnalytics($event->id, clone $filteredQuery);
            if (is_array($compiled['compiled_responses'])) {
                $rawCompiledList = $compiled['compiled_responses'];

                if ($plan['is_custom_selection']) {
                    $byFieldId = [];
                    foreach ($rawCompiledList as $q) {
                        $byFieldId[(string) ($q['id'] ?? '')] = $q;
                    }

                    $filteredCompiled = [];
                    foreach ($plan['question_items'] as $qItem) {
                        $fId = $qItem['field_id'];
                        if (isset($byFieldId[$fId])) {
                            $filteredCompiled[] = $byFieldId[$fId];
                        }
                    }
                    $rawCompiledList = $filteredCompiled;
                }

                if (! empty($rawCompiledList)) {
                    $compiledQuestionsForPdf = array_map(function (array $q): array {
                        $options = [];
                        if (is_array($q['options'] ?? null)) {
                            foreach ($q['options'] as $opt) {
                                $options[] = [
                                    'label' => $this->cleanQuestionLabel((string) ($opt['label'] ?? '')),
                                    'count' => (int) ($opt['count'] ?? 0),
                                    'percentage' => (float) ($opt['percentage'] ?? 0.0),
                                ];
                            }
                        }

                        return [
                            'particular' => $this->cleanQuestionLabel((string) ($q['particular'] ?? '')),
                            'type' => (string) ($q['type'] ?? 'text'),
                            'answered_count' => (int) ($q['answered_count'] ?? 0),
                            'unanswered_count' => (int) ($q['unanswered_count'] ?? 0),
                            'total_submissions' => (int) ($q['total_submissions'] ?? 0),
                            'is_choice' => (bool) ($q['is_choice'] ?? false),
                            'options' => $options,
                        ];
                    }, $rawCompiledList);
                }
            }
        }

        $filterSummary = $this->describeActiveFilters($filters, $event);
        if ($plan['is_custom_selection']) {
            $filterSummary['Fields Included'] = sprintf(
                '%d of %d selected',
                count($plan['ordered_items']),
                $plan['total_available_count']
            );
        }

        $reportMeta = [
            'title' => $isCompiled
                ? 'Compiled Feedback Submissions Report'
                : 'Feedback Submissions Export Report',
            'subtitle' => $event !== null
                ? 'Event: '.$event->name.($event->trashed() ? ' (Archived)' : '')
                : 'Event Scope: All Events',
            'generated_at' => now()->format('Y-m-d H:i:s'),
            'total_submissions' => $totalMatching,
            'active_tab' => $activeTab,
            'filter_summary' => $filterSummary,
            'compiled_questions' => $compiledQuestionsForPdf,
        ];

        $submissionCardsGenerator = (function () use ($filteredQuery, $plan): \Generator {
            $index = 0;
            $embeddedImagesCount = 0;

            foreach ($this->withSubmissionRelations(clone $filteredQuery)->lazy(100) as $submission) {
                $index++;
                if ($index > $this->maxPdfSubmissions) {
                    $this->throwPdfSubmissionsLimitExceeded($index);
                }

                $card = $this->buildPdfSubmissionCard($submission, $index, $plan);

                foreach ($card['answers'] as $answer) {
                    if (($answer['type'] ?? null) === 'photo' && ! empty($answer['photo']['raw_bytes'])) {
                        $embeddedImagesCount++;
                        if ($embeddedImagesCount > $this->maxPdfEmbeddedImages) {
                            $this->throwPdfEmbeddedImagesLimitExceeded();
                        }
                    }
                }

                yield $card;
            }
        })();

        $pdfBinary = $this->pdfBuilder->buildReport($reportMeta, $submissionCardsGenerator);

        return response($pdfBinary, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Content-Length' => (string) strlen($pdfBinary),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }

    /**
     * Enforce server-side bounds on matching submissions and embeddable photos before building the in-memory PDF.
     *
     * @param  Builder<FbSubmission>  $filteredQuery
     * @param  array<string, mixed>  $plan
     */
    protected function ensurePdfExportWithinLimits(Builder $filteredQuery, array $plan, int $totalMatching): void
    {
        if ($totalMatching > $this->maxPdfSubmissions) {
            $this->throwPdfSubmissionsLimitExceeded($totalMatching);
        }

        if ($totalMatching === 0) {
            return;
        }

        $isCustomSelection = ! empty($plan['is_custom_selection']);
        /** @var array<int, array<string, mixed>> $selectedQuestionItems */
        $selectedQuestionItems = is_array($plan['question_items'] ?? null) ? $plan['question_items'] : [];

        if ($isCustomSelection && count($selectedQuestionItems) === 0) {
            return;
        }

        /** @var array<int, array<int, array<string, mixed>>> $photoFieldsByFeedbackId */
        $photoFieldsByFeedbackId = [];

        if ($isCustomSelection) {
            foreach ($selectedQuestionItems as $qItem) {
                if ((string) ($qItem['field']['type'] ?? '') === 'photo') {
                    $fbId = (int) $qItem['feedback_id'];
                    $photoFieldsByFeedbackId[$fbId][] = $qItem['field'];
                }
            }
        } else {
            $feedbackIds = (clone $filteredQuery)
                ->select('feedback_id')
                ->distinct()
                ->pluck('feedback_id')
                ->filter()
                ->map(fn ($id): int => (int) $id)
                ->values()
                ->all();

            if (! empty($feedbackIds)) {
                $feedbacks = Feedback::with(['event' => fn ($q) => $q->withTrashed()])
                    ->whereIn('id', $feedbackIds)
                    ->get();

                foreach ($feedbacks as $fb) {
                    foreach ($this->getResolvedAnswerableFields($fb) as $field) {
                        if ((string) ($field['type'] ?? '') === 'photo') {
                            $photoFieldsByFeedbackId[(int) $fb->id][] = $field;
                        }
                    }
                }
            }
        }

        if (empty($photoFieldsByFeedbackId)) {
            return;
        }

        $embeddableImagesCount = 0;
        $photoSubmissionsQuery = (clone $filteredQuery)
            ->whereIn('feedback_id', array_keys($photoFieldsByFeedbackId))
            ->select(['id', 'feedback_id', 'data']);

        foreach ($photoSubmissionsQuery->lazy(200) as $submission) {
            $fbId = (int) $submission->feedback_id;
            foreach ($photoFieldsByFeedbackId[$fbId] ?? [] as $photoField) {
                if ($this->hasEmbeddablePhotoOnDisk($submission, $photoField)) {
                    $embeddableImagesCount++;
                    if ($embeddableImagesCount > $this->maxPdfEmbeddedImages) {
                        $this->throwPdfEmbeddedImagesLimitExceeded();
                    }
                }
            }
        }
    }

    /**
     * Throw a validation exception when matching submissions exceed the safe PDF export ceiling.
     */
    protected function throwPdfSubmissionsLimitExceeded(int $matchingCount): never
    {
        throw ValidationException::withMessages([
            'export' => sprintf(
                'PDF export is limited to %d submissions per report (found %d matching submissions). Please export as CSV (Excel) or apply narrower filters.',
                $this->maxPdfSubmissions,
                $matchingCount
            ),
        ]);
    }

    /**
     * Throw a validation exception when embedded photos exceed the safe PDF export ceiling.
     */
    protected function throwPdfEmbeddedImagesLimitExceeded(): never
    {
        throw ValidationException::withMessages([
            'export' => sprintf(
                'PDF export is limited to %d embedded images per report. Please export as CSV (Excel), apply narrower filters, or exclude photo fields.',
                $this->maxPdfEmbeddedImages
            ),
        ]);
    }

    /**
     * Resolve the ordered metadata and question items requested for export, validating non-empty selections.
     *
     * @param  array<string, mixed>  $filters
     * @param  Builder<FbSubmission>  $filteredQuery
     * @param  array<int, string>|null  $selectedTokens
     * @return array{
     *     is_custom_selection: bool,
     *     total_available_count: int,
     *     ordered_items: array<int, array<string, mixed>>,
     *     meta_keys: array<int, string>,
     *     question_items: array<int, array<string, mixed>>
     * }
     */
    protected function resolveRequestedExportPlan(
        array $filters,
        Builder $filteredQuery,
        ?array $selectedTokens
    ): array {
        $catalog = [];
        $defaultOrder = [];

        foreach (self::METADATA_FIELD_DEFINITIONS as $key => $label) {
            $token = 'meta:'.$key;
            $entry = [
                'kind' => 'meta',
                'token' => $token,
                'key' => $key,
                'header' => $label,
            ];
            $catalog[$token] = $entry;
            $defaultOrder[] = $token;
        }

        foreach ($this->resolveCsvQuestionColumns($filters, $filteredQuery) as $col) {
            $fieldId = (string) ($col['field']['id'] ?? '');
            if ($fieldId === '') {
                continue;
            }

            $token = sprintf('q:%d:%s', $col['feedback_id'], $fieldId);
            $entry = [
                'kind' => 'question',
                'token' => $token,
                'feedback_id' => $col['feedback_id'],
                'field_id' => $fieldId,
                'field' => $col['field'],
                'header' => $col['header'],
                'event_name' => $col['event_name'],
            ];
            $catalog[$token] = $entry;
            $defaultOrder[] = $token;
        }

        if ($selectedTokens === null) {
            $orderedItems = array_values($catalog);
            $isCustom = false;
        } else {
            $orderedItems = [];
            foreach ($selectedTokens as $token) {
                if (isset($catalog[$token])) {
                    $orderedItems[] = $catalog[$token];
                }
            }

            if (empty($orderedItems)) {
                throw ValidationException::withMessages([
                    'fields' => 'Please select at least one valid field to include in the export.',
                ]);
            }

            $chosenTokens = array_map(fn (array $item): string => $item['token'], $orderedItems);
            $isCustom = ($chosenTokens !== $defaultOrder);
        }

        $metaKeys = [];
        $questionItems = [];

        foreach ($orderedItems as $item) {
            if ($item['kind'] === 'meta') {
                $metaKeys[] = (string) $item['key'];
            } else {
                $questionItems[] = $item;
            }
        }

        return [
            'is_custom_selection' => $isCustom,
            'total_available_count' => count($catalog),
            'ordered_items' => $orderedItems,
            'meta_keys' => $metaKeys,
            'question_items' => $questionItems,
        ];
    }

    /**
     * Resolve ordered CSV question columns from the event schema(s), using human-readable stripped labels.
     *
     * @param  array<string, mixed>  $filters
     * @param  Builder<FbSubmission>  $filteredQuery
     * @return array<int, array{feedback_id: int, field: array<string, mixed>, header: string}>
     */
    protected function resolveCsvQuestionColumns(array $filters, Builder $filteredQuery): array
    {
        /** @var array<int, Feedback> $feedbacks */
        $feedbacks = [];

        if (! empty($filters['event_id'])) {
            $feedback = Feedback::with(['event' => fn ($q) => $q->withTrashed()])
                ->where('event_id', (int) $filters['event_id'])
                ->first();
            if ($feedback) {
                $feedbacks[] = $feedback;
            }
        } else {
            $feedbackIds = (clone $filteredQuery)
                ->select('feedback_id')
                ->distinct()
                ->pluck('feedback_id')
                ->filter()
                ->map(fn ($id): int => (int) $id)
                ->values()
                ->all();

            if (! empty($feedbackIds)) {
                $loaded = Feedback::with(['event' => fn ($q) => $q->withTrashed()])
                    ->whereIn('id', $feedbackIds)
                    ->orderBy('event_id')
                    ->orderBy('id')
                    ->get();

                foreach ($loaded as $fb) {
                    $feedbacks[] = $fb;
                }
            }
        }

        if (empty($feedbacks)) {
            return [];
        }

        $multipleEvents = count($feedbacks) > 1;
        $labelEventCounts = [];

        if ($multipleEvents) {
            foreach ($feedbacks as $fb) {
                $seenInForm = [];
                foreach ($this->getResolvedAnswerableFields($fb) as $field) {
                    $baseLabel = $this->cleanQuestionLabel((string) ($field['particular'] ?? $field['id'] ?? 'Question'));
                    $seenInForm[$baseLabel] = true;
                }
                foreach (array_keys($seenInForm) as $lbl) {
                    $labelEventCounts[$lbl] = ($labelEventCounts[$lbl] ?? 0) + 1;
                }
            }
        }

        $columns = [];
        $usedHeaders = [];

        foreach ($feedbacks as $fb) {
            $eventName = $fb->event?->name ?? ('Event #'.$fb->event_id);
            foreach ($this->getResolvedAnswerableFields($fb) as $field) {
                $baseLabel = $this->cleanQuestionLabel((string) ($field['particular'] ?? $field['id'] ?? 'Question'));

                $candidateHeader = ($multipleEvents && ($labelEventCounts[$baseLabel] ?? 0) > 1)
                    ? "{$eventName} — {$baseLabel}"
                    : $baseLabel;

                $header = $candidateHeader;
                $suffix = 2;
                while (isset($usedHeaders[$header])) {
                    $header = "{$candidateHeader} ({$suffix})";
                    $suffix++;
                }
                $usedHeaders[$header] = true;

                $columns[] = [
                    'feedback_id' => (int) $fb->id,
                    'field' => $field,
                    'header' => $header,
                    'event_name' => $eventName,
                ];
            }
        }

        return $columns;
    }

    /**
     * Build one sanitized CSV row following the exact ordered list of selected metadata and question columns.
     *
     * @param  array<int, array<string, mixed>>  $orderedColumns
     * @return array<int, string>
     */
    protected function buildOrderedCsvSubmissionRow(FbSubmission $submission, array $orderedColumns): array
    {
        $row = [];

        foreach ($orderedColumns as $item) {
            if ($item['kind'] === 'meta') {
                $key = (string) $item['key'];
                $isSignedNumeric = in_array($key, ['years_in_designation', 'no_of_exercises'], true);
                $row[] = $this->sanitizeCsvCell(
                    $this->extractMetadataValueForCsv($submission, $key),
                    allowSignedNumber: $isSignedNumeric
                );

                continue;
            }

            if ((int) $submission->feedback_id !== (int) $item['feedback_id']) {
                $row[] = '';

                continue;
            }

            /** @var array<string, mixed> $field */
            $field = $item['field'];
            $isNumericField = ($field['type'] ?? '') === 'number';
            $formatted = $this->formatFieldAnswerForCsv($submission, $field);
            $row[] = $this->sanitizeCsvCell($formatted, allowSignedNumber: $isNumericField);
        }

        return $row;
    }

    /**
     * Extract a single metadata attribute value for CSV export.
     */
    protected function extractMetadataValueForCsv(FbSubmission $submission, string $key): string
    {
        $participant = $submission->participant;
        $event = $submission->feedback?->event;

        return match ($key) {
            'reference_code' => $submission->reference_code ?: ('#'.$submission->id),
            'event' => $event
                ? $event->name.($event->trashed() ? ' (Archived)' : '')
                : ('Archived Event #'.($submission->feedback?->event_id ?? '—')),
            'participant_name' => ($participant && ! empty($participant->name))
                ? $participant->name
                : 'Anonymous Participant',
            'function' => $this->formatParticipantFunction($submission),
            'agency' => $participant?->agency ?? '',
            'designation' => $participant?->designation ?? '',
            'years_in_designation' => $participant?->years_in_designation !== null
                ? (string) $participant->years_in_designation
                : '',
            'location' => $participant?->location ?? '',
            'no_of_exercises' => $participant?->no_of_exercises !== null
                ? (string) $participant->no_of_exercises
                : '',
            'submitted_at' => $submission->created_at?->format('Y-m-d H:i:s') ?? '',
            default => '',
        };
    }

    /**
     * Build a structured per-submission card payload for PDF generation (honoring field selection & ordering).
     *
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    protected function buildPdfSubmissionCard(FbSubmission $submission, int $index, array $plan): array
    {
        $participant = $submission->participant;
        $feedback = $submission->feedback;
        $event = $feedback?->event;

        $eventLabel = $event
            ? $event->name.($event->trashed() ? ' (Archived)' : '')
            : ('Archived Event #'.($feedback?->event_id ?? '—'));

        $participantName = ($participant && ! empty($participant->name))
            ? $participant->name
            : 'Anonymous Participant';
        $functionLabel = $this->formatParticipantFunction($submission);
        $agencyLabel = ($participant && ! empty($participant->agency)) ? $participant->agency : '—';
        $designationLabel = ($participant && ! empty($participant->designation)) ? $participant->designation : '—';
        $locationLabel = ($participant && ! empty($participant->location)) ? $participant->location : '—';

        $years = (int) ($participant?->years_in_designation ?? 0);
        $exercises = (int) ($participant?->no_of_exercises ?? 0);
        $experienceParts = [];
        if ($years > 0) {
            $experienceParts[] = "{$years} yr(s) in role";
        }
        if ($exercises > 0) {
            $experienceParts[] = "{$exercises} exercise(s)";
        }
        $experienceLabel = ! empty($experienceParts) ? implode(' • ', $experienceParts) : '—';

        $isCustomSelection = ! empty($plan['is_custom_selection']);
        /** @var array<int, string> $selectedMetaKeys */
        $selectedMetaKeys = is_array($plan['meta_keys'] ?? null) ? $plan['meta_keys'] : [];
        /** @var array<int, array<string, mixed>> $selectedQuestionItems */
        $selectedQuestionItems = is_array($plan['question_items'] ?? null) ? $plan['question_items'] : [];

        $showRefCode = ! $isCustomSelection || in_array('reference_code', $selectedMetaKeys, true);
        $showSubmittedAt = ! $isCustomSelection || in_array('submitted_at', $selectedMetaKeys, true);
        $includeAnswers = ! $isCustomSelection || count($selectedQuestionItems) > 0;

        $customMetadataRows = null;
        if ($isCustomSelection) {
            $customMetadataRows = [];
            foreach ($selectedMetaKeys as $metaKey) {
                if (in_array($metaKey, ['reference_code', 'submitted_at'], true)) {
                    continue;
                }

                $customMetadataRows[] = match ($metaKey) {
                    'event' => ['label' => 'Event', 'value' => $eventLabel, 'bold' => true],
                    'participant_name' => ['label' => 'Participant', 'value' => $participantName, 'bold' => false],
                    'function' => ['label' => 'Function', 'value' => $functionLabel, 'bold' => false],
                    'agency' => ['label' => 'Agency', 'value' => $agencyLabel, 'bold' => false],
                    'designation' => ['label' => 'Designation', 'value' => $designationLabel, 'bold' => false],
                    'years_in_designation' => [
                        'label' => 'Years in Designation',
                        'value' => $years > 0 ? "{$years} yr(s)" : '0',
                        'bold' => false,
                    ],
                    'location' => ['label' => 'Location', 'value' => $locationLabel, 'bold' => false],
                    'no_of_exercises' => [
                        'label' => 'No. of Exercises',
                        'value' => (string) $exercises,
                        'bold' => false,
                    ],
                    default => null,
                };
            }
            $customMetadataRows = array_values(array_filter($customMetadataRows));
        }

        $fieldsToRender = [];
        if ($feedback && $includeAnswers) {
            if ($isCustomSelection) {
                foreach ($selectedQuestionItems as $qItem) {
                    if ((int) $qItem['feedback_id'] === (int) $feedback->id) {
                        $fieldsToRender[] = $qItem['field'];
                    }
                }
            } else {
                $fieldsToRender = $this->getResolvedAnswerableFields($feedback);
            }
        }

        $answers = [];
        foreach ($fieldsToRender as $field) {
            $label = $this->cleanQuestionLabel((string) ($field['particular'] ?? $field['id'] ?? 'Question'));
            $type = (string) ($field['type'] ?? 'text');

            if ($type === 'photo') {
                $photoMeta = $this->resolvePhotoAnswer($submission, $field, loadBytes: true);
                if ($photoMeta !== null) {
                    $answers[] = [
                        'label' => $label,
                        'type' => 'photo',
                        'value' => $photoMeta['display_name'],
                        'photo' => [
                            'display_name' => $photoMeta['display_name'],
                            'size_label' => $photoMeta['size_label'],
                            'raw_bytes' => $photoMeta['raw_bytes'],
                        ],
                    ];
                } else {
                    $answers[] = [
                        'label' => $label,
                        'type' => 'photo',
                        'value' => '—',
                        'photo' => null,
                    ];
                }

                continue;
            }

            $formatted = $this->formatScalarOrChoiceAnswer($submission, $field);
            $answers[] = [
                'label' => $label,
                'type' => $type,
                'value' => $formatted !== '' ? $formatted : '—',
            ];
        }

        $card = [
            'index' => $index,
            'reference_code' => $submission->reference_code ?: ('#'.$submission->id),
            'event_name' => $eventLabel,
            'submitted_at' => $submission->created_at?->format('Y-m-d H:i:s') ?? '—',
            'show_reference_code' => $showRefCode,
            'show_submitted_at' => $showSubmittedAt,
            'include_answers' => $includeAnswers,
            'participant' => [
                'name' => $participantName,
                'function' => $functionLabel,
                'agency' => $agencyLabel,
                'designation' => $designationLabel,
                'location' => $locationLabel,
                'experience' => $experienceLabel,
            ],
            'answers' => $answers,
        ];

        if ($customMetadataRows !== null) {
            $card['metadata_rows'] = $customMetadataRows;
        }

        return $card;
    }

    /**
     * Format a single field answer as a plain-text CSV value.
     *
     * @param  array<string, mixed>  $field
     */
    protected function formatFieldAnswerForCsv(FbSubmission $submission, array $field): string
    {
        $type = (string) ($field['type'] ?? 'text');

        if ($type === 'photo') {
            $photoMeta = $this->resolvePhotoAnswer($submission, $field, loadBytes: false);
            if ($photoMeta === null) {
                return '';
            }

            return sprintf('%s (%s)', $photoMeta['display_name'], $photoMeta['authorized_url']);
        }

        return $this->formatScalarOrChoiceAnswer($submission, $field);
    }

    /**
     * Format scalar (`text`, `textarea`, `number`, `date`) and choice (`radio`, `select`, `checkbox`) answers.
     *
     * @param  array<string, mixed>  $field
     */
    protected function formatScalarOrChoiceAnswer(FbSubmission $submission, array $field): string
    {
        $data = is_array($submission->data) ? $submission->data : [];
        $fieldId = (string) ($field['id'] ?? '');
        if ($fieldId === '' || ! array_key_exists($fieldId, $data)) {
            return '';
        }

        $rawValue = $data[$fieldId];
        if ($rawValue === null || $rawValue === '') {
            return '';
        }

        $type = (string) ($field['type'] ?? 'text');
        $otherSpecification = $this->extractOtherText($data, $fieldId);

        if ($type === 'radio' || $type === 'select') {
            if (! is_scalar($rawValue)) {
                return '';
            }

            return $this->resolveOptionLabel($field, (string) $rawValue, $otherSpecification);
        }

        if ($type === 'checkbox') {
            if (! is_array($rawValue) || count($rawValue) === 0) {
                return '';
            }

            $labels = [];
            foreach ($rawValue as $selected) {
                if (is_scalar($selected) && (string) $selected !== '') {
                    $labels[] = $this->resolveOptionLabel($field, (string) $selected, $otherSpecification);
                }
            }

            return implode('; ', $labels);
        }

        if (is_scalar($rawValue)) {
            return (string) $rawValue;
        }

        return '';
    }

    /**
     * Map an option value to its schema display label, appending "Other" text when specified.
     *
     * @param  array<string, mixed>  $field
     */
    protected function resolveOptionLabel(array $field, string $value, ?string $otherSpecification = null): string
    {
        $options = is_array($field['options'] ?? null) ? $field['options'] : [];
        $matched = null;

        foreach ($options as $opt) {
            if (is_array($opt) && (string) ($opt['value'] ?? '') === $value) {
                $matched = $opt;
                break;
            }
        }

        $label = $matched !== null
            ? $this->cleanQuestionLabel((string) ($matched['label'] ?? $value))
            : $value;

        $isOtherOption = ($matched !== null && ! empty($matched['is_other']))
            || $value === 'other'
            || strtolower($label) === 'other'
            || strtolower($label) === 'others';

        if ($isOtherOption && $otherSpecification !== null && $otherSpecification !== '') {
            return "{$label}: {$otherSpecification}";
        }

        return $label;
    }

    /**
     * Extract optional `_other` or `custom_` specification text for a choice field.
     *
     * @param  array<string, mixed>  $data
     */
    protected function extractOtherText(array $data, string $fieldId): ?string
    {
        $raw = $data[$fieldId.'_other'] ?? $data['custom_'.$fieldId] ?? null;
        if (! is_scalar($raw)) {
            return null;
        }

        $trimmed = trim((string) $raw);

        return $trimmed !== '' ? $trimmed : null;
    }

    /**
     * Validate stored photo metadata against path containment rules without touching disk or generating URLs.
     *
     * @param  array<string, mixed>  $field
     * @return array{
     *     field_id: string,
     *     disk: string,
     *     path: string,
     *     filename: string,
     *     photo_data: array<string, mixed>
     * }|null
     */
    protected function resolveValidatedPhotoStorageLocation(FbSubmission $submission, array $field): ?array
    {
        $fieldId = (string) ($field['id'] ?? '');
        $data = is_array($submission->data) ? $submission->data : [];
        $photoData = $data[$fieldId] ?? null;

        if (! is_array($photoData) || ($photoData['type'] ?? null) !== 'photo') {
            return null;
        }

        $disk = (string) ($photoData['disk'] ?? '');
        $storedPath = (string) ($photoData['path'] ?? '');
        $filename = (string) ($photoData['filename'] ?? basename($storedPath));
        $safeField = preg_replace('/[^a-zA-Z0-9_-]/', '', $fieldId) ?: '';

        if ($safeField === '' || ! preg_match('/^[A-Za-z0-9._-]+$/', $filename)) {
            return null;
        }

        $expectedPrefix = FeedbackSubmissionPersistenceService::STORAGE_BASE_DIR
            ."/{$submission->feedback_id}/{$submission->id}/{$safeField}/";

        if (
            $disk !== FeedbackSubmissionPersistenceService::STORAGE_DISK ||
            $storedPath === '' ||
            str_contains($storedPath, '..') ||
            ! str_starts_with($storedPath, $expectedPrefix) ||
            basename($storedPath) !== $filename ||
            $storedPath !== $expectedPrefix.$filename
        ) {
            return null;
        }

        return [
            'field_id' => $fieldId,
            'disk' => $disk,
            'path' => $storedPath,
            'filename' => $filename,
            'photo_data' => $photoData,
        ];
    }

    /**
     * Determine whether a submission field references an existing, valid raster image that will be embedded in PDF.
     *
     * @param  array<string, mixed>  $field
     */
    protected function hasEmbeddablePhotoOnDisk(FbSubmission $submission, array $field): bool
    {
        $location = $this->resolveValidatedPhotoStorageLocation($submission, $field);
        if ($location === null) {
            return false;
        }

        $disk = $location['disk'];
        $storedPath = $location['path'];

        if (! Storage::disk($disk)->exists($storedPath)) {
            return false;
        }

        $detectedMime = strtolower((string) Storage::disk($disk)->mimeType($storedPath));

        return in_array($detectedMime, FeedbackSchemaValidator::PHOTO_ALLOWED_MIMES, true);
    }

    /**
     * Validate stored photo metadata against path containment rules and optionally read raster bytes for PDF embedding.
     *
     * @param  array<string, mixed>  $field
     * @return array{
     *     display_name: string,
     *     authorized_url: string,
     *     size_label: string|null,
     *     raw_bytes: string|null
     * }|null
     */
    protected function resolvePhotoAnswer(FbSubmission $submission, array $field, bool $loadBytes = false): ?array
    {
        $location = $this->resolveValidatedPhotoStorageLocation($submission, $field);
        if ($location === null) {
            return null;
        }

        $fieldId = $location['field_id'];
        $disk = $location['disk'];
        $storedPath = $location['path'];
        $filename = $location['filename'];
        $photoData = $location['photo_data'];

        $rawOriginal = isset($photoData['original_name']) && is_string($photoData['original_name'])
            ? trim($photoData['original_name'])
            : '';
        $safeDisplayName = $this->sanitizeDisplayFilename($rawOriginal !== '' ? $rawOriginal : $filename);

        $authorizedUrl = route('admin.feedback.submissions.photo', [
            'submission' => $submission->id,
            'field' => $fieldId,
            'filename' => $filename,
        ]);

        $sizeBytes = isset($photoData['size']) && is_numeric($photoData['size']) ? (int) $photoData['size'] : 0;
        $sizeLabel = $sizeBytes > 0 ? $this->formatBytes($sizeBytes) : null;

        $rawBytes = null;
        if ($loadBytes && Storage::disk($disk)->exists($storedPath)) {
            $detectedMime = strtolower((string) Storage::disk($disk)->mimeType($storedPath));
            if (in_array($detectedMime, FeedbackSchemaValidator::PHOTO_ALLOWED_MIMES, true)) {
                $contents = Storage::disk($disk)->get($storedPath);
                if (is_string($contents) && $contents !== '') {
                    $rawBytes = $contents;
                }
            }
        }

        return [
            'display_name' => $safeDisplayName,
            'authorized_url' => $authorizedUrl,
            'size_label' => $sizeLabel,
            'raw_bytes' => $rawBytes,
        ];
    }

    /**
     * Resolve and cache answerable fields (excluding `section` and `text_only`) for a `Feedback` form.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function getResolvedAnswerableFields(Feedback $feedback): array
    {
        $feedbackId = (int) $feedback->id;
        if (isset($this->resolvedFieldsCache[$feedbackId])) {
            return $this->resolvedFieldsCache[$feedbackId];
        }

        if (! is_array($feedback->schema)) {
            return $this->resolvedFieldsCache[$feedbackId] = [];
        }

        $event = $feedback->relationLoaded('event')
            ? $feedback->event
            : FbEvent::withTrashed()->find($feedback->event_id);

        $resolved = $this->schemaValidator->resolveSchemaForEvent($feedback->schema, $event);
        $rawFields = is_array($resolved['fields'] ?? null) ? $resolved['fields'] : [];

        $answerable = [];
        foreach ($rawFields as $field) {
            if (! is_array($field)) {
                continue;
            }
            $type = (string) ($field['type'] ?? 'text');
            if (in_array($type, ['section', 'text_only'], true)) {
                continue;
            }
            $answerable[] = $field;
        }

        return $this->resolvedFieldsCache[$feedbackId] = $answerable;
    }

    /**
     * Strip HTML formatting tags and normalize whitespace for clean column headers and PDF question labels.
     */
    public function cleanQuestionLabel(string $rawHtml): string
    {
        $withBreaks = preg_replace('/<\s*br\s*\/?>/i', ' ', $rawHtml) ?? $rawHtml;
        $stripped = strip_tags($withBreaks);
        $decoded = html_entity_decode($stripped, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $collapsed = preg_replace('/\s+/u', ' ', trim($decoded)) ?? trim($decoded);

        return $collapsed !== '' ? $collapsed : 'Untitled Question';
    }

    /**
     * Neutralize spreadsheet formula injection (`=`, `+`, `-`, `@`, `\t`, `\r`) while preserving normal text & numbers.
     */
    public function sanitizeCsvCell(string|int|float|null $value, bool $allowSignedNumber = false): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if ($allowSignedNumber && is_numeric($value)) {
            return (string) $value;
        }

        $str = (string) $value;
        if ($str === '') {
            return '';
        }

        $firstChar = $str[0];
        $trimmedLeading = ltrim($str, ' ');
        $firstNonSpace = $trimmedLeading !== '' ? $trimmedLeading[0] : '';

        $dangerousTriggers = ['=', '+', '-', '@', "\t", "\r"];

        if (
            in_array($firstChar, $dangerousTriggers, true) ||
            in_array($firstNonSpace, $dangerousTriggers, true)
        ) {
            return "'".$str;
        }

        return $str;
    }

    /**
     * Format the participant's assigned role/function, including custom specification when provided.
     */
    protected function formatParticipantFunction(FbSubmission $submission): string
    {
        $participant = $submission->participant;
        if (! $participant) {
            return 'Unassigned Role';
        }

        $roleName = $participant->function?->function;
        $custom = is_string($participant->custom_function) ? trim($participant->custom_function) : '';

        if ($roleName && $custom !== '') {
            return "{$roleName} ({$custom})";
        }

        if ($roleName) {
            return $roleName;
        }

        if ($custom !== '') {
            return $custom;
        }

        return 'Unassigned Role';
    }

    /**
     * Describe active filters as human-readable key/value labels for the PDF report header.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, string>
     */
    protected function describeActiveFilters(array $filters, ?FbEvent $event = null): array
    {
        $summary = [
            'View Mode' => ($filters['tab'] ?? 'individual') === 'compiled'
                ? 'Compiled Event Responses'
                : 'Individual Responses',
            'Event' => $event !== null
                ? $event->name.($event->trashed() ? ' (Archived)' : '')
                : 'All Events',
        ];

        if (! empty($filters['function_id'])) {
            $func = FbFunction::find((int) $filters['function_id']);
            $summary['Function'] = $func?->function ?? ('Function #'.$filters['function_id']);
        } else {
            $summary['Function'] = 'All Functions';
        }

        $summary['Agency'] = ! empty($filters['agency']) ? (string) $filters['agency'] : 'All Agencies';
        $summary['Designation'] = ! empty($filters['designation']) ? (string) $filters['designation'] : 'All Designations';

        if (($filters['tab'] ?? 'individual') === 'individual' && ! empty($filters['search'])) {
            $summary['Search'] = (string) $filters['search'];
        }

        return $summary;
    }

    /**
     * Generate a clean, descriptive download filename reflecting the active view and event scope.
     *
     * @param  array<string, mixed>  $filters
     */
    protected function buildExportFilename(array $filters, string $extension): string
    {
        $scopeSlug = 'all-events';

        if (! empty($filters['event_id'])) {
            $event = FbEvent::withTrashed()->find((int) $filters['event_id']);
            if ($event) {
                $scopeSlug = Str::slug(Str::limit($event->name, 40, '')) ?: ('event-'.$event->id);
            } else {
                $scopeSlug = 'event-'.(int) $filters['event_id'];
            }
        }

        $modePrefix = ($filters['tab'] ?? 'individual') === 'compiled'
            ? 'feedback-compiled'
            : 'feedback-submissions';

        return sprintf('%s-%s-%s.%s', $modePrefix, $scopeSlug, now()->format('Ymd-His'), $extension);
    }

    protected function sanitizeDisplayFilename(string $rawName): string
    {
        $base = basename(str_replace('\\', '/', trim($rawName)));
        $cleaned = preg_replace('/[^a-zA-Z0-9._ -]/', '_', $base) ?? '';
        $cleaned = trim($cleaned, '. _-');

        return $cleaned !== '' ? Str::limit($cleaned, 120, '') : 'photo';
    }

    protected function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return "{$bytes} B";
        }

        if ($bytes < 1048576) {
            return number_format($bytes / 1024, 1).' KB';
        }

        return number_format($bytes / 1048576, 2).' MB';
    }
}
