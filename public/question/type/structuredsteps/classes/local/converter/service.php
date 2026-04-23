<?php
// This file is part of Moodle - http://moodle.org/

namespace qtype_structuredsteps\local\converter;

defined('MOODLE_INTERNAL') || die();

/**
 * Deterministic converter core service for legacy question mapping.
 */
class service {
    /**
     * Build a deterministic conversion proposal from a legacy question payload.
     *
     * @param array<string,mixed> $legacyquestion
     * @return array<string,mixed>
     */
    public function propose_conversion(array $legacyquestion): array {
        $questionid = (int)($legacyquestion['id'] ?? 0);
        $qtype = trim((string)($legacyquestion['qtype'] ?? ''));
        $questiontext = (string)($legacyquestion['questiontext'] ?? '');
        $tokens = $this->extract_cloze_tokens($questiontext);
        $analysis = $this->analyse_structure($questiontext, $qtype, $tokens);

        $engine = (string)($analysis['engine'] ?? '');
        $components = (array)($analysis['components'] ?? []);
        $confidence = (int)min(100, max(0, array_sum($components)));
        $ambiguous = !empty($analysis['ambiguous']);

        $status = 'manual_review';
        if ($confidence >= 85 && !$ambiguous && $engine !== '') {
            $status = 'auto_convert_eligible';
        } else if ($confidence < 60 || $engine === '') {
            $status = 'low_confidence';
        }

        $modeljson = null;
        $validationerror = null;
        if ($engine !== '') {
            $modeljson = $this->build_model_json($engine, $questiontext);
            $validator = new \qtype_structuredsteps\local\model_validator();
            $validationerror = $validator->validate($modeljson, $engine);
        }

        $needsai = ($engine === '' || $confidence < 60 || $ambiguous || $validationerror !== null);

        return [
            'status' => $status,
            'questionid' => $questionid,
            'source_qtype' => $qtype,
            'engine' => $engine,
            'confidence' => $confidence,
            'components' => $components,
            'ambiguous' => $ambiguous,
            'needs_ai' => $needsai,
            'validation_error' => $validationerror,
            'detected_patterns' => (array)($analysis['matched_patterns'] ?? []),
            'cloze_tokens' => $tokens,
            'model_json' => $modeljson,
            'ai_payload' => $this->build_ai_payload($legacyquestion, $tokens, (array)($analysis['candidate_engines'] ?? []), $confidence),
            'conversion_plan' => [
                'non_destructive' => true,
                'create_new_question' => true,
                'tag' => 'converted_structuredsteps',
            ],
            'rollback_plan' => [
                'disable_new_question' => true,
                'remove_conversion_tag' => true,
                'preserve_original' => true,
            ],
        ];
    }

    /**
     * @param string $html
     * @return array<int,array<string,mixed>>
     */
    public function extract_cloze_tokens(string $html): array {
        $tokens = [];
        $pattern = '/\{(\d+):(NUMERICAL|SHORTANSWER|MULTICHOICE):([^}]*)\}/i';
        if (!preg_match_all($pattern, $html, $matches, PREG_SET_ORDER)) {
            return $tokens;
        }

        foreach ($matches as $match) {
            $rawanswer = trim((string)$match[3]);
            $answer = preg_replace('/^[=~#:]+/', '', $rawanswer);
            $tolerance = null;
            if (preg_match('/:(\d+(?:\.\d+)?)/', $rawanswer, $tolmatch)) {
                $tolerance = (float)$tolmatch[1];
            }

            $tokens[] = [
                'mark' => (int)$match[1],
                'type' => strtoupper((string)$match[2]),
                'answer' => trim((string)$answer),
                'tolerance' => $tolerance,
            ];
        }

        return $tokens;
    }

    /**
     * @param string $questiontext
     * @param string $qtype
     * @param array<int,array<string,mixed>> $tokens
     * @return array<string,mixed>
     */
    private function analyse_structure(string $questiontext, string $qtype, array $tokens): array {
        $plain = trim(preg_replace('/\s+/', ' ', strip_tags($questiontext)));
        $lower = \core_text::strtolower($plain);
        $numericcount = $this->count_token_type($tokens, 'NUMERICAL');
        $textcount = $this->count_token_type($tokens, 'SHORTANSWER');

        $candidates = [];
        $patterns = [];

        if (preg_match('/[A-Z][a-z]?\d*\s*\+\s*[A-Z][a-z]?\d*\s*(->|→)\s*[A-Z]/', $plain)) {
            $candidates['stoichiometry'] = 90;
            $patterns[] = 'chemical_equation';
        }
        if (preg_match('/\d+\s*[÷\/]\s*\d+/', $plain) || preg_match('/\d+\s*\)\s*\d+/', $plain)) {
            $candidates['long_division'] = 88;
            $patterns[] = 'division_pattern';
        }
        if (preg_match('/\d+\s*[x×]\s*\d+/', $lower) || strpos($lower, 'multiply') !== false) {
            $candidates['long_multiplication'] = 84;
            $patterns[] = 'multiplication_pattern';
        }
        if (strpos($lower, 'debit') !== false && strpos($lower, 'credit') !== false) {
            $candidates['ledger_poa'] = 88;
            $patterns[] = 'debit_credit_pattern';
        }
        if (strpos($lower, 'evidence') !== false || strpos($lower, 'explain') !== false || strpos($lower, 'claim') !== false) {
            $candidates['evidence_table'] = 82;
            $patterns[] = 'evidence_pattern';
        }
        if (strpos($lower, 'gradient') !== false || strpos($lower, 'intercept') !== false || strpos($lower, 'coordinate') !== false) {
            $candidates['graphing'] = 83;
            $patterns[] = 'graphing_pattern';
        }
        if (strpos($lower, '=') !== false || preg_match('/\b(ke|force|velocity|formula|solve)\b/', $lower)) {
            $candidates['step_calculation'] = 76;
            $patterns[] = 'formula_pattern';
        }

        if ($qtype === 'numerical' && empty($candidates)) {
            $candidates['step_calculation'] = 70;
            $patterns[] = 'numerical_fallback';
        }
        if ($qtype === 'essay' && empty($candidates)) {
            $candidates['evidence_table'] = 65;
            $patterns[] = 'essay_fallback';
        }

        $engine = '';
        $ambiguous = false;
        if (!empty($candidates)) {
            arsort($candidates);
            $keys = array_keys($candidates);
            $engine = (string)$keys[0];
            if (count($keys) > 1) {
                $top = (int)$candidates[$keys[0]];
                $second = (int)$candidates[$keys[1]];
                $ambiguous = abs($top - $second) <= 8;
            }
        }

        $patternscore = empty($candidates) ? 0 : min(40, (int)round(((int)reset($candidates)) * 0.4));
        $tokenalign = min(20, $numericcount >= 2 ? 20 : ($numericcount >= 1 ? 12 : ($textcount >= 2 ? 10 : 4)));
        $structureclarity = min(20, strlen($plain) > 30 ? 16 : 8);
        if (strpos($questiontext, '<table') !== false) {
            $structureclarity = min(20, $structureclarity + 4);
        }
        $aiagreement = 0;

        return [
            'engine' => $engine,
            'ambiguous' => $ambiguous,
            'candidate_engines' => array_keys($candidates),
            'matched_patterns' => $patterns,
            'components' => [
                'pattern_match_strength' => $patternscore,
                'token_alignment_consistency' => $tokenalign,
                'html_structure_clarity' => $structureclarity,
                'ai_agreement' => $aiagreement,
            ],
        ];
    }

    /**
     * @param string $engine
     * @param string $questiontext
     * @return string
     */
    private function build_model_json(string $engine, string $questiontext): string {
        $modeljson = \qtype_structuredsteps\local\model_template_factory::starter_json($engine);
        $model = json_decode($modeljson, true, 512, JSON_THROW_ON_ERROR);

        if ($engine === 'long_multiplication' && preg_match('/(\d+)\s*[x×]\s*(\d+)/i', $questiontext, $m)) {
            $model['params']['multiplicand'] = (int)$m[1];
            $model['params']['multiplier'] = (int)$m[2];
        }

        if ($engine === 'long_division') {
            if (preg_match('/(\d+)\s*[÷\/]\s*(\d+)/', $questiontext, $m)) {
                $model['params']['dividend'] = (int)$m[1];
                $model['params']['divisor'] = (int)$m[2];
            } else if (preg_match('/(\d+)\s*\)\s*(\d+)/', $questiontext, $m)) {
                $model['params']['divisor'] = (int)$m[1];
                $model['params']['dividend'] = (int)$m[2];
            }
        }

        return json_encode($model, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param array<string,mixed> $legacyquestion
     * @param array<int,array<string,mixed>> $tokens
     * @param array<int,string> $suggestedengines
     * @param int $confidence
     * @return array<string,mixed>
     */
    private function build_ai_payload(array $legacyquestion, array $tokens, array $suggestedengines, int $confidence): array {
        return [
            'questionid' => (int)($legacyquestion['id'] ?? 0),
            'questiontext' => (string)($legacyquestion['questiontext'] ?? ''),
            'cloze_tokens' => $tokens,
            'suggested_engines' => array_values($suggestedengines),
            'confidence' => $confidence,
            'schema_version' => '1.0',
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $tokens
     * @param string $type
     * @return int
     */
    private function count_token_type(array $tokens, string $type): int {
        $count = 0;
        foreach ($tokens as $token) {
            if (!empty($token['type']) && strtoupper((string)$token['type']) === $type) {
                $count++;
            }
        }
        return $count;
    }
}
