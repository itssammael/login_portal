<?php

namespace App\Services;

class FeedbackSubmissionPdfBuilder
{
    public const PAGE_WIDTH = 612.0;

    public const PAGE_HEIGHT = 792.0;

    public const MARGIN_LEFT = 42.0;

    public const MARGIN_RIGHT = 42.0;

    public const MARGIN_TOP = 50.0;

    public const MARGIN_BOTTOM = 48.0;

    protected const MAX_IMAGE_PX_WIDTH = 1000;

    protected const MAX_IMAGE_PX_HEIGHT = 800;

    protected const MAX_IMAGE_PT_WIDTH = 280.0;

    protected const MAX_IMAGE_PT_HEIGHT = 185.0;

    /**
     * Approximate average glyph width ratios (relative to font size) for standard PDF Type1 fonts.
     *
     * @var array<string, float>
     */
    protected const FONT_AVG_WIDTH = [
        'F1' => 0.52, // Helvetica
        'F2' => 0.56, // Helvetica-Bold
        'F3' => 0.52, // Helvetica-Oblique
        'F4' => 0.60, // Courier
    ];

    /**
     * Per-page arrays of PDF content stream commands.
     *
     * @var array<int, array<int, string>>
     */
    protected array $pages = [];

    /**
     * Per-page map of XObject image names used on that page (`['Im1' => 0, ...]`).
     *
     * @var array<int, array<string, int>>
     */
    protected array $pageImages = [];

    /**
     * Registered embedded JPEG images.
     *
     * @var array<int, array{name: string, bytes: string, width: int, height: int}>
     */
    protected array $images = [];

    protected int $currentPageIndex = -1;

    protected float $cursorY = self::MARGIN_TOP;

    protected string $runningTitle = 'Feedback Submissions Export';

    protected PdfEmojiRenderer $emojiRenderer;

    public function __construct(?PdfEmojiRenderer $emojiRenderer = null)
    {
        $this->emojiRenderer = $emojiRenderer ?? new PdfEmojiRenderer;
    }

    /**
     * Build a complete PDF 1.4 binary document for the exported feedback submissions.
     *
     * @param  array{
     *     title: string,
     *     subtitle: string,
     *     generated_at: string,
     *     total_submissions: int,
     *     active_tab: string,
     *     filter_summary: array<string, string>,
     *     compiled_questions?: array<int, array{
     *         particular: string,
     *         type: string,
     *         answered_count: int,
     *         unanswered_count: int,
     *         total_submissions: int,
     *         is_choice: bool,
     *         options: array<int, array{label: string, count: int, percentage: float}>
     *     }>|null
     * }  $reportMeta
     * @param  iterable<int, array{
     *     index: int,
     *     reference_code: string,
     *     event_name: string,
     *     submitted_at: string,
     *     participant: array{
     *         name: string,
     *         function: string,
     *         agency: string,
     *         designation: string,
     *         location: string,
     *         experience: string
     *     },
     *     answers: array<int, array{
     *         label: string,
     *         type: string,
     *         value: string,
     *         photo?: array{
     *             display_name: string,
     *             size_label: string|null,
     *             raw_bytes: string|null
     *         }|null
     *     }>
     * }>  $submissions
     */
    public function buildReport(array $reportMeta, iterable $submissions): string
    {
        $this->pages = [];
        $this->pageImages = [];
        $this->images = [];
        $this->currentPageIndex = -1;
        $this->runningTitle = $reportMeta['title'] !== '' ? $reportMeta['title'] : 'Feedback Submissions Export';

        $this->addPage(isCoverPage: true);
        $this->renderReportBanner($reportMeta);
        $this->renderFilterSummaryCard($reportMeta);

        if (! empty($reportMeta['compiled_questions']) && is_array($reportMeta['compiled_questions'])) {
            $this->renderCompiledSummarySection($reportMeta['compiled_questions']);
        }

        $renderedCount = 0;
        foreach ($submissions as $submissionCard) {
            $renderedCount++;
            $this->renderSubmissionCard($submissionCard, $reportMeta['total_submissions']);
        }

        if ($renderedCount === 0) {
            $this->renderEmptyStateCard();
        }

        return $this->compilePdfBinary($reportMeta['generated_at']);
    }

    /**
     * Prepare raw raster image bytes (JPEG, PNG, WebP, GIF) into a bounded RGB JPEG for PDF `/DCTDecode`.
     *
     * @return array{name: string, index: int, width: int, height: int}|null
     */
    protected function registerEmbeddedImage(string $rawBytes): ?array
    {
        if ($rawBytes === '' || ! function_exists('imagecreatefromstring')) {
            return null;
        }

        $source = @imagecreatefromstring($rawBytes);
        if ($source === false) {
            return null;
        }

        $srcW = imagesx($source);
        $srcH = imagesy($source);

        if ($srcW <= 0 || $srcH <= 0) {
            imagedestroy($source);

            return null;
        }

        $scale = min(
            1.0,
            self::MAX_IMAGE_PX_WIDTH / $srcW,
            self::MAX_IMAGE_PX_HEIGHT / $srcH
        );

        $dstW = max(1, (int) round($srcW * $scale));
        $dstH = max(1, (int) round($srcH * $scale));

        $canvas = imagecreatetruecolor($dstW, $dstH);
        if ($canvas === false) {
            imagedestroy($source);

            return null;
        }

        $white = imagecolorallocate($canvas, 255, 255, 255);
        if ($white !== false) {
            imagefilledrectangle($canvas, 0, 0, $dstW, $dstH, $white);
        }
        imagealphablending($canvas, true);
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH);
        imagedestroy($source);

        ob_start();
        $encoded = imagejpeg($canvas, null, 84);
        $jpegBytes = (string) ob_get_clean();
        imagedestroy($canvas);

        if (! $encoded || $jpegBytes === '') {
            return null;
        }

        $index = count($this->images);
        $name = 'Im'.($index + 1);

        $this->images[$index] = [
            'name' => $name,
            'bytes' => $jpegBytes,
            'width' => $dstW,
            'height' => $dstH,
        ];

        return [
            'name' => $name,
            'index' => $index,
            'width' => $dstW,
            'height' => $dstH,
        ];
    }

    /**
     * Render the top report banner on page 1.
     *
     * @param  array<string, mixed>  $reportMeta
     */
    protected function renderReportBanner(array $reportMeta): void
    {
        $contentW = $this->contentWidth();
        $bannerH = 58.0;
        $topY = $this->cursorY;

        // Forest green banner (#143829)
        $this->drawRect(self::MARGIN_LEFT, $topY, $contentW, $bannerH, [0.078, 0.220, 0.161]);

        $this->drawText(
            (string) ($reportMeta['title'] ?? 'Feedback Submissions Report'),
            self::MARGIN_LEFT + 14.0,
            $topY + 21.0,
            'F2',
            14.0,
            [1.0, 1.0, 1.0]
        );

        $subtitle = (string) ($reportMeta['subtitle'] ?? '');
        if ($subtitle !== '') {
            $lines = $this->wrapText($subtitle, $contentW - 28.0, 'F1', 8.5);
            $this->drawText(
                $lines[0] ?? '',
                self::MARGIN_LEFT + 14.0,
                $topY + 36.0,
                'F1',
                8.5,
                [0.85, 0.93, 0.87]
            );
        }

        $metaLine = sprintf(
            'Total Matching Submissions: %d   |   Generated: %s',
            (int) ($reportMeta['total_submissions'] ?? 0),
            (string) ($reportMeta['generated_at'] ?? '')
        );
        $this->drawText(
            $metaLine,
            self::MARGIN_LEFT + 14.0,
            $topY + 49.0,
            'F1',
            7.5,
            [0.75, 0.88, 0.79]
        );

        $this->cursorY = $topY + $bannerH + 10.0;
    }

    /**
     * Render the active filter context summary box.
     *
     * @param  array<string, mixed>  $reportMeta
     */
    protected function renderFilterSummaryCard(array $reportMeta): void
    {
        /** @var array<string, string> $filters */
        $filters = is_array($reportMeta['filter_summary'] ?? null) ? $reportMeta['filter_summary'] : [];
        if (empty($filters)) {
            return;
        }

        $contentW = $this->contentWidth();
        $pairs = [];
        foreach ($filters as $label => $val) {
            $pairs[] = "{$label}: {$val}";
        }

        $summaryLine = implode('   |   ', $pairs);
        $wrapped = $this->wrapText($summaryLine, $contentW - 20.0, 'F1', 8.0);
        $boxH = 22.0 + (count($wrapped) * 11.0);

        $this->ensureSpace($boxH + 8.0);
        $topY = $this->cursorY;

        $this->drawRect(
            self::MARGIN_LEFT,
            $topY,
            $contentW,
            $boxH,
            [0.976, 0.969, 0.937],
            [0.84, 0.81, 0.72],
            0.6
        );

        $this->drawText(
            'ACTIVE EXPORT SCOPE & FILTERS',
            self::MARGIN_LEFT + 10.0,
            $topY + 12.0,
            'F2',
            7.5,
            [0.078, 0.220, 0.161]
        );

        $lineY = $topY + 24.0;
        foreach ($wrapped as $line) {
            $this->drawText($line, self::MARGIN_LEFT + 10.0, $lineY, 'F1', 8.0, [0.22, 0.25, 0.28]);
            $lineY += 11.0;
        }

        $this->cursorY = $topY + $boxH + 12.0;
    }

    /**
     * Render a concise aggregated summary table when exporting from the Compiled Responses tab.
     *
     * @param  array<int, array<string, mixed>>  $compiledQuestions
     */
    protected function renderCompiledSummarySection(array $compiledQuestions): void
    {
        $contentW = $this->contentWidth();
        $this->ensureSpace(42.0);

        $this->drawRect(self::MARGIN_LEFT, $this->cursorY, $contentW, 20.0, [0.89, 0.94, 0.86], [0.72, 0.82, 0.68], 0.5);
        $this->drawText(
            'COMPILED QUESTION BREAKDOWN SUMMARY',
            self::MARGIN_LEFT + 10.0,
            $this->cursorY + 13.5,
            'F2',
            8.5,
            [0.078, 0.220, 0.161]
        );
        $this->cursorY += 26.0;

        foreach ($compiledQuestions as $qIdx => $q) {
            $prompt = sprintf('%d. %s [%s]', $qIdx + 1, (string) ($q['particular'] ?? ''), strtoupper((string) ($q['type'] ?? 'text')));
            $promptLines = $this->wrapText($prompt, $contentW - 16.0, 'F2', 8.5);

            $statsText = sprintf(
                'Answered: %d of %d (%d unanswered)',
                (int) ($q['answered_count'] ?? 0),
                (int) ($q['total_submissions'] ?? 0),
                (int) ($q['unanswered_count'] ?? 0)
            );

            $optionLines = [];
            if (! empty($q['is_choice']) && ! empty($q['options']) && is_array($q['options'])) {
                foreach ($q['options'] as $opt) {
                    $optLine = sprintf(
                        '- %s: %d (%s%%)',
                        (string) ($opt['label'] ?? ''),
                        (int) ($opt['count'] ?? 0),
                        number_format((float) ($opt['percentage'] ?? 0.0), 1)
                    );
                    foreach ($this->wrapText($optLine, $contentW - 28.0, 'F1', 8.0) as $wrappedOpt) {
                        $optionLines[] = $wrappedOpt;
                    }
                }
            }

            $cardH = 14.0 + (count($promptLines) * 11.0) + 12.0 + (count($optionLines) * 10.5);
            $this->ensureSpace(min($cardH + 6.0, 90.0));

            $startY = $this->cursorY;
            foreach ($promptLines as $pLine) {
                $this->ensureSpace(13.0);
                $this->drawText($pLine, self::MARGIN_LEFT + 8.0, $this->cursorY + 9.0, 'F2', 8.5, [0.12, 0.15, 0.18]);
                $this->cursorY += 11.0;
            }

            $this->ensureSpace(13.0);
            $this->drawText($statsText, self::MARGIN_LEFT + 8.0, $this->cursorY + 8.5, 'F3', 7.8, [0.35, 0.39, 0.43]);
            $this->cursorY += 12.0;

            foreach ($optionLines as $oLine) {
                $this->ensureSpace(12.0);
                $this->drawText($oLine, self::MARGIN_LEFT + 16.0, $this->cursorY + 8.0, 'F1', 8.0, [0.20, 0.24, 0.28]);
                $this->cursorY += 10.5;
            }

            $this->drawLine(
                self::MARGIN_LEFT,
                $this->cursorY + 2.0,
                self::MARGIN_LEFT + $contentW,
                $this->cursorY + 2.0,
                [0.86, 0.84, 0.78],
                0.4
            );
            $this->cursorY = max($this->cursorY + 7.0, $startY + 10.0);
        }

        $this->cursorY += 6.0;
    }

    /**
     * Render a single submission in a structured per-submission layout with selected metadata,
     * wrapped Q&A rows, and embedded respondent photos.
     *
     * @param  array<string, mixed>  $submission
     */
    protected function renderSubmissionCard(array $submission, int $totalSubmissions): void
    {
        $contentW = $this->contentWidth();
        $refCode = (string) ($submission['reference_code'] ?? '');
        $eventName = (string) ($submission['event_name'] ?? 'Unknown Event');
        $submittedAt = (string) ($submission['submitted_at'] ?? '—');
        $idx = (int) ($submission['index'] ?? 1);
        $showRefCode = (bool) ($submission['show_reference_code'] ?? true);
        $showSubmittedAt = (bool) ($submission['show_submitted_at'] ?? true);
        $includeAnswers = (bool) ($submission['include_answers'] ?? true);

        /** @var array<int, array{label: string, value: string, bold?: bool}>|null $customMetaRows */
        $customMetaRows = isset($submission['metadata_rows']) && is_array($submission['metadata_rows'])
            ? $submission['metadata_rows']
            : null;

        if ($customMetaRows === null) {
            /** @var array<string, string> $participant */
            $participant = is_array($submission['participant'] ?? null) ? $submission['participant'] : [];
            $customMetaRows = [
                ['label' => 'Event', 'value' => $eventName, 'bold' => true],
                ['label' => 'Participant', 'value' => (string) ($participant['name'] ?? 'Anonymous Participant'), 'bold' => false],
                ['label' => 'Function', 'value' => (string) ($participant['function'] ?? 'Unassigned Role'), 'bold' => false],
                ['label' => 'Agency', 'value' => (string) ($participant['agency'] ?? '—'), 'bold' => false],
                ['label' => 'Designation', 'value' => (string) ($participant['designation'] ?? '—'), 'bold' => false],
                ['label' => 'Location', 'value' => (string) ($participant['location'] ?? '—'), 'bold' => false],
                ['label' => 'Experience', 'value' => (string) ($participant['experience'] ?? '—'), 'bold' => false],
            ];
        }

        $hasMetaBox = ! empty($customMetaRows);

        // Ensure room for submission header + metadata block together
        $this->ensureSpace($hasMetaBox ? 110.0 : 54.0);

        // Submission Header Bar
        $headerH = 24.0;
        $headerTop = $this->cursorY;
        $this->drawRect(self::MARGIN_LEFT, $headerTop, $contentW, $headerH, [0.11, 0.27, 0.20]);

        $leftTitle = ($showRefCode && $refCode !== '')
            ? sprintf('Submission %d of %d  —  Ref: %s', $idx, max($idx, $totalSubmissions), $refCode)
            : sprintf('Submission %d of %d', $idx, max($idx, $totalSubmissions));
        $this->drawText($leftTitle, self::MARGIN_LEFT + 10.0, $headerTop + 15.5, 'F2', 9.0, [1.0, 1.0, 1.0]);

        if ($showSubmittedAt) {
            $rightMeta = "Submitted: {$submittedAt}";
            $rightWidth = $this->estimateTextWidth($rightMeta, 'F1', 8.0);
            $this->drawText(
                $rightMeta,
                max(self::MARGIN_LEFT + 260.0, self::MARGIN_LEFT + $contentW - $rightWidth - 10.0),
                $headerTop + 15.5,
                'F1',
                8.0,
                [0.84, 0.93, 0.87]
            );
        }

        $this->cursorY = $headerTop + $headerH;

        if ($hasMetaBox) {
            $colW = ($contentW - 24.0) / 2.0;
            $splitPoint = (int) ceil(count($customMetaRows) / 2);
            $leftSpecs = array_slice($customMetaRows, 0, $splitPoint);
            $rightSpecs = array_slice($customMetaRows, $splitPoint);

            $leftLines = $this->formatMetadataEntryLines($leftSpecs, $colW);
            $rightLines = $this->formatMetadataEntryLines($rightSpecs, $colW);
            $metaLineCount = max(1, count($leftLines), count($rightLines));
            $metaBoxH = 12.0 + ($metaLineCount * 11.5);

            $metaTop = $this->cursorY;
            $this->drawRect(
                self::MARGIN_LEFT,
                $metaTop,
                $contentW,
                $metaBoxH,
                [0.985, 0.982, 0.965],
                [0.83, 0.81, 0.74],
                0.5
            );

            foreach ($leftLines as $i => $line) {
                $this->drawText(
                    $line['text'],
                    self::MARGIN_LEFT + 10.0,
                    $metaTop + 13.5 + ($i * 11.5),
                    $line['bold'] ? 'F2' : 'F1',
                    8.0,
                    [0.18, 0.21, 0.24]
                );
            }

            foreach ($rightLines as $i => $line) {
                $this->drawText(
                    $line['text'],
                    self::MARGIN_LEFT + 14.0 + $colW,
                    $metaTop + 13.5 + ($i * 11.5),
                    $line['bold'] ? 'F2' : 'F1',
                    8.0,
                    [0.18, 0.21, 0.24]
                );
            }

            $this->cursorY = $metaTop + $metaBoxH + 6.0;
        } else {
            $this->cursorY += 6.0;
        }

        if (! $includeAnswers) {
            $this->cursorY += 8.0;

            return;
        }

        /** @var array<int, array<string, mixed>> $answers */
        $answers = is_array($submission['answers'] ?? null) ? $submission['answers'] : [];

        if (empty($answers)) {
            $this->ensureSpace(22.0);
            $this->drawText(
                'No questionnaire fields configured for this submission.',
                self::MARGIN_LEFT + 10.0,
                $this->cursorY + 12.0,
                'F3',
                8.5,
                [0.45, 0.48, 0.52]
            );
            $this->cursorY += 22.0;

            return;
        }

        $continuationLabel = ($showRefCode && $refCode !== '')
            ? "Ref: {$refCode}"
            : "Submission {$idx}";

        foreach ($answers as $qIdx => $answerItem) {
            $this->renderQuestionAnswerBlock($qIdx + 1, $answerItem, $continuationLabel);
        }

        $this->cursorY += 10.0;
    }

    /**
     * Render one Question & Answer entry inside a submission card, handling multi-page text and embedded photos.
     *
     * @param  array<string, mixed>  $answerItem
     */
    protected function renderQuestionAnswerBlock(int $questionNumber, array $answerItem, string $continuationContext): void
    {
        $contentW = $this->contentWidth();
        $innerW = $contentW - 20.0;
        $label = (string) ($answerItem['label'] ?? "Question {$questionNumber}");
        $type = (string) ($answerItem['type'] ?? 'text');
        $value = (string) ($answerItem['value'] ?? '—');

        $promptText = "{$questionNumber}. {$label}";
        $promptLines = $this->wrapText($promptText, $innerW, 'F2', 8.5);
        $promptHeight = count($promptLines) * 11.5;

        $photoMeta = is_array($answerItem['photo'] ?? null) ? $answerItem['photo'] : null;

        if ($type === 'photo' && $photoMeta !== null) {
            $embedded = null;
            if (is_string($photoMeta['raw_bytes'] ?? null) && $photoMeta['raw_bytes'] !== '') {
                $embedded = $this->registerEmbeddedImage($photoMeta['raw_bytes']);
            }

            if ($embedded !== null) {
                [$drawW, $drawH] = $this->calculateDisplayDimensions($embedded['width'], $embedded['height']);
                $totalPhotoBlockH = $promptHeight + $drawH + 28.0;

                // Keep question prompt + embedded photo + caption together on one page
                $this->ensureSpace($totalPhotoBlockH, "Continued — {$continuationContext}");

                foreach ($promptLines as $pLine) {
                    $this->drawText($pLine, self::MARGIN_LEFT + 8.0, $this->cursorY + 9.5, 'F2', 8.5, [0.10, 0.14, 0.18]);
                    $this->cursorY += 11.5;
                }

                $imgX = self::MARGIN_LEFT + 14.0;
                $imgTopY = $this->cursorY + 4.0;

                // Light frame behind the embedded photo
                $this->drawRect(
                    $imgX - 2.0,
                    $imgTopY - 2.0,
                    $drawW + 4.0,
                    $drawH + 4.0,
                    [1.0, 1.0, 1.0],
                    [0.80, 0.82, 0.85],
                    0.5
                );

                $this->drawEmbeddedImage($embedded['name'], $embedded['index'], $imgX, $imgTopY, $drawW, $drawH);
                $this->cursorY = $imgTopY + $drawH + 6.0;

                $caption = 'Photo: '.(string) ($photoMeta['display_name'] ?? 'photo');
                if (! empty($photoMeta['size_label'])) {
                    $caption .= ' ('.(string) $photoMeta['size_label'].')';
                }

                foreach ($this->wrapText($caption, $innerW - 8.0, 'F3', 7.5) as $capLine) {
                    $this->ensureSpace(11.0);
                    $this->drawText($capLine, $imgX, $this->cursorY + 7.5, 'F3', 7.5, [0.38, 0.42, 0.46]);
                    $this->cursorY += 10.0;
                }

                $this->drawQuestionDivider();

                return;
            }

            // Fallback when photo metadata exists on the submission but image bytes are missing/unreadable
            $fallbackName = (string) ($photoMeta['display_name'] ?? 'photo');
            $value = "[Photo file unavailable on disk: {$fallbackName}]";
        }

        $answerLines = $this->wrapText($value !== '' ? $value : '—', $innerW - 6.0, 'F1', 8.5);
        $minStartSpace = $promptHeight + min(count($answerLines), 2) * 11.5 + 10.0;
        $this->ensureSpace(min($minStartSpace, 120.0), "Continued — {$continuationContext}");

        foreach ($promptLines as $pLine) {
            if ($this->cursorY + 12.0 > $this->contentBottomY()) {
                $this->addPage();
                $this->renderContinuationNotice("Continued — {$continuationContext}");
            }
            $this->drawText($pLine, self::MARGIN_LEFT + 8.0, $this->cursorY + 9.5, 'F2', 8.5, [0.10, 0.14, 0.18]);
            $this->cursorY += 11.5;
        }

        $isUnanswered = ($value === '—' || $value === '');
        $answerFont = $isUnanswered ? 'F3' : 'F1';
        $answerColor = $isUnanswered ? [0.50, 0.53, 0.57] : [0.18, 0.21, 0.25];

        foreach ($answerLines as $aLine) {
            if ($this->cursorY + 12.0 > $this->contentBottomY()) {
                $this->addPage();
                $this->renderContinuationNotice("Continued — {$continuationContext} (Q{$questionNumber})");
            }
            $this->drawText($aLine, self::MARGIN_LEFT + 14.0, $this->cursorY + 9.5, $answerFont, 8.5, $answerColor);
            $this->cursorY += 11.5;
        }

        $this->drawQuestionDivider();
    }

    /**
     * Render an empty-results notice card when zero submissions match the active filters.
     */
    protected function renderEmptyStateCard(): void
    {
        $contentW = $this->contentWidth();
        $this->ensureSpace(56.0);
        $topY = $this->cursorY;

        $this->drawRect(
            self::MARGIN_LEFT,
            $topY,
            $contentW,
            46.0,
            [0.985, 0.982, 0.965],
            [0.83, 0.81, 0.74],
            0.6
        );

        $this->drawText(
            'No feedback submissions matched the selected filter criteria.',
            self::MARGIN_LEFT + 14.0,
            $topY + 20.0,
            'F2',
            9.5,
            [0.20, 0.24, 0.28]
        );
        $this->drawText(
            'Try broadening the active Event, Function, Agency, Designation, or Search filters.',
            self::MARGIN_LEFT + 14.0,
            $topY + 34.0,
            'F1',
            8.0,
            [0.42, 0.46, 0.50]
        );

        $this->cursorY = $topY + 54.0;
    }

    /**
     * Draw a subtle horizontal rule below a question/answer pair.
     */
    protected function drawQuestionDivider(): void
    {
        $y = $this->cursorY + 3.0;
        $this->drawLine(
            self::MARGIN_LEFT + 6.0,
            $y,
            self::MARGIN_LEFT + $this->contentWidth() - 6.0,
            $y,
            [0.89, 0.87, 0.82],
            0.4
        );
        $this->cursorY += 7.0;
    }

    /**
     * Render a small continuation label at the top of a new page when splitting a submission.
     */
    protected function renderContinuationNotice(string $notice): void
    {
        $this->drawText($notice, self::MARGIN_LEFT + 4.0, $this->cursorY + 8.0, 'F3', 7.5, [0.42, 0.46, 0.50]);
        $this->cursorY += 14.0;
    }

    /**
     * Calculate bounded PDF display dimensions in points while preserving aspect ratio.
     *
     * @return array{0: float, 1: float}
     */
    protected function calculateDisplayDimensions(int $pixelW, int $pixelH): array
    {
        $naturalW = max(1.0, $pixelW * 0.75);
        $naturalH = max(1.0, $pixelH * 0.75);

        // Ensure very small images remain clearly visible without exceeding bounded card dimensions
        if ($naturalW < 54.0 && $naturalH < 54.0) {
            $boost = 54.0 / max($naturalW, $naturalH);
            $naturalW *= $boost;
            $naturalH *= $boost;
        }

        $scale = min(
            1.0,
            self::MAX_IMAGE_PT_WIDTH / $naturalW,
            self::MAX_IMAGE_PT_HEIGHT / $naturalH
        );

        return [
            round($naturalW * $scale, 2),
            round($naturalH * $scale, 2),
        ];
    }

    /**
     * Format structured metadata entries into wrapped single-column lines.
     *
     * @param  array<int, array{label: string, value: string, bold?: bool}>  $entries
     * @return array<int, array{text: string, bold: bool}>
     */
    protected function formatMetadataEntryLines(array $entries, float $maxWidth): array
    {
        $lines = [];
        foreach ($entries as $entry) {
            $label = $entry['label'];
            $val = $entry['value'];
            $isBold = (bool) ($entry['bold'] ?? ($label === 'Event'));
            $wrapped = $this->wrapText("{$label}: {$val}", $maxWidth, 'F1', 8.0);
            foreach ($wrapped as $idx => $wLine) {
                $lines[] = [
                    'text' => $wLine,
                    'bold' => $idx === 0 && $isBold,
                ];
            }
        }

        return $lines;
    }

    /**
     * Ensure vertical space remains on the active page; otherwise add a new page.
     */
    protected function ensureSpace(float $requiredHeight, ?string $continuationNotice = null): void
    {
        if ($this->cursorY + $requiredHeight <= $this->contentBottomY()) {
            return;
        }

        $this->addPage();

        if ($continuationNotice !== null && $continuationNotice !== '') {
            $this->renderContinuationNotice($continuationNotice);
        }
    }

    /**
     * Append a new page and position the cursor at the top content area.
     */
    protected function addPage(bool $isCoverPage = false): void
    {
        $this->currentPageIndex = count($this->pages);
        $this->pages[$this->currentPageIndex] = [];
        $this->pageImages[$this->currentPageIndex] = [];

        if ($isCoverPage) {
            $this->cursorY = 36.0;
        } else {
            // Running top header on continuation pages
            $this->drawText(
                $this->runningTitle,
                self::MARGIN_LEFT,
                28.0,
                'F2',
                8.0,
                [0.35, 0.40, 0.38]
            );
            $this->drawLine(
                self::MARGIN_LEFT,
                34.0,
                self::PAGE_WIDTH - self::MARGIN_RIGHT,
                34.0,
                [0.84, 0.83, 0.78],
                0.5
            );
            $this->cursorY = self::MARGIN_TOP - 4.0;
        }
    }

    protected function contentWidth(): float
    {
        return self::PAGE_WIDTH - self::MARGIN_LEFT - self::MARGIN_RIGHT;
    }

    protected function contentBottomY(): float
    {
        return self::PAGE_HEIGHT - self::MARGIN_BOTTOM;
    }

    protected function toPdfY(float $topOriginY): float
    {
        return self::PAGE_HEIGHT - $topOriginY;
    }

    protected function emit(string $command, ?int $pageIndex = null): void
    {
        $target = $pageIndex ?? $this->currentPageIndex;
        $this->pages[$target][] = $command;
    }

    /**
     * Draw a single line of text (including inline full-color vector emojis) at top-origin coordinates.
     *
     * @param  array{0: float, 1: float, 2: float}  $rgb
     */
    protected function drawText(
        string $text,
        float $x,
        float $baselineTopY,
        string $fontKey,
        float $fontSize,
        array $rgb = [0.12, 0.14, 0.16],
        ?int $pageIndex = null
    ): void {
        if ($text === '') {
            return;
        }

        $prepared = $this->emojiRenderer->preprocessUnsupportedEmojis($text);
        $tokens = $this->emojiRenderer->tokenizeLine($prepared);
        if (empty($tokens)) {
            return;
        }

        $pdfY = $this->toPdfY($baselineTopY);
        $ratio = self::FONT_AVG_WIDTH[$fontKey] ?? 0.53;
        $curX = $x;

        foreach ($tokens as $token) {
            if ($token['type'] === 'emoji') {
                $rendered = $this->emojiRenderer->renderVectorEmoji($token['spec'], $curX, $pdfY, $fontSize);
                $this->emit($rendered['commands'], $pageIndex);
                $curX += $rendered['advance'];

                continue;
            }

            $winAnsi = $this->emojiRenderer->utf8ToWinAnsi($token['text']);
            if ($winAnsi === '') {
                continue;
            }

            $escaped = $this->escapePdfLiteral($winAnsi);
            $this->emit(sprintf(
                'BT /%s %.2f Tf %.3f %.3f %.3f rg 1 0 0 1 %.2f %.2f Tm (%s) Tj ET',
                $fontKey,
                $fontSize,
                $rgb[0],
                $rgb[1],
                $rgb[2],
                $curX,
                $pdfY,
                $escaped
            ), $pageIndex);

            $curX += strlen($winAnsi) * $fontSize * $ratio;
        }
    }

    /**
     * Draw a filled and/or stroked rectangle using top-origin coordinates.
     *
     * @param  array{0: float, 1: float, 2: float}|null  $fillRgb
     * @param  array{0: float, 1: float, 2: float}|null  $strokeRgb
     */
    protected function drawRect(
        float $x,
        float $topY,
        float $width,
        float $height,
        ?array $fillRgb = null,
        ?array $strokeRgb = null,
        float $lineWidth = 0.6
    ): void {
        $pdfBottomY = $this->toPdfY($topY + $height);
        $ops = ['q'];

        if ($fillRgb !== null) {
            $ops[] = sprintf('%.3f %.3f %.3f rg', $fillRgb[0], $fillRgb[1], $fillRgb[2]);
        }
        if ($strokeRgb !== null) {
            $ops[] = sprintf('%.3f %.3f %.3f RG %.2f w', $strokeRgb[0], $strokeRgb[1], $strokeRgb[2], $lineWidth);
        }

        $paintOp = match (true) {
            $fillRgb !== null && $strokeRgb !== null => 'B',
            $fillRgb !== null => 'f',
            default => 'S',
        };

        $ops[] = sprintf('%.2f %.2f %.2f %.2f re %s Q', $x, $pdfBottomY, $width, $height, $paintOp);
        $this->emit(implode(' ', $ops));
    }

    /**
     * Draw a line segment using top-origin coordinates.
     *
     * @param  array{0: float, 1: float, 2: float}  $rgb
     */
    protected function drawLine(
        float $x1,
        float $topY1,
        float $x2,
        float $topY2,
        array $rgb = [0.80, 0.80, 0.80],
        float $lineWidth = 0.5,
        ?int $pageIndex = null
    ): void {
        $this->emit(sprintf(
            'q %.3f %.3f %.3f RG %.2f w %.2f %.2f m %.2f %.2f l S Q',
            $rgb[0],
            $rgb[1],
            $rgb[2],
            $lineWidth,
            $x1,
            $this->toPdfY($topY1),
            $x2,
            $this->toPdfY($topY2)
        ), $pageIndex);
    }

    /**
     * Place an embedded Image XObject onto the active page.
     */
    protected function drawEmbeddedImage(
        string $imageName,
        int $imageIndex,
        float $x,
        float $topY,
        float $drawW,
        float $drawH
    ): void {
        $this->pageImages[$this->currentPageIndex][$imageName] = $imageIndex;
        $pdfBottomY = $this->toPdfY($topY + $drawH);

        $this->emit(sprintf(
            'q %.2f 0 0 %.2f %.2f %.2f cm /%s Do Q',
            $drawW,
            $drawH,
            $x,
            $pdfBottomY,
            $imageName
        ));
    }

    /**
     * Wrap multi-paragraph UTF-8 text (including emojis) to fit within `$maxWidth` points, breaking overlong tokens safely.
     *
     * @return array<int, string>
     */
    public function wrapText(string $text, float $maxWidth, string $fontKey = 'F1', float $fontSize = 8.5): array
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $this->emojiRenderer->preprocessUnsupportedEmojis($text));
        $paragraphs = explode("\n", $normalized);
        $avgCharW = max(2.5, $fontSize * (self::FONT_AVG_WIDTH[$fontKey] ?? 0.53));
        $maxCharsPerLine = max(12, (int) floor($maxWidth / $avgCharW));

        $lines = [];

        foreach ($paragraphs as $paragraph) {
            $trimmedRight = rtrim($paragraph);
            if ($trimmedRight === '') {
                $lines[] = '';

                continue;
            }

            $words = preg_split('/\s+/u', trim($trimmedRight)) ?: [];
            $current = '';

            foreach ($words as $word) {
                $chunks = $this->splitOverlongWord($word, $maxCharsPerLine);

                foreach ($chunks as $chunk) {
                    $candidate = $current === '' ? $chunk : "{$current} {$chunk}";
                    if ($current !== '' && $this->estimateTextWidth($candidate, $fontKey, $fontSize) > $maxWidth) {
                        $lines[] = $current;
                        $current = $chunk;
                    } else {
                        $current = $candidate;
                    }
                }
            }

            if ($current !== '') {
                $lines[] = $current;
            }
        }

        if (empty($lines)) {
            return [''];
        }

        return $lines;
    }

    /**
     * Split a single unbroken token by grapheme clusters so multi-byte emojis or ZWJ sequences are never cut in half.
     *
     * @return array<int, string>
     */
    protected function splitOverlongWord(string $word, int $maxChars): array
    {
        $graphemes = $this->emojiRenderer->splitGraphemes($word);
        if (count($graphemes) <= $maxChars) {
            return [$word];
        }

        $parts = [];
        $total = count($graphemes);
        for ($offset = 0; $offset < $total; $offset += $maxChars) {
            $parts[] = implode('', array_slice($graphemes, $offset, $maxChars));
        }

        return $parts;
    }

    protected function estimateTextWidth(string $text, string $fontKey, float $fontSize): float
    {
        $ratio = self::FONT_AVG_WIDTH[$fontKey] ?? 0.53;
        $prepared = $this->emojiRenderer->preprocessUnsupportedEmojis($text);
        $tokens = $this->emojiRenderer->tokenizeLine($prepared);

        $width = 0.0;
        foreach ($tokens as $token) {
            if ($token['type'] === 'emoji') {
                $kind = (string) ($token['spec']['kind'] ?? '');
                $mult = $kind === 'hundred' ? 1.48 : PdfEmojiRenderer::EMOJI_ADVANCE_RATIO;
                $width += $fontSize * $mult;
            } else {
                $winAnsi = $this->emojiRenderer->utf8ToWinAnsi($token['text']);
                $width += strlen($winAnsi) * $fontSize * $ratio;
            }
        }

        return $width;
    }

    /**
     * Convert UTF-8 text into single-byte Windows-1252 (`WinAnsiEncoding`) bytes per codepoint without `????`.
     */
    protected function toWinAnsi(string $text): string
    {
        return $this->emojiRenderer->utf8ToWinAnsi(
            $this->emojiRenderer->preprocessUnsupportedEmojis($text)
        );
    }

    /**
     * Escape single-byte WinAnsi bytes as a safe ASCII PDF literal string content.
     */
    protected function escapePdfLiteral(string $winAnsiBytes): string
    {
        $out = '';
        $len = strlen($winAnsiBytes);

        for ($i = 0; $i < $len; $i++) {
            $byte = ord($winAnsiBytes[$i]);
            $ch = $winAnsiBytes[$i];

            if ($ch === '\\' || $ch === '(' || $ch === ')') {
                $out .= '\\'.$ch;
            } elseif ($byte < 32 || $byte > 126) {
                $out .= sprintf('\\%03o', $byte);
            } else {
                $out .= $ch;
            }
        }

        return $out;
    }

    /**
     * Stamp running footers (`Page X of Y`) and serialize all objects + xref table into a valid PDF 1.4 binary.
     */
    protected function compilePdfBinary(string $generatedAt): string
    {
        $totalPages = count($this->pages);

        for ($p = 0; $p < $totalPages; $p++) {
            $footerY = self::PAGE_HEIGHT - 24.0;
            $this->drawLine(
                self::MARGIN_LEFT,
                $footerY - 10.0,
                self::PAGE_WIDTH - self::MARGIN_RIGHT,
                $footerY - 10.0,
                [0.85, 0.84, 0.79],
                0.5,
                $p
            );

            $this->drawText(
                "Generated {$generatedAt}  •  Authorized Admin Export",
                self::MARGIN_LEFT,
                $footerY,
                'F1',
                7.5,
                [0.45, 0.48, 0.52],
                $p
            );

            $pageLabel = sprintf('Page %d of %d', $p + 1, $totalPages);
            $labelW = $this->estimateTextWidth($pageLabel, 'F2', 7.5);
            $this->drawText(
                $pageLabel,
                self::PAGE_WIDTH - self::MARGIN_RIGHT - $labelW,
                $footerY,
                'F2',
                7.5,
                [0.30, 0.34, 0.38],
                $p
            );
        }

        /** @var array<int, string> $objects */
        $objects = [];

        // Reserve 1 = Catalog, 2 = Pages
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = '';

        // 3..6 = Standard Type1 Fonts with WinAnsiEncoding
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $objects[5] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Oblique /Encoding /WinAnsiEncoding >>';
        $objects[6] = '<< /Type /Font /Subtype /Type1 /BaseFont /Courier /Encoding /WinAnsiEncoding >>';

        $nextObjId = 7;

        // Register Image XObjects
        /** @var array<int, int> $imageObjIds */
        $imageObjIds = [];
        foreach ($this->images as $idx => $img) {
            $objId = $nextObjId++;
            $imageObjIds[$idx] = $objId;
            $byteLen = strlen($img['bytes']);
            $objects[$objId] = sprintf(
                "<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length %d >>\nstream\n%s\nendstream",
                $img['width'],
                $img['height'],
                $byteLen,
                $img['bytes']
            );
        }

        // Register Page + Content Stream objects
        $kidRefs = [];
        for ($p = 0; $p < $totalPages; $p++) {
            $contentObjId = $nextObjId++;
            $pageObjId = $nextObjId++;

            $streamBody = implode("\n", $this->pages[$p]);
            $streamLen = strlen($streamBody);
            $objects[$contentObjId] = "<< /Length {$streamLen} >>\nstream\n{$streamBody}\nendstream";

            $xObjectEntries = [];
            foreach ($this->pageImages[$p] as $imgName => $imgIdx) {
                if (isset($imageObjIds[$imgIdx])) {
                    $xObjectEntries[] = "/{$imgName} {$imageObjIds[$imgIdx]} 0 R";
                }
            }

            $xObjectDict = ! empty($xObjectEntries)
                ? ' /XObject << '.implode(' ', $xObjectEntries).' >>'
                : '';

            $resources = '<< /Font << /F1 3 0 R /F2 4 0 R /F3 5 0 R /F4 6 0 R >>'.$xObjectDict.' >>';

            $objects[$pageObjId] = sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2f %.2f] /Resources %s /Contents %d 0 R >>',
                self::PAGE_WIDTH,
                self::PAGE_HEIGHT,
                $resources,
                $contentObjId
            );

            $kidRefs[] = "{$pageObjId} 0 R";
        }

        $objects[2] = sprintf(
            '<< /Type /Pages /Kids [%s] /Count %d >>',
            implode(' ', $kidRefs),
            $totalPages
        );

        // Info dictionary
        $infoObjId = $nextObjId++;
        $escapedTitle = $this->escapePdfLiteral($this->toWinAnsi($this->runningTitle));
        $objects[$infoObjId] = "<< /Title ({$escapedTitle}) /Producer (Login Portal Native PDF 1.4 Builder) >>";

        ksort($objects);

        // Serialize binary PDF with exact byte-offset xref table
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [0 => 0];
        $maxId = $nextObjId - 1;

        for ($id = 1; $id <= $maxId; $id++) {
            $offsets[$id] = strlen($pdf);
            $pdf .= "{$id} 0 obj\n{$objects[$id]}\nendobj\n";
        }

        $xrefOffset = strlen($pdf);
        $totalEntries = $maxId + 1;
        $pdf .= "xref\n0 {$totalEntries}\n";
        $pdf .= "0000000000 65535 f \n";

        for ($id = 1; $id <= $maxId; $id++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$id]);
        }

        $pdf .= "trailer\n<< /Size {$totalEntries} /Root 1 0 R /Info {$infoObjId} 0 R >>\n";
        $pdf .= "startxref\n{$xrefOffset}\n%%EOF";

        return $pdf;
    }
}
