<?php

namespace App\Services;

use IntlChar;
use Normalizer;

class PdfEmojiRenderer
{
    /**
     * Exact Unicode codepoint -> Windows-1252 (`WinAnsiEncoding`) byte map for the `0x80..0x9F` range.
     *
     * @var array<int, int>
     */
    protected const WIN_ANSI_SPECIALS = [
        0x20AC => 0x80, // €
        0x201A => 0x82, // ‚
        0x0192 => 0x83, // ƒ
        0x201E => 0x84, // „
        0x2026 => 0x85, // …
        0x2020 => 0x86, // †
        0x2021 => 0x87, // ‡
        0x02C6 => 0x88, // ˆ
        0x2030 => 0x89, // ‰
        0x0160 => 0x8A, // Š
        0x2039 => 0x8B, // ‹
        0x0152 => 0x8C, // Œ
        0x017D => 0x8E, // Ž
        0x2018 => 0x91, // ‘
        0x2019 => 0x92, // ’
        0x201C => 0x93, // “
        0x201D => 0x94, // ”
        0x2022 => 0x95, // •
        0x2013 => 0x96, // –
        0x2014 => 0x97, // —
        0x02DC => 0x98, // ˜
        0x2122 => 0x99, // ™
        0x0161 => 0x9A, // š
        0x203A => 0x9B, // ›
        0x0153 => 0x9C, // œ
        0x017E => 0x9E, // ž
        0x0178 => 0x9F, // Ÿ
    ];

    /**
     * Deterministic ASCII/WinAnsi substitutions for non-emoji symbols outside Windows-1252.
     *
     * @var array<int, string>
     */
    protected const SYMBOL_REPLACEMENTS = [
        0x20B1 => 'PHP ', // ₱ Philippine Peso
        0x2190 => '<-',   // ←
        0x2192 => '->',   // →
        0x2191 => '^',    // ↑
        0x2193 => 'v',    // ↓
        0x2197 => '',     // ↗
        0x2264 => '<=',   // ≤
        0x2265 => '>=',   // ≥
        0x2260 => '!=',   // ≠
        0x2248 => '~=',   // ≈
        0x2713 => '[x]',  // ✓
        0x2714 => '[x]',  // ✔
        0x2715 => 'x',    // ✕
        0x2716 => 'x',    // ✖
        0x2717 => 'x',    // ✗
        0x2718 => 'x',    // ✘
        0x25CF => '*',    // ●
        0x25CB => 'o',    // ○
        0x25AA => '-',    // ▪
        0x25AB => '-',    // ▫
    ];

    /**
     * Advance width multiplier (relative to font size) for an inline vector emoji glyph.
     */
    public const EMOJI_ADVANCE_RATIO = 1.18;

    /**
     * Replace only non-vectorized/rare emojis with readable `[name]` labels while preserving all
     * vector-supported emojis so line wrapping and rendering handle both cleanly.
     */
    public function preprocessUnsupportedEmojis(string $text): string
    {
        return preg_replace_callback('/\X/u', function (array $match): string {
            $grapheme = $match[0];

            if ($this->resolveVectorSpec($grapheme) !== null) {
                return $grapheme;
            }

            if ($this->isEmojiGrapheme($grapheme)) {
                return $this->describeEmojiGrapheme($grapheme);
            }

            return $grapheme;
        }, $text) ?? $text;
    }

    /**
     * Split UTF-8 text into grapheme clusters (`\X`) safely without breaking multi-byte emojis or ZWJ sequences.
     *
     * @return array<int, string>
     */
    public function splitGraphemes(string $text): array
    {
        if ($text === '') {
            return [];
        }

        if (preg_match_all('/\X/u', $text, $matches) && ! empty($matches[0])) {
            return $matches[0];
        }

        return mb_str_split($text, 1, 'UTF-8');
    }

    /**
     * Tokenize a single line of text into alternating `text` and vector `emoji` runs.
     *
     * @return array<int, array{type: 'text', text: string}|array{type: 'emoji', grapheme: string, spec: array<string, mixed>}>
     */
    public function tokenizeLine(string $line): array
    {
        $tokens = [];
        $textBuffer = '';

        foreach ($this->splitGraphemes($line) as $grapheme) {
            $spec = $this->resolveVectorSpec($grapheme);
            if ($spec !== null) {
                if ($textBuffer !== '') {
                    $tokens[] = ['type' => 'text', 'text' => $textBuffer];
                    $textBuffer = '';
                }
                $tokens[] = [
                    'type' => 'emoji',
                    'grapheme' => $grapheme,
                    'spec' => $spec,
                ];
            } else {
                $textBuffer .= $grapheme;
            }
        }

        if ($textBuffer !== '') {
            $tokens[] = ['type' => 'text', 'text' => $textBuffer];
        }

        return $tokens;
    }

    /**
     * Convert a UTF-8 text segment into Windows-1252 (`WinAnsiEncoding`) single-byte string per codepoint,
     * never failing whole lines or emitting `????`.
     */
    public function utf8ToWinAnsi(string $text): string
    {
        $clean = str_replace("\t", '    ', $text);
        $clean = preg_replace('/[\x00-\x1F\x7F]/', ' ', $clean) ?? $clean;

        $out = '';
        foreach (mb_str_split($clean, 1, 'UTF-8') as $char) {
            $cp = mb_ord($char, 'UTF-8');
            if ($cp === false) {
                continue;
            }

            // Skip zero-width joiners, variation selectors, and directional marks
            if (
                ($cp >= 0xFE00 && $cp <= 0xFE0F) ||
                ($cp >= 0x200B && $cp <= 0x200F) ||
                $cp === 0xFEFF
            ) {
                continue;
            }

            if ($cp >= 0x20 && $cp <= 0x7E) {
                $out .= chr($cp);

                continue;
            }

            if ($cp === 0xA0) {
                $out .= ' ';

                continue;
            }

            if ($cp >= 0xA1 && $cp <= 0xFF) {
                $out .= chr($cp);

                continue;
            }

            if (isset(self::WIN_ANSI_SPECIALS[$cp])) {
                $out .= chr(self::WIN_ANSI_SPECIALS[$cp]);

                continue;
            }

            if (isset(self::SYMBOL_REPLACEMENTS[$cp])) {
                $out .= self::SYMBOL_REPLACEMENTS[$cp];

                continue;
            }

            // Decompose accented characters outside Latin-1 (e.g. ā -> a, š -> s)
            if (class_exists(Normalizer::class)) {
                $decomposed = Normalizer::normalize($char, Normalizer::FORM_D);
                if (is_string($decomposed) && $decomposed !== '') {
                    $stripped = preg_replace('/\p{Mn}+/u', '', $decomposed) ?? '';
                    if ($stripped !== '' && preg_match('/^[\x20-\x7E]+$/', $stripped)) {
                        $out .= $stripped;

                        continue;
                    }
                }
            }

            // Last-resort readable character name via IntlChar instead of `?`
            if (class_exists(IntlChar::class)) {
                $name = IntlChar::charName($cp);
                if (is_string($name) && $name !== '') {
                    $short = strtolower($name);
                    $out .= "[{$short}]";

                    continue;
                }
            }

            $out .= '';
        }

        return $out;
    }

    /**
     * Resolve a vector drawing specification if this grapheme matches a supported inline PDF vector emoji.
     *
     * @return array<string, mixed>|null
     */
    public function resolveVectorSpec(string $grapheme): ?array
    {
        $codepoints = $this->extractSignificantCodepoints($grapheme);
        if (empty($codepoints)) {
            return null;
        }

        // Keycap sequences: `0`..`9` or `#` or `*` + U+20E3
        if (in_array(0x20E3, $codepoints, true)) {
            $first = $codepoints[0];
            if (($first >= 0x30 && $first <= 0x39) || $first === 0x23 || $first === 0x2A) {
                return ['kind' => 'badge', 'label' => chr($first), 'bg' => [0.22, 0.49, 0.88]];
            }
        }

        // Regional indicator country flags (two codepoints in U+1F1E6..U+1F1FF, e.g. 🇵🇭 -> PH)
        if (
            count($codepoints) === 2 &&
            $codepoints[0] >= 0x1F1E6 && $codepoints[0] <= 0x1F1FF &&
            $codepoints[1] >= 0x1F1E6 && $codepoints[1] <= 0x1F1FF
        ) {
            $iso = chr($codepoints[0] - 0x1F1E6 + 65).chr($codepoints[1] - 0x1F1E6 + 65);

            return ['kind' => 'badge', 'label' => $iso, 'bg' => [0.15, 0.36, 0.28]];
        }

        // Do not Collapse multi-person ZWJ sequences into a single base face
        if (in_array(0x200D, $codepoints, true)) {
            return null;
        }

        $primary = $codepoints[0];

        return match ($primary) {
            // Stars & Sparkles
            0x2B50, 0x1F31F, 0x2605, 0x272A => ['kind' => 'star', 'filled' => true],
            0x2606 => ['kind' => 'star', 'filled' => false],
            0x2728, 0x1F4AB => ['kind' => 'sparkles'],

            // Warnings, Checks, Crosses & Status Symbols
            0x26A0 => ['kind' => 'warning'],
            0x2705, 0x2611 => ['kind' => 'check'],
            0x274C, 0x274E => ['kind' => 'cross'],
            0x2753, 0x2754 => ['kind' => 'badge', 'label' => '?', 'bg' => [0.86, 0.24, 0.24]],
            0x2757, 0x2755, 0x203C => ['kind' => 'badge', 'label' => '!', 'bg' => [0.88, 0.22, 0.20]],
            0x1F51F => ['kind' => 'badge', 'label' => '10', 'bg' => [0.22, 0.49, 0.88]],
            0x1F4AF => ['kind' => 'hundred'],

            // Hearts
            0x2764, 0x2665, 0x1F495, 0x1F496, 0x1F497, 0x1F498, 0x1F493, 0x1F49E, 0x2763, 0x1FAF6 => [
                'kind' => 'heart',
                'rgb' => [0.90, 0.20, 0.26],
                'broken' => false,
            ],
            0x1F9E1 => ['kind' => 'heart', 'rgb' => [0.96, 0.52, 0.16], 'broken' => false],
            0x1F49B => ['kind' => 'heart', 'rgb' => [0.95, 0.78, 0.14], 'broken' => false],
            0x1F49A => ['kind' => 'heart', 'rgb' => [0.16, 0.72, 0.38], 'broken' => false],
            0x1F499 => ['kind' => 'heart', 'rgb' => [0.20, 0.54, 0.92], 'broken' => false],
            0x1F49C => ['kind' => 'heart', 'rgb' => [0.60, 0.32, 0.88], 'broken' => false],
            0x1F5A4 => ['kind' => 'heart', 'rgb' => [0.18, 0.20, 0.24], 'broken' => false],
            0x1F90D => ['kind' => 'heart', 'rgb' => [0.90, 0.92, 0.95], 'broken' => false],
            0x1F494 => ['kind' => 'heart', 'rgb' => [0.90, 0.20, 0.26], 'broken' => true],

            // Colored Status Circles
            0x1F534 => ['kind' => 'dot', 'rgb' => [0.90, 0.22, 0.22]],
            0x1F7E0 => ['kind' => 'dot', 'rgb' => [0.95, 0.52, 0.15]],
            0x1F7E1 => ['kind' => 'dot', 'rgb' => [0.95, 0.78, 0.15]],
            0x1F7E2 => ['kind' => 'dot', 'rgb' => [0.16, 0.72, 0.36]],
            0x1F535 => ['kind' => 'dot', 'rgb' => [0.22, 0.52, 0.90]],

            // Sentiment / Survey Faces
            0x1F600, 0x1F603, 0x1F604, 0x1F601, 0x1F606, 0x1F605 => ['kind' => 'face', 'mood' => 'grin'],
            0x1F60A, 0x263A, 0x1F642, 0x1F607, 0x1F970, 0x1F917, 0x1F979, 0x1FAE1, 0x1F60B => ['kind' => 'face', 'mood' => 'smile'],
            0x1F609 => ['kind' => 'face', 'mood' => 'wink'],
            0x1F60D => ['kind' => 'face', 'mood' => 'heart_eyes'],
            0x1F929 => ['kind' => 'face', 'mood' => 'star_eyes'],
            0x1F602, 0x1F923 => ['kind' => 'face', 'mood' => 'joy'],
            0x1F60E => ['kind' => 'face', 'mood' => 'cool'],
            0x1F973 => ['kind' => 'face', 'mood' => 'party'],
            0x1F610, 0x1F611, 0x1F636, 0x1F62C, 0x1F634 => ['kind' => 'face', 'mood' => 'neutral'],
            0x1F914, 0x1F928 => ['kind' => 'face', 'mood' => 'thinking'],
            0x1F62E, 0x1F62F, 0x1F632, 0x1F631, 0x1F92F => ['kind' => 'face', 'mood' => 'surprised'],
            0x1F61E, 0x1F614, 0x2639, 0x1F641, 0x1F615, 0x1F61F, 0x1F629, 0x1F62B, 0x1F612, 0x1F613 => ['kind' => 'face', 'mood' => 'sad'],
            0x1F622, 0x1F62D, 0x1F625 => ['kind' => 'face', 'mood' => 'cry'],
            0x1F620, 0x1F621, 0x1F92C, 0x1F624 => ['kind' => 'face', 'mood' => 'angry'],

            // Thumbs, Hands, Medals, Fire & Celebration
            0x1F44D => ['kind' => 'thumb', 'up' => true],
            0x1F44E => ['kind' => 'thumb', 'up' => false],
            0x1F525 => ['kind' => 'fire'],
            0x1F389, 0x1F38A => ['kind' => 'confetti'],
            0x1F947, 0x1F3C6 => ['kind' => 'badge', 'label' => '1st', 'bg' => [0.85, 0.62, 0.08]],
            0x1F948 => ['kind' => 'badge', 'label' => '2nd', 'bg' => [0.52, 0.56, 0.62]],
            0x1F949 => ['kind' => 'badge', 'label' => '3rd', 'bg' => [0.72, 0.44, 0.22]],
            0x1F44F, 0x1F64C => ['kind' => 'badge', 'label' => 'CLAP', 'bg' => [0.85, 0.56, 0.12]],
            0x1F64F => ['kind' => 'badge', 'label' => 'THX', 'bg' => [0.22, 0.52, 0.68]],
            0x1F44C => ['kind' => 'badge', 'label' => 'OK', 'bg' => [0.16, 0.62, 0.38]],

            default => null,
        };
    }

    /**
     * Render inline PDF 1.4 vector operators for a supported emoji at `($x, $pdfBaselineY)`.
     *
     * @param  array<string, mixed>  $spec
     * @return array{commands: string, advance: float}
     */
    public function renderVectorEmoji(array $spec, float $x, float $pdfBaselineY, float $fontSize): array
    {
        $size = max(6.0, $fontSize * 1.02);
        $bottomY = $pdfBaselineY - ($fontSize * 0.14);
        $cx = $x + ($size * 0.5);
        $cy = $bottomY + ($size * 0.5);
        $r = $size * 0.45;
        $advance = $fontSize * self::EMOJI_ADVANCE_RATIO;

        $kind = (string) ($spec['kind'] ?? 'dot');
        $ops = ['q 1 J 1 j'];

        switch ($kind) {
            case 'star':
                $ops[] = $this->drawStarCommands($cx, $cy, $r * 1.04, ! empty($spec['filled']));
                break;

            case 'sparkles':
                $ops[] = $this->drawSparklesCommands($cx, $cy, $r);
                break;

            case 'warning':
                $ops[] = $this->drawWarningCommands($cx, $bottomY, $size);
                break;

            case 'check':
                $ops[] = $this->drawCheckBadgeCommands($x + ($size * 0.06), $bottomY + ($size * 0.06), $size * 0.88);
                break;

            case 'cross':
                $ops[] = $this->drawCrossBadgeCommands($x + ($size * 0.06), $bottomY + ($size * 0.06), $size * 0.88);
                break;

            case 'heart':
                /** @var array{0: float, 1: float, 2: float} $rgb */
                $rgb = $spec['rgb'] ?? [0.90, 0.20, 0.26];
                $ops[] = $this->drawHeartCommands($cx, $cy, $r * 1.02, $rgb, ! empty($spec['broken']));
                break;

            case 'dot':
                /** @var array{0: float, 1: float, 2: float} $rgb */
                $rgb = $spec['rgb'] ?? [0.22, 0.52, 0.90];
                $ops[] = sprintf('%.3f %.3f %.3f rg %s f', $rgb[0], $rgb[1], $rgb[2], $this->circlePath($cx, $cy, $r * 0.85));
                break;

            case 'face':
                $ops[] = $this->drawFaceCommands($cx, $cy, $r, (string) ($spec['mood'] ?? 'smile'));
                break;

            case 'thumb':
                $ops[] = $this->drawThumbCommands($x, $bottomY, $size, ! empty($spec['up']));
                break;

            case 'fire':
                $ops[] = $this->drawFireCommands($cx, $bottomY, $size);
                break;

            case 'confetti':
                $ops[] = $this->drawConfettiCommands($x, $bottomY, $size);
                break;

            case 'hundred':
                $ops[] = $this->drawHundredCommands($x, $pdfBaselineY, $size, $fontSize);
                $advance = $fontSize * 1.48;
                break;

            case 'badge':
                $label = (string) ($spec['label'] ?? '?');
                /** @var array{0: float, 1: float, 2: float} $bg */
                $bg = $spec['bg'] ?? [0.22, 0.49, 0.88];
                [$badgeCmd, $badgeW] = $this->drawMiniBadgeCommands($x, $bottomY, $size, $fontSize, $label, $bg);
                $ops[] = $badgeCmd;
                $advance = $badgeW + ($fontSize * 0.18);
                break;
        }

        $ops[] = 'Q';

        return [
            'commands' => implode(' ', array_filter($ops)),
            'advance' => $advance,
        ];
    }

    /**
     * Determine whether a UTF-8 grapheme cluster contains Unicode emoji / pictographic codepoints.
     */
    public function isEmojiGrapheme(string $grapheme): bool
    {
        if (preg_match('/\p{Extended_Pictographic}/u', $grapheme) === 1) {
            return true;
        }

        foreach ($this->extractSignificantCodepoints($grapheme) as $cp) {
            if (
                $cp === 0x20E3 ||
                ($cp >= 0x1F1E6 && $cp <= 0x1F1FF) ||
                ($cp >= 0x1F300 && $cp <= 0x1FAFF) ||
                ($cp >= 0x2600 && $cp <= 0x27BF) ||
                ($cp >= 0x2B50 && $cp <= 0x2B55)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Convert an arbitrary rare emoji grapheme into a concise readable `[label]` using `IntlChar`.
     */
    protected function describeEmojiGrapheme(string $grapheme): string
    {
        $names = [];

        foreach ($this->extractSignificantCodepoints($grapheme) as $cp) {
            if ($cp === 0x200D) {
                continue;
            }

            if (class_exists(IntlChar::class)) {
                $rawName = IntlChar::charName($cp);
                if (is_string($rawName) && $rawName !== '') {
                    $cleaned = strtolower($rawName);
                    $cleaned = str_replace(
                        ['emoji modifier fitzpatrick type-1-2', 'emoji modifier fitzpatrick type-3', 'emoji modifier fitzpatrick type-4', 'emoji modifier fitzpatrick type-5', 'emoji modifier fitzpatrick type-6', 'sign', 'symbol'],
                        '',
                        $cleaned
                    );
                    $cleaned = trim(preg_replace('/\s+/', ' ', $cleaned) ?? $cleaned);
                    if ($cleaned !== '') {
                        $names[] = $cleaned;
                    }
                }
            }
        }

        if (empty($names)) {
            return '[emoji]';
        }

        return '['.implode(' ', array_unique($names)).']';
    }

    /**
     * Extract meaningful codepoints from a grapheme cluster (stripping variation selectors and skin-tone modifiers).
     *
     * @return array<int, int>
     */
    protected function extractSignificantCodepoints(string $grapheme): array
    {
        $cps = [];
        foreach (mb_str_split($grapheme, 1, 'UTF-8') as $ch) {
            $cp = mb_ord($ch, 'UTF-8');
            if ($cp === false) {
                continue;
            }

            // Strip variation selectors (FE0E/FE0F) and Fitzpatrick skin-tone modifiers (1F3FB..1F3FF)
            if (
                ($cp >= 0xFE00 && $cp <= 0xFE0F) ||
                ($cp >= 0x1F3FB && $cp <= 0x1F3FF)
            ) {
                continue;
            }

            $cps[] = $cp;
        }

        return $cps;
    }

    protected function drawStarCommands(float $cx, float $cy, float $outerR, bool $filled): string
    {
        $innerR = $outerR * 0.42;
        $pts = [];

        for ($i = 0; $i < 10; $i++) {
            $angle = deg2rad(90.0 + ($i * 36.0));
            $rad = ($i % 2 === 0) ? $outerR : $innerR;
            $px = $cx + ($rad * cos($angle));
            $py = $cy + ($rad * sin($angle));
            $pts[] = sprintf('%.2f %.2f %s', $px, $py, $i === 0 ? 'm' : 'l');
        }

        $path = implode(' ', $pts).' h';

        if ($filled) {
            return sprintf('0.980 0.750 0.120 rg 0.820 0.520 0.040 RG 0.45 w %s B', $path);
        }

        return sprintf('0.820 0.520 0.040 RG 0.65 w %s S', $path);
    }

    protected function drawSparklesCommands(float $cx, float $cy, float $r): string
    {
        $main = $this->fourPointStarPath($cx - ($r * 0.15), $cy - ($r * 0.05), $r * 0.85, $r * 0.26);
        $sub = $this->fourPointStarPath($cx + ($r * 0.52), $cy + ($r * 0.48), $r * 0.44, $r * 0.14);

        return sprintf('0.980 0.760 0.140 rg 0.840 0.550 0.050 RG 0.35 w %s B %s f', $main, $sub);
    }

    protected function fourPointStarPath(float $cx, float $cy, float $outer, float $inner): string
    {
        $pts = [];
        for ($i = 0; $i < 8; $i++) {
            $angle = deg2rad($i * 45.0);
            $rad = ($i % 2 === 0) ? $outer : $inner;
            $pts[] = sprintf('%.2f %.2f %s', $cx + $rad * cos($angle), $cy + $rad * sin($angle), $i === 0 ? 'm' : 'l');
        }

        return implode(' ', $pts).' h';
    }

    protected function drawWarningCommands(float $cx, float $bottomY, float $size): string
    {
        $halfW = $size * 0.48;
        $topY = $bottomY + ($size * 0.94);
        $baseY = $bottomY + ($size * 0.06);

        $tri = sprintf(
            '%.2f %.2f m %.2f %.2f l %.2f %.2f l h',
            $cx,
            $topY,
            $cx + $halfW,
            $baseY,
            $cx - $halfW,
            $baseY
        );

        $bangTop = $bottomY + ($size * 0.64);
        $bangBot = $bottomY + ($size * 0.35);
        $dotCy = $bottomY + ($size * 0.21);

        return sprintf(
            '0.985 0.765 0.140 rg 0.720 0.360 0.040 RG 0.55 w %s B 0.150 0.130 0.100 RG %.2f w %.2f %.2f m %.2f %.2f l S 0.150 0.130 0.100 rg %s f',
            $tri,
            max(0.8, $size * 0.11),
            $cx,
            $bangTop,
            $cx,
            $bangBot,
            $this->circlePath($cx, $dotCy, max(0.55, $size * 0.06))
        );
    }

    protected function drawCheckBadgeCommands(float $bx, float $by, float $boxSize): string
    {
        $x1 = $bx + ($boxSize * 0.23);
        $y1 = $by + ($boxSize * 0.48);
        $x2 = $bx + ($boxSize * 0.43);
        $y2 = $by + ($boxSize * 0.26);
        $x3 = $bx + ($boxSize * 0.79);
        $y3 = $by + ($boxSize * 0.73);

        return sprintf(
            '0.100 0.660 0.340 rg %.2f %.2f %.2f %.2f re f 1.000 1.000 1.000 RG %.2f w %.2f %.2f m %.2f %.2f l %.2f %.2f l S',
            $bx,
            $by,
            $boxSize,
            $boxSize,
            max(0.9, $boxSize * 0.14),
            $x1,
            $y1,
            $x2,
            $y2,
            $x3,
            $y3
        );
    }

    protected function drawCrossBadgeCommands(float $bx, float $by, float $boxSize): string
    {
        $pad = $boxSize * 0.25;

        return sprintf(
            '0.880 0.200 0.230 rg %.2f %.2f %.2f %.2f re f 1.000 1.000 1.000 RG %.2f w %.2f %.2f m %.2f %.2f l %.2f %.2f m %.2f %.2f l S',
            $bx,
            $by,
            $boxSize,
            $boxSize,
            max(0.9, $boxSize * 0.14),
            $bx + $pad,
            $by + $pad,
            $bx + $boxSize - $pad,
            $by + $boxSize - $pad,
            $bx + $pad,
            $by + $boxSize - $pad,
            $bx + $boxSize - $pad,
            $by + $pad
        );
    }

    /**
     * @param  array{0: float, 1: float, 2: float}  $rgb
     */
    protected function drawHeartCommands(float $cx, float $cy, float $r, array $rgb, bool $broken): string
    {
        $topDipY = $cy + ($r * 0.32);
        $botTipY = $cy - ($r * 0.85);

        $path = sprintf(
            '%.2f %.2f m %.2f %.2f %.2f %.2f %.2f %.2f c %.2f %.2f %.2f %.2f %.2f %.2f c %.2f %.2f %.2f %.2f %.2f %.2f c %.2f %.2f %.2f %.2f %.2f %.2f c h',
            $cx,
            $botTipY,
            $cx - ($r * 1.15),
            $cy - ($r * 0.15),
            $cx - ($r * 1.05),
            $cy + ($r * 0.95),
            $cx - ($r * 0.45),
            $cy + ($r * 0.90),
            $cx - ($r * 0.15),
            $cy + ($r * 0.88),
            $cx,
            $cy + ($r * 0.58),
            $cx,
            $topDipY,
            $cx,
            $cy + ($r * 0.58),
            $cx + ($r * 0.15),
            $cy + ($r * 0.88),
            $cx + ($r * 0.45),
            $cy + ($r * 0.90),
            $cx + ($r * 1.05),
            $cy + ($r * 0.95),
            $cx + ($r * 1.15),
            $cy - ($r * 0.15),
            $cx,
            $botTipY
        );

        $cmd = sprintf(
            '%.3f %.3f %.3f rg 0.550 0.150 0.180 RG 0.35 w %s B',
            $rgb[0],
            $rgb[1],
            $rgb[2],
            $path
        );

        if ($broken) {
            $cmd .= sprintf(
                ' 1.000 1.000 1.000 RG %.2f w %.2f %.2f m %.2f %.2f l %.2f %.2f l %.2f %.2f l S',
                max(0.65, $r * 0.22),
                $cx,
                $topDipY + ($r * 0.10),
                $cx - ($r * 0.18),
                $cy + ($r * 0.02),
                $cx + ($r * 0.14),
                $cy - ($r * 0.22),
                $cx - ($r * 0.05),
                $botTipY + ($r * 0.18)
            );
        }

        return $cmd;
    }

    protected function drawFaceCommands(float $cx, float $cy, float $r, string $mood): string
    {
        $isAngry = $mood === 'angry';
        $fill = $isAngry ? [0.975, 0.480, 0.340] : [0.990, 0.805, 0.180];
        $stroke = $isAngry ? [0.760, 0.210, 0.140] : [0.800, 0.530, 0.050];
        $featureRgb = '0.240 0.150 0.060';

        $cmds = [
            sprintf(
                '%.3f %.3f %.3f rg %.3f %.3f %.3f RG 0.48 w %s B',
                $fill[0],
                $fill[1],
                $fill[2],
                $stroke[0],
                $stroke[1],
                $stroke[2],
                $this->circlePath($cx, $cy, $r)
            ),
        ];

        $eyeOffsetX = $r * 0.34;
        $eyeY = $cy + ($r * 0.20);
        $eyeR = max(0.45, $r * 0.115);
        $strokeW = max(0.55, $r * 0.15);

        // Eyes by mood
        if ($mood === 'heart_eyes') {
            $cmds[] = $this->drawHeartCommands($cx - $eyeOffsetX, $eyeY, $r * 0.28, [0.90, 0.16, 0.22], false);
            $cmds[] = $this->drawHeartCommands($cx + $eyeOffsetX, $eyeY, $r * 0.28, [0.90, 0.16, 0.22], false);
        } elseif ($mood === 'star_eyes') {
            $cmds[] = $this->drawStarCommands($cx - $eyeOffsetX, $eyeY, $r * 0.32, true);
            $cmds[] = $this->drawStarCommands($cx + $eyeOffsetX, $eyeY, $r * 0.32, true);
        } elseif ($mood === 'cool') {
            $lensW = $r * 0.56;
            $lensH = $r * 0.34;
            $cmds[] = sprintf(
                '0.120 0.140 0.180 rg %.2f %.2f %.2f %.2f re f %.2f %.2f %.2f %.2f re f 0.120 0.140 0.180 RG %.2f w %.2f %.2f m %.2f %.2f l S',
                $cx - ($r * 0.72),
                $eyeY - ($lensH * 0.5),
                $lensW,
                $lensH,
                $cx + ($r * 0.16),
                $eyeY - ($lensH * 0.5),
                $lensW,
                $lensH,
                max(0.5, $r * 0.12),
                $cx - ($r * 0.92),
                $eyeY + ($lensH * 0.25),
                $cx + ($r * 0.92),
                $eyeY + ($lensH * 0.25)
            );
        } elseif ($mood === 'wink') {
            $cmds[] = sprintf('%s rg %s f', $featureRgb, $this->circlePath($cx - $eyeOffsetX, $eyeY, $eyeR));
            $cmds[] = sprintf(
                '%s RG %.2f w %.2f %.2f m %.2f %.2f l S',
                $featureRgb,
                $strokeW,
                $cx + ($eyeOffsetX * 0.55),
                $eyeY,
                $cx + ($eyeOffsetX * 1.45),
                $eyeY
            );
        } elseif ($mood === 'joy') {
            $cmds[] = sprintf(
                '%s RG %.2f w %.2f %.2f m %.2f %.2f l %.2f %.2f l S %.2f %.2f m %.2f %.2f l %.2f %.2f l S',
                $featureRgb,
                $strokeW * 0.9,
                $cx - ($r * 0.52),
                $eyeY + ($r * 0.10),
                $cx - ($r * 0.24),
                $eyeY,
                $cx - ($r * 0.52),
                $eyeY - ($r * 0.10),
                $cx + ($r * 0.52),
                $eyeY + ($r * 0.10),
                $cx + ($r * 0.24),
                $eyeY,
                $cx + ($r * 0.52),
                $eyeY - ($r * 0.10)
            );
        } else {
            $cmds[] = sprintf(
                '%s rg %s f %s f',
                $featureRgb,
                $this->circlePath($cx - $eyeOffsetX, $eyeY, $eyeR),
                $this->circlePath($cx + $eyeOffsetX, $eyeY, $eyeR)
            );
        }

        // Angry furrowed eyebrows
        if ($isAngry) {
            $cmds[] = sprintf(
                '%s RG %.2f w %.2f %.2f m %.2f %.2f l %.2f %.2f m %.2f %.2f l S',
                $featureRgb,
                $strokeW,
                $cx - ($r * 0.55),
                $eyeY + ($r * 0.30),
                $cx - ($r * 0.16),
                $eyeY + ($r * 0.14),
                $cx + ($r * 0.55),
                $eyeY + ($r * 0.30),
                $cx + ($r * 0.16),
                $eyeY + ($r * 0.14)
            );
        }

        // Rosy cheeks on warm smile
        if ($mood === 'smile') {
            $cmds[] = sprintf(
                '0.960 0.520 0.460 rg %s f %s f',
                $this->circlePath($cx - ($r * 0.54), $cy - ($r * 0.08), $r * 0.14),
                $this->circlePath($cx + ($r * 0.54), $cy - ($r * 0.08), $r * 0.14)
            );
        }

        // Mouth & tears by mood
        $mouthW = $r * 0.46;
        if (in_array($mood, ['grin', 'joy', 'star_eyes'], true)) {
            $my = $cy - ($r * 0.12);
            $cmds[] = sprintf(
                '%s rg %.2f %.2f m %.2f %.2f l %.2f %.2f %.2f %.2f %.2f %.2f c h f',
                $featureRgb,
                $cx - $mouthW,
                $my,
                $cx + $mouthW,
                $my,
                $cx + ($mouthW * 0.75),
                $cy - ($r * 0.64),
                $cx - ($mouthW * 0.75),
                $cy - ($r * 0.64),
                $cx - $mouthW,
                $my
            );
        } elseif (in_array($mood, ['smile', 'wink', 'heart_eyes', 'cool', 'party'], true)) {
            $my = $cy - ($r * 0.18);
            $cmds[] = sprintf(
                '%s RG %.2f w %.2f %.2f m %.2f %.2f %.2f %.2f %.2f %.2f c S',
                $featureRgb,
                $strokeW,
                $cx - $mouthW,
                $my,
                $cx - ($mouthW * 0.45),
                $cy - ($r * 0.56),
                $cx + ($mouthW * 0.45),
                $cy - ($r * 0.56),
                $cx + $mouthW,
                $my
            );
        } elseif ($mood === 'neutral' || $mood === 'thinking') {
            $my = $cy - ($r * 0.32);
            $tilt = $mood === 'thinking' ? ($r * 0.08) : 0.0;
            $cmds[] = sprintf(
                '%s RG %.2f w %.2f %.2f m %.2f %.2f l S',
                $featureRgb,
                $strokeW,
                $cx - ($mouthW * 0.8),
                $my - $tilt,
                $cx + ($mouthW * 0.8),
                $my + $tilt
            );
        } elseif ($mood === 'surprised') {
            $cmds[] = sprintf(
                '%s rg %s f',
                $featureRgb,
                $this->circlePath($cx, $cy - ($r * 0.34), $r * 0.21)
            );
        } else {
            // Sad, Cry, or Angry downward frown
            $my = $cy - ($r * 0.46);
            $cmds[] = sprintf(
                '%s RG %.2f w %.2f %.2f m %.2f %.2f %.2f %.2f %.2f %.2f c S',
                $featureRgb,
                $strokeW,
                $cx - $mouthW,
                $my,
                $cx - ($mouthW * 0.45),
                $cy - ($r * 0.14),
                $cx + ($mouthW * 0.45),
                $cy - ($r * 0.14),
                $cx + $mouthW,
                $my
            );
        }

        // Blue teardrops for cry / joy
        if ($mood === 'cry') {
            $cmds[] = sprintf(
                '0.200 0.620 0.960 rg %s f',
                $this->circlePath($cx + $eyeOffsetX, $cy - ($r * 0.08), max(0.55, $r * 0.14))
            );
        } elseif ($mood === 'joy') {
            $cmds[] = sprintf(
                '0.200 0.620 0.960 rg %s f %s f',
                $this->circlePath($cx - ($r * 0.68), $cy - ($r * 0.04), max(0.55, $r * 0.14)),
                $this->circlePath($cx + ($r * 0.68), $cy - ($r * 0.04), max(0.55, $r * 0.14))
            );
        }

        return implode(' ', $cmds);
    }

    protected function drawThumbCommands(float $x, float $bottomY, float $size, bool $up): string
    {
        $cuffX = $x + ($size * 0.08);
        $fistX = $x + ($size * 0.34);

        if ($up) {
            return sprintf(
                '0.200 0.480 0.860 rg %.2f %.2f %.2f %.2f re f 0.980 0.760 0.180 rg 0.780 0.500 0.060 RG 0.40 w %.2f %.2f %.2f %.2f re B %.2f %.2f m %.2f %.2f l %.2f %.2f l %.2f %.2f l h B',
                $cuffX,
                $bottomY + ($size * 0.10),
                $size * 0.22,
                $size * 0.48,
                $fistX,
                $bottomY + ($size * 0.10),
                $size * 0.54,
                $size * 0.48,
                $fistX + ($size * 0.06),
                $bottomY + ($size * 0.58),
                $fistX + ($size * 0.22),
                $bottomY + ($size * 0.92),
                $fistX + ($size * 0.38),
                $bottomY + ($size * 0.88),
                $fistX + ($size * 0.30),
                $bottomY + ($size * 0.58)
            );
        }

        return sprintf(
            '0.200 0.480 0.860 rg %.2f %.2f %.2f %.2f re f 0.980 0.760 0.180 rg 0.780 0.500 0.060 RG 0.40 w %.2f %.2f %.2f %.2f re B %.2f %.2f m %.2f %.2f l %.2f %.2f l %.2f %.2f l h B',
            $cuffX,
            $bottomY + ($size * 0.42),
            $size * 0.22,
            $size * 0.48,
            $fistX,
            $bottomY + ($size * 0.42),
            $size * 0.54,
            $size * 0.48,
            $fistX + ($size * 0.06),
            $bottomY + ($size * 0.42),
            $fistX + ($size * 0.22),
            $bottomY + ($size * 0.08),
            $fistX + ($size * 0.38),
            $bottomY + ($size * 0.12),
            $fistX + ($size * 0.30),
            $bottomY + ($size * 0.42)
        );
    }

    protected function drawFireCommands(float $cx, float $bottomY, float $size): string
    {
        $topY = $bottomY + ($size * 0.95);
        $baseY = $bottomY + ($size * 0.06);
        $w = $size * 0.40;

        $outer = sprintf(
            '%.2f %.2f m %.2f %.2f %.2f %.2f %.2f %.2f c %.2f %.2f %.2f %.2f %.2f %.2f c h',
            $cx,
            $topY,
            $cx + ($w * 1.25),
            $bottomY + ($size * 0.58),
            $cx + $w,
            $baseY,
            $cx,
            $baseY,
            $cx - $w,
            $baseY,
            $cx - ($w * 1.25),
            $bottomY + ($size * 0.58),
            $cx,
            $topY
        );

        $inner = sprintf(
            '%.2f %.2f m %.2f %.2f %.2f %.2f %.2f %.2f c %.2f %.2f %.2f %.2f %.2f %.2f c h',
            $cx,
            $bottomY + ($size * 0.58),
            $cx + ($w * 0.55),
            $bottomY + ($size * 0.35),
            $cx + ($w * 0.45),
            $baseY + ($size * 0.04),
            $cx,
            $baseY + ($size * 0.04),
            $cx - ($w * 0.45),
            $baseY + ($size * 0.04),
            $cx - ($w * 0.55),
            $bottomY + ($size * 0.35),
            $cx,
            $bottomY + ($size * 0.58)
        );

        return sprintf('0.945 0.340 0.120 rg %s f 0.990 0.810 0.180 rg %s f', $outer, $inner);
    }

    protected function drawConfettiCommands(float $x, float $bottomY, float $size): string
    {
        $cone = sprintf(
            '%.2f %.2f m %.2f %.2f l %.2f %.2f l h',
            $x + ($size * 0.10),
            $bottomY + ($size * 0.10),
            $x + ($size * 0.62),
            $bottomY + ($size * 0.34),
            $x + ($size * 0.34),
            $bottomY + ($size * 0.62)
        );

        return sprintf(
            '0.950 0.680 0.160 rg 0.760 0.480 0.060 RG 0.40 w %s B 0.900 0.220 0.340 rg %s f 0.200 0.620 0.940 rg %s f 0.160 0.720 0.420 rg %s f',
            $cone,
            $this->circlePath($x + ($size * 0.72), $bottomY + ($size * 0.74), max(0.6, $size * 0.09)),
            $this->circlePath($x + ($size * 0.50), $bottomY + ($size * 0.84), max(0.6, $size * 0.08)),
            $this->circlePath($x + ($size * 0.84), $bottomY + ($size * 0.52), max(0.6, $size * 0.08))
        );
    }

    protected function drawHundredCommands(float $x, float $pdfBaselineY, float $size, float $fontSize): string
    {
        $u1 = $pdfBaselineY - ($fontSize * 0.06);
        $u2 = $pdfBaselineY - ($fontSize * 0.16);
        $w = $fontSize * 1.30;

        return sprintf(
            'BT /F2 %.2f Tf 0.860 0.160 0.180 rg 1 0 0 1 %.2f %.2f Tm (100) Tj ET 0.860 0.160 0.180 RG %.2f w %.2f %.2f m %.2f %.2f l %.2f %.2f m %.2f %.2f l S',
            $fontSize * 0.78,
            $x,
            $pdfBaselineY + ($fontSize * 0.06),
            max(0.55, $size * 0.07),
            $x,
            $u1,
            $x + $w,
            $u1,
            $x + ($w * 0.08),
            $u2,
            $x + ($w * 0.92),
            $u2
        );
    }

    /**
     * @param  array{0: float, 1: float, 2: float}  $bg
     * @return array{0: string, 1: float}
     */
    protected function drawMiniBadgeCommands(
        float $x,
        float $bottomY,
        float $size,
        float $fontSize,
        string $label,
        array $bg
    ): array {
        $textFontSize = max(5.2, $fontSize * 0.64);
        $charCount = strlen($label);
        $boxW = max($size * 0.90, ($charCount * $textFontSize * 0.60) + ($size * 0.34));
        $boxH = $size * 0.88;
        $by = $bottomY + ($size * 0.06);

        $textW = $charCount * $textFontSize * 0.56;
        $tx = $x + (($boxW - $textW) * 0.5);
        $ty = $by + (($boxH - ($textFontSize * 0.72)) * 0.5);

        $cmd = sprintf(
            '%.3f %.3f %.3f rg %.2f %.2f %.2f %.2f re f BT /F2 %.2f Tf 1.000 1.000 1.000 rg 1 0 0 1 %.2f %.2f Tm (%s) Tj ET',
            $bg[0],
            $bg[1],
            $bg[2],
            $x,
            $by,
            $boxW,
            $boxH,
            $textFontSize,
            $tx,
            $ty,
            $label
        );

        return [$cmd, $boxW];
    }

    /**
     * Build a 4-segment cubic Bézier path approximating a circle at `(cx, cy)` with radius `r`.
     */
    protected function circlePath(float $cx, float $cy, float $r): string
    {
        $k = 0.55228475 * $r;

        return sprintf(
            '%.2f %.2f m %.2f %.2f %.2f %.2f %.2f %.2f c %.2f %.2f %.2f %.2f %.2f %.2f c %.2f %.2f %.2f %.2f %.2f %.2f c %.2f %.2f %.2f %.2f %.2f %.2f c h',
            $cx + $r,
            $cy,
            $cx + $r,
            $cy + $k,
            $cx + $k,
            $cy + $r,
            $cx,
            $cy + $r,
            $cx - $k,
            $cy + $r,
            $cx - $r,
            $cy + $k,
            $cx - $r,
            $cy,
            $cx - $r,
            $cy - $k,
            $cx - $k,
            $cy - $r,
            $cx,
            $cy - $r,
            $cx + $k,
            $cy - $r,
            $cx + $r,
            $cy - $k,
            $cx + $r,
            $cy
        );
    }
}
