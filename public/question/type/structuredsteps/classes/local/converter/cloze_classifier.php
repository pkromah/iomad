<?php
// This file is part of Moodle - http://moodle.org/

namespace qtype_structuredsteps\local\converter;

defined('MOODLE_INTERNAL') || die();

/**
 * Rule-based engine classifier for CLOZE (multianswer) questions.
 *
 * Implements D-PSQC-004 (confidence scoring) and D-PSQC-005 (engine selection)
 * from the primary school question conversion spec.
 *
 * Confidence scoring (0–100):
 * - All sub-parts NUMERICAL: +25
 * - Category name matches known engine keyword: +15
 * - Question text contains algorithmic keyword: +10
 * - Explicit step labels ("Step N:", "Calculate:", "Find:"): +20, max +40
 * - Mixed sub-types (NUMERICAL + other in same question): −15
 * - No category engine hint AND no keyword match: −20
 * - Image refs present (@@PLUGINFILE@@ or <img>): forced 0, status = image_review
 *
 * Thresholds (calibrated from TASK-PSQC-017 dry-run on 95 unique Math questions):
 * - ≥ 40: auto_convert_eligible  (lowered from 75 — SEA drill Qs rarely have step labels)
 * - 25–39: review_queue
 * - < 25:  skip
 *
 * Engine selection (D-PSQC-005):
 * - AlgorithmicWorkingEngine: time/clock/money/decimal/fraction/multiply/divide keywords
 * - StepCalculationEngine: area/perimeter/volume/angle/geometry/percent/ratio/statistics keywords
 * - Ambiguous (both score within 8 pts of each other): −10 confidence penalty
 *
 * Only CLOZE NUMERICAL questions are eligible for Phase 1 auto-conversion.
 * Non-NUMERICAL CLOZE and image-blocked questions are routed to skip / image_review.
 *
 * @package qtype_structuredsteps
 */
class cloze_classifier {

    /** @var string Classifier version */
    const VERSION = '1.0';

    /**
     * AlgorithmicWorkingEngine keyword sets (category and text).
     * Keys are keywords, values are point weights.
     */
    const ALGO_KEYWORDS = [
        // Time
        'time' => 8, 'clock' => 8, 'hour' => 6, 'minute' => 6, 'second' => 5,
        // Money / consumer
        'money' => 8, 'dollar' => 6, 'cost' => 5, 'price' => 5, 'profit' => 5,
        'loss' => 5, 'sale' => 4, 'discount' => 5, 'change' => 3,
        // Decimal / fraction / operations
        'decimal' => 7, 'fraction' => 7, 'multiply' => 5, 'divide' => 5,
        'addition' => 4, 'subtraction' => 4,
    ];

    /**
     * StepCalculationEngine keyword sets (category and text).
     */
    const STEP_KEYWORDS = [
        // Geometry / measurement
        'area' => 8, 'perimeter' => 8, 'volume' => 7, 'length' => 5,
        'mass' => 5, 'weight' => 5, 'capacity' => 5,
        // Angles / shapes
        'angle' => 7, 'triangle' => 6, 'circle' => 6, 'polygon' => 5,
        'square' => 4, 'rectangle' => 4, 'geometry' => 7,
        // Ratio / statistics
        'percent' => 6, 'percentage' => 6, 'ratio' => 6, 'proportion' => 5,
        'mean' => 5, 'average' => 5, 'mode' => 4, 'median' => 4,
    ];

    /**
     * Category keywords that map directly to an engine (overrides text scoring when present).
     *
     * These are category-level signals and receive the full +15 bonus even without text match.
     */
    const CATEGORY_ENGINE_MAP = [
        // Algo
        'time'       => 'AlgorithmicWorkingEngine',
        'clock'      => 'AlgorithmicWorkingEngine',
        'money'      => 'AlgorithmicWorkingEngine',
        'decimal'    => 'AlgorithmicWorkingEngine',
        'fraction'   => 'AlgorithmicWorkingEngine',
        // Step
        'area'       => 'StepCalculationEngine',
        'perimeter'  => 'StepCalculationEngine',
        'volume'     => 'StepCalculationEngine',
        'geometry'   => 'StepCalculationEngine',
        'angle'      => 'StepCalculationEngine',
        'percent'    => 'StepCalculationEngine',
        'percentage' => 'StepCalculationEngine',
        'ratio'      => 'StepCalculationEngine',
        'statistic'  => 'StepCalculationEngine',
        'measurement'=> 'StepCalculationEngine',
    ];

    /**
     * Classify a parsed CLOZE question to a structuredsteps engine.
     *
     * Input must be the output of cloze_parser::parse_question(). The additional
     * CLOZE-specific keys ('sub_parts', 'subtypes', 'all_numerical', 'has_mixed_types',
     * 'category') are required for accurate classification.
     *
     * @param array  $parsed     Output from cloze_parser::parse_question().
     * @param string $topic_hint Optional override; if empty, parsed['category'] is used.
     * @return array{engine:string,confidence:int,status:string,matched_patterns:array,notes:array}
     */
    public function classify(array $parsed, string $topic_hint = ''): array {
        $raw_text      = (string)($parsed['raw_text'] ?? '');
        $plain         = $this->plain_text($raw_text);
        $lower         = strtolower($plain);
        $category      = (string)($parsed['category'] ?? '');
        $topic         = $topic_hint !== '' ? $topic_hint : $category;
        $topic_lower   = strtolower($topic);
        $all_numerical = !empty($parsed['all_numerical']);
        $mixed_types   = !empty($parsed['has_mixed_types']);
        $has_image     = !empty($parsed['has_image_refs']);
        $sub_parts     = (array)($parsed['sub_parts'] ?? []);

        $patterns = [];
        $notes    = [];

        // ── Image guard: forced 0 regardless of other rules ─────────────────────
        if ($has_image) {
            $notes[] = 'Image refs detected — forced to image_review status.';
            return [
                'engine'           => '',
                'confidence'       => 0,
                'status'           => 'image_review',
                'matched_patterns' => ['image_blocked'],
                'notes'            => $notes,
            ];
        }

        // ── Non-NUMERICAL guard: not eligible for Phase 1 pipeline ───────────────
        if (!$all_numerical && !$mixed_types) {
            $subtypes = array_unique(array_column($sub_parts, 'subtype'));
            $only_subtype = count($subtypes) === 1 ? $subtypes[0] : 'MIXED';
            $notes[] = "Sub-type {$only_subtype} is not NUMERICAL — not eligible for Phase 1 auto-conversion.";
            return [
                'engine'           => '',
                'confidence'       => 0,
                'status'           => 'skip',
                'matched_patterns' => ['non_numerical'],
                'notes'            => $notes,
            ];
        }

        // ── Engine scoring (D-PSQC-005) ──────────────────────────────────────────
        $algo_score = $this->keyword_score($topic_lower . ' ' . $lower, self::ALGO_KEYWORDS);
        $step_score = $this->keyword_score($topic_lower . ' ' . $lower, self::STEP_KEYWORDS);

        if ($algo_score === 0 && $step_score === 0) {
            $engine = '';
        } elseif ($algo_score >= $step_score) {
            $engine = 'AlgorithmicWorkingEngine';
            $patterns[] = 'algo_keyword';
        } else {
            $engine = 'StepCalculationEngine';
            $patterns[] = 'step_keyword';
        }

        $ambiguous = ($algo_score > 0 && $step_score > 0)
            && abs($algo_score - $step_score) <= 8;

        if ($ambiguous) {
            $patterns[] = 'ambiguous_engine';
        }

        // ── Confidence scoring (D-PSQC-004) ──────────────────────────────────────
        $score = 0;

        // All sub-parts NUMERICAL: +25
        if ($all_numerical) {
            $score += 25;
            $patterns[] = 'all_numerical';
        }

        // Mixed sub-types: −15
        if ($mixed_types) {
            $score -= 15;
            $notes[] = 'Mixed CLOZE sub-types (NUMERICAL + other). Confidence reduced.';
            $patterns[] = 'mixed_subtypes';
        }

        // Category keyword match: +15
        $cat_engine = $this->category_engine($topic_lower);
        if ($cat_engine !== '') {
            $score += 15;
            $patterns[] = 'category_keyword';
            // If category engine contradicts text-scoring engine, record ambiguity.
            if ($engine !== '' && $cat_engine !== $engine) {
                $ambiguous = true;
                $notes[] = "Category suggests {$cat_engine} but text suggests {$engine}.";
            } elseif ($engine === '') {
                $engine = $cat_engine;
            }
        }

        // Algorithmic keyword in text: +10 (first match only)
        if ($this->has_keyword($lower, array_keys(self::ALGO_KEYWORDS))
            || $this->has_keyword($lower, array_keys(self::STEP_KEYWORDS))) {
            $score += 10;
            $patterns[] = 'text_keyword';
        }

        // Explicit step labels: +20 each, max +40 (D-PSQC-004)
        $step_label_count = $this->count_step_labels($plain);
        if ($step_label_count >= 2) {
            $score += 40;
            $patterns[] = 'step_labels_2+';
        } elseif ($step_label_count === 1) {
            $score += 20;
            $patterns[] = 'step_label_1';
        }

        // Ambiguous engine penalty: −10
        if ($ambiguous) {
            $score -= 10;
            $notes[] = 'Ambiguous engine selection. Confidence reduced.';
        }

        // No engine signal at all: −20
        if ($engine === '' && $cat_engine === '') {
            $score -= 20;
            $notes[] = 'No category or text engine signal found.';
        }

        $confidence = max(0, min(100, $score));

        // ── Status assignment (thresholds from TASK-PSQC-017 calibration) ──────────
        if ($confidence >= 40) {
            $status = 'auto_convert_eligible';
        } elseif ($confidence >= 25) {
            $status = 'review_queue';
        } else {
            $status = 'skip';
        }

        // Fallback engine if we matched keywords but engine wasn't set above.
        if ($engine === '' && $confidence > 0) {
            $engine = 'AlgorithmicWorkingEngine';  // safe default for unclassified numericals
            $notes[] = 'Engine defaulted to AlgorithmicWorkingEngine (no strong signal).';
        }

        return [
            'engine'           => $confidence > 0 ? $engine : '',
            'confidence'       => $confidence,
            'status'           => $status,
            'matched_patterns' => $patterns,
            'notes'            => $notes,
        ];
    }

    /**
     * Return classifier version.
     *
     * @return string
     */
    public function get_version(): string {
        return self::VERSION;
    }

    // ---- Private helpers ----

    /**
     * Sum keyword scores present in $haystack.
     *
     * @param string $haystack Lower-cased text to search.
     * @param array<string,int> $keywords keyword => weight map.
     * @return int
     */
    private function keyword_score(string $haystack, array $keywords): int {
        $total = 0;
        foreach ($keywords as $kw => $weight) {
            if (str_contains($haystack, $kw)) {
                $total += $weight;
            }
        }
        return $total;
    }

    /**
     * Return true if any of $keywords appears in $haystack.
     *
     * @param string   $haystack
     * @param string[] $keywords
     * @return bool
     */
    private function has_keyword(string $haystack, array $keywords): bool {
        foreach ($keywords as $kw) {
            if (str_contains($haystack, $kw)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Resolve the engine from the topic/category string using CATEGORY_ENGINE_MAP.
     * Returns '' if no match found.
     *
     * @param string $topic_lower Lower-cased topic or category.
     * @return string Engine name or ''.
     */
    private function category_engine(string $topic_lower): string {
        foreach (self::CATEGORY_ENGINE_MAP as $kw => $eng) {
            if (str_contains($topic_lower, $kw)) {
                return $eng;
            }
        }
        return '';
    }

    /**
     * Count explicit step-label occurrences in plain text.
     *
     * Recognises: "Step N:", "Calculate:", "Find:", "Determine:", "Work out:" (case-insensitive).
     *
     * @param string $plain Plain text (HTML stripped).
     * @return int Number of distinct step-label occurrences (capped at 2 for scoring purposes).
     */
    private function count_step_labels(string $plain): int {
        $count = 0;
        // Numbered step labels: "Step 1:", "Step 2:", etc.
        $count += preg_match_all('/\bStep\s+\d+\s*:/i', $plain);
        // Imperative calculation labels
        $count += preg_match_all('/\b(Calculate|Find|Determine|Work\s+out|Compute|Solve)\s*:/i', $plain);
        // List-item prefixes that imply separate steps: a), b), c)
        $list_items = preg_match_all('/\b[a-e]\)\s/i', $plain);
        if ($list_items >= 2) {
            $count += 1;  // treat multi-item list as one step-label unit
        }
        return min(2, $count);  // cap at 2 for +40 max bonus
    }

    /**
     * Strip HTML and CLOZE patterns from text, returning plain text.
     *
     * @param string $html
     * @return string
     */
    private function plain_text(string $html): string {
        $plain = preg_replace('/\{[^}]*\}/', ' ', $html);
        $plain = preg_replace('/<[^>]+>/', ' ', $plain ?? $html);
        $plain = html_entity_decode($plain ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/\s+/', ' ', $plain) ?? '');
    }
}
