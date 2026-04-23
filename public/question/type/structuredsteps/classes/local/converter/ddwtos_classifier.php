<?php
// This file is part of Moodle - http://moodle.org/

namespace qtype_structuredsteps\local\converter;

defined('MOODLE_INTERNAL') || die();

/**
 * Rule-based engine classifier for DDWTOS questions.
 *
 * Version 2.0 — adds Phase 2 engine routing (TraceEngine, GraphEngine, EvidenceMappingEngine).
 *
 * Confidence scoring (0–100):
 * - Pattern match strength: up to 40 pts
 * - Step label quality: up to 20 pts
 * - Numeric/algebraic dragbox options: up to 15 pts
 * - Penalties: image refs (-20), no step labels (-15), ambiguous engine (-10)
 *
 * Thresholds:
 * - >= 75: auto-convert eligible
 * - 50–74: manual review queue
 * - < 50: skip (no engine)
 *
 * Phase 2 topics bypass the confidence pipeline and return a fixed engine name with
 * status 'auto_convert_eligible' (if no image refs) or 'review_queue' (if image-flagged).
 */
class ddwtos_classifier {

    /** @var string Classifier version surfaced in test and dry-run output */
    const VERSION = '2.0';

    /**
     * Classify a parsed DDWTOS question to a structuredsteps engine.
     *
     * @param array $parsed Output from ddwtos_parser::parse_question().
     * @param string $topic_hint Optional topic name for additional signal (e.g. 'computation').
     * @return array{engine:string,confidence:int,status:string,matched_patterns:array,notes:array}
     */
    public function classify(array $parsed, string $topic_hint = ''): array {
        $plain = $this->plain_text($parsed['raw_text'] ?? '');
        $lower = mb_strtolower($plain);
        $topic_lower = mb_strtolower($topic_hint);
        $dragbox_texts = array_column($parsed['dragboxes'] ?? [], 'text');
        $step_count = (int)($parsed['step_count'] ?? 0);
        $has_steps = $step_count > 0;
        $has_image_refs = !empty($parsed['has_image_refs']);

        $candidates = [];
        $patterns = [];
        $notes = [];

        // --- Pattern matching ---

        // Long multiplication
        if (preg_match('/\d+\s*[×x\*]\s*\d+/u', $plain)
            || str_contains($lower, 'long multiplication')
            || str_contains($lower, 'multiply')
            || str_contains($topic_lower, 'computation')
        ) {
            if (!str_contains($lower, 'divide') && !str_contains($lower, 'division')) {
                $candidates['long_multiplication'] = 42;
                $patterns[] = 'multiplication_keyword';
            }
        }

        // Computation topic boosts algorithmic engines (long_multiplication / long_division)
        if (str_contains($topic_lower, 'computation')) {
            if (isset($candidates['long_multiplication'])) {
                $candidates['long_multiplication'] += 8;
            }
            if (isset($candidates['long_division'])) {
                $candidates['long_division'] += 8;
            }
            $patterns[] = 'computation_topic';
        }

        // Long division
        if (preg_match('/\d+\s*[÷\/]\s*\d+/', $plain)
            || preg_match('/\d+\s*\)\s*\d+/', $plain)
            || str_contains($lower, 'long division')
            || str_contains($lower, 'divide')
        ) {
            $candidates['long_division'] = isset($candidates['long_division'])
                ? $candidates['long_division']
                : 42;
            $patterns[] = 'division_keyword';
        }

        // Fractions & decimals → step_calculation (strong topic signal)
        if (str_contains($topic_lower, 'fraction') || str_contains($topic_lower, 'decimal')) {
            $candidates['step_calculation'] = isset($candidates['step_calculation'])
                ? $candidates['step_calculation'] + 10
                : 38;
            $patterns[] = 'fraction_decimal_topic';
        }

        // Consumer arithmetic / measurement / relations / algebra → step_calculation
        if (str_contains($topic_lower, 'consumer')
            || str_contains($topic_lower, 'measurement')
            || str_contains($topic_lower, 'relations')
            || str_contains($topic_lower, 'algebra')
        ) {
            $candidates['step_calculation'] = isset($candidates['step_calculation'])
                ? $candidates['step_calculation'] + 10
                : 38;
            $patterns[] = 'calculation_topic';
        }

        // Formula / equation pattern → step_calculation
        // Suppressed only when an algorithmic engine is the primary candidate AND no step_calculation
        // topic keyword is present — prevents spurious ambiguity in computation questions while
        // still allowing fractions/decimals questions (which may contain ÷) to route correctly.
        $algorithmic_engine_selected = isset($candidates['long_multiplication'])
            || isset($candidates['long_division']);
        $has_step_calc_topic = str_contains($topic_lower, 'fraction')
            || str_contains($topic_lower, 'decimal')
            || str_contains($topic_lower, 'consumer')
            || str_contains($topic_lower, 'measurement')
            || str_contains($topic_lower, 'relations')
            || str_contains($topic_lower, 'algebra');
        $suppress_formula_pattern = $algorithmic_engine_selected && !$has_step_calc_topic;
        if (!$suppress_formula_pattern
            && (preg_match('/=\s*[\d\.\-\+]+/', $plain)
                || preg_match('/\b(formula|substitute|equation|solve|calculate)\b/i', $plain))
        ) {
            $candidates['step_calculation'] = isset($candidates['step_calculation'])
                ? $candidates['step_calculation'] + 12
                : 35;
            $patterns[] = 'formula_pattern';
        }

        // Ledger / POA → ledger_poa (raised base for clear keyword matches)
        if (str_contains($lower, 'debit') || str_contains($lower, 'credit')
            || str_contains($lower, 'journal') || str_contains($lower, 'ledger')
            || str_contains($topic_lower, 'accounts') || str_contains($topic_lower, 'poa')
        ) {
            $candidates['ledger_poa'] = 45;
            $patterns[] = 'ledger_poa_keyword';
        }

        // Stoichiometry
        if (preg_match('/[A-Z][a-z]?\d*\s*\+\s*[A-Z][a-z]?\d*/', $plain)
            || str_contains($lower, 'molar') || str_contains($lower, 'stoichiometry')
        ) {
            $candidates['stoichiometry'] = 38;
            $patterns[] = 'stoichiometry_pattern';
        }

        // --- Phase 2 engine routing ---
        // Topics with dedicated Phase 2 engines bypass Phase 1 confidence pipeline entirely.
        $phase2_engine = $this->get_phase2_engine($topic_lower, $lower);
        if ($phase2_engine !== '') {
            $status = $has_image_refs ? 'review_queue' : 'auto_convert_eligible';
            $note = 'Phase 2 topic "' . $topic_hint . '" routed to engine "' . $phase2_engine . '".';
            if ($has_image_refs) {
                $note .= ' Image refs detected — held in review queue.';
            }
            return [
                'engine'           => $phase2_engine,
                'confidence'       => $has_image_refs ? 70 : 80,
                'status'           => $status,
                'matched_patterns' => ['phase2_topic'],
                'notes'            => [$note],
            ];
        }

        // Default fallback when no pattern matched but steps exist
        if (empty($candidates) && $has_steps && !str_contains(implode(',', $patterns), 'phase2')) {
            $candidates['step_calculation'] = 20;
            $patterns[] = 'fallback_step_calculation';
            $notes[] = 'No specific pattern matched; defaulting to step_calculation (low confidence).';
        }

        // --- Select best engine ---
        if (empty($candidates)) {
            return [
                'engine' => '',
                'confidence' => 0,
                'status' => 'skip',
                'matched_patterns' => $patterns,
                'notes' => $notes ?: ['No suitable Phase 1 engine identified.'],
            ];
        }

        arsort($candidates);
        $engine_keys = array_keys($candidates);
        $engine = $engine_keys[0];
        $base_score = (int)$candidates[$engine];

        $ambiguous = count($engine_keys) > 1
            && abs($candidates[$engine_keys[0]] - $candidates[$engine_keys[1]]) <= 8;

        // --- Confidence adjustments ---
        $score = $base_score;

        // Step label quality — 2-step is a deliberate authoring choice, not a quality signal.
        if ($step_count >= 3) {
            $score += 25;
        } elseif ($step_count >= 2) {
            $score += 22;
        } elseif ($step_count === 1) {
            $score += 8;
        } else {
            $score -= 15;
            $notes[] = 'No step labels detected. Confidence reduced.';
        }

        // Numeric/algebraic dragbox options
        $numeric_ratio = $this->numeric_dragbox_ratio($dragbox_texts);
        if ($numeric_ratio >= 0.7) {
            $score += 15;
        } elseif ($numeric_ratio >= 0.4) {
            $score += 7;
        }

        // Image refs penalty
        if ($has_image_refs) {
            $score -= 20;
            $notes[] = 'Image refs detected — routed to review queue regardless of confidence.';
        }

        // Ambiguous engine penalty
        if ($ambiguous) {
            $score -= 10;
            $notes[] = 'Ambiguous engine selection between ' . implode(' / ', array_slice($engine_keys, 0, 2)) . '.';
        }

        $confidence = max(0, min(100, $score));

        // Force review queue for image refs regardless of score
        if ($has_image_refs) {
            $status = 'review_queue';
        } elseif ($confidence >= 75) {
            $status = 'auto_convert_eligible';
        } elseif ($confidence >= 50) {
            $status = 'review_queue';
        } else {
            $status = 'skip';
        }

        return [
            'engine' => $engine,
            'confidence' => $confidence,
            'status' => $status,
            'matched_patterns' => $patterns,
            'notes' => $notes,
        ];
    }

    /**
     * @param string $html
     * @return string
     */
    private function plain_text(string $html): string {
        $plain = preg_replace('/<[^>]+>/', ' ', $html);
        $plain = html_entity_decode($plain ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/\s+/', ' ', $plain) ?? '');
    }

    /**
     * Return the classifier version string.
     *
     * @return string
     */
    public function get_version(): string {
        return self::VERSION;
    }

    /**
     * Determine whether the topic or question text maps to a Phase 2 engine.
     *
     * Returns the engine name, or empty string if the topic is Phase 1.
     *
     * @param string $topic_lower Lower-cased topic hint.
     * @param string $lower       Lower-cased plain text of the question.
     * @return string Engine name or ''.
     */
    private function get_phase2_engine(string $topic_lower, string $lower): string {
        // TraceEngine: Logic Sequence Patterns, Sets
        if (str_contains($topic_lower, 'logic')
            || str_contains($topic_lower, 'sequence')
            || str_contains($topic_lower, 'pattern')
            || str_contains($topic_lower, 'set')
            || preg_match('/\b(union|intersection|complement|venn|subset)\b/', $lower)
        ) {
            return 'trace';
        }

        // EvidenceMappingEngine: Statistics
        if (str_contains($topic_lower, 'statistic')
            || str_contains($topic_lower, 'frequency')
            || str_contains($topic_lower, 'probability')
            || (str_contains($topic_lower, 'data') && !str_contains($topic_lower, 'database'))
        ) {
            return 'evidence_mapping';
        }

        // GraphEngine: Vectors, Matrices
        if (str_contains($topic_lower, 'vector')
            || str_contains($topic_lower, 'matri')
            || preg_match('/\b(determinant|transpose|eigenvalue|magnitude|dot product)\b/', $lower)
        ) {
            return 'graph';
        }

        return '';
    }

    /**
     * @param string[] $dragbox_texts
     * @return float
     */
    private function numeric_dragbox_ratio(array $dragbox_texts): float {
        if (empty($dragbox_texts)) {
            return 0.0;
        }

        $numeric_count = 0;
        foreach ($dragbox_texts as $text) {
            $plain = preg_replace('/<[^>]+>/', '', $text);
            $plain = html_entity_decode($plain ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $plain = trim($plain);
            // Consider numeric if it contains digits, operators, =, or LaTeX math markers
            if (preg_match('/[\d\=\+\-×÷\\\$]/', $plain)) {
                $numeric_count++;
            }
        }

        return $numeric_count / count($dragbox_texts);
    }
}
