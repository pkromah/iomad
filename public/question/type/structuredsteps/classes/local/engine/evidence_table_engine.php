<?php
// This file is part of Moodle - http://moodle.org/

namespace qtype_structuredsteps\local\engine;

defined('MOODLE_INTERNAL') || die();

/**
 * Evidence table engine for structured humanities reasoning.
 */
class evidence_table_engine extends base_engine {
    public function validate_response(array $response, array $model): array {
        if (isset($response['responses']) && is_array($response['responses'])) {
            return ['is_valid' => true, 'errors' => []];
        }

        if (array_key_exists('answer', $response)) {
            return ['is_valid' => true, 'errors' => []];
        }

        return ['is_valid' => false, 'errors' => ['answer' => 'missing']];
    }

    public function grade(array $response, array $model): array {
        $fieldresults = [];
        $stepaccumulator = [];
        $totalscore = 0.0;
        $totalmax = 0.0;
        $responses = $this->extract_structured_responses($response);
        $params = isset($model['params']) && is_array($model['params']) ? $model['params'] : [];
        $requireexplanation = !array_key_exists('require_explanation', $params) || !empty($params['require_explanation']);

        if (!empty($model['fields']) && is_array($model['fields'])) {
            foreach ($model['fields'] as $field) {
                if (!is_array($field) || empty($field['id'])) {
                    continue;
                }

                if (!$requireexplanation && $this->is_explanation_field($field)) {
                    continue;
                }

                $fieldid = (string)$field['id'];
                $stepid = isset($field['step_id']) ? (string)$field['step_id'] : '';
                $expected = isset($field['expected']) ? (string)$field['expected'] : '';
                $fieldtype = isset($field['type']) ? (string)$field['type'] : 'structured_text';
                $maxmarks = isset($field['marks']) ? (float)$field['marks'] : 1.0;
                $student = isset($responses[$fieldid]) ? (string)$responses[$fieldid] : '';
                $iscorrect = $this->is_field_correct($student, $expected, $fieldtype, $field, $params);
                $awarded = $iscorrect ? $maxmarks : 0.0;

                $diagnostics = $this->classify_field_error($student, $field, $iscorrect, $params);
                $fieldresults[$fieldid] = [
                    'is_correct' => $iscorrect,
                    'awarded_marks' => $awarded,
                    'max_marks' => $maxmarks,
                    'error_type' => $diagnostics['error_type'],
                    'diagnostic_code' => $diagnostics['diagnostic_code'],
                ];

                $totalscore += $awarded;
                $totalmax += $maxmarks;

                if ($stepid !== '') {
                    if (!isset($stepaccumulator[$stepid])) {
                        $stepaccumulator[$stepid] = [
                            'awarded' => 0.0,
                            'max' => 0.0,
                            'allcorrect' => true,
                            'error_types' => [],
                            'diagnostic_codes' => [],
                        ];
                    }
                    $stepaccumulator[$stepid]['awarded'] += $awarded;
                    $stepaccumulator[$stepid]['max'] += $maxmarks;
                    $stepaccumulator[$stepid]['allcorrect'] = $stepaccumulator[$stepid]['allcorrect'] && $iscorrect;

                    if (!$iscorrect && !empty($diagnostics['error_type'])) {
                        $stepaccumulator[$stepid]['error_types'][] = (string)$diagnostics['error_type'];
                    }
                    if (!$iscorrect && !empty($diagnostics['diagnostic_code'])) {
                        $stepaccumulator[$stepid]['diagnostic_codes'][] = (string)$diagnostics['diagnostic_code'];
                    }
                }
            }
        }

        $stepresults = [];
        foreach ($stepaccumulator as $stepid => $state) {
            $stepresults[$stepid] = [
                'is_correct' => (bool)$state['allcorrect'],
                'awarded_marks' => (float)$state['awarded'],
                'max_marks' => (float)$state['max'],
                'dominant_error_type' => $this->resolve_dominant_error_type($state['error_types']),
                'diagnostic_codes' => array_values(array_unique($state['diagnostic_codes'])),
            ];
        }

        $fraction = $totalmax > 0.0 ? max(0.0, min(1.0, $totalscore / $totalmax)) : 0.0;

        return [
            'score' => $totalscore,
            'max_score' => $totalmax,
            'fraction' => $fraction,
            'field_results' => $fieldresults,
            'step_results' => $stepresults,
            'errors' => [],
        ];
    }

    public function classify_errors(array $response, array $model): array {
        return [];
    }

    public function get_metadata(): array {
        return [
            'engine_name' => 'evidence_table',
            'version' => '1.0',
            'supported_modes' => ['answer_evidence', 'cause_effect', 'comparison', 'claim_support', 'structured_short'],
            'supported_field_types' => ['structured_text', 'tokenbank', 'choice'],
            'recommended_layout' => 'step_cards',
            'step_types' => ['conceptual', 'justification', 'communication'],
        ];
    }

    /**
     * @param array $response
     * @return array<string,string>
     */
    private function extract_structured_responses(array $response): array {
        if (!isset($response['responses']) || !is_array($response['responses'])) {
            return [];
        }

        $normalised = [];
        foreach ($response['responses'] as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $normalised[(string)$key] = trim((string)$value);
            }
        }
        return $normalised;
    }

    /**
     * @param array $field
     * @return bool
     */
    private function is_explanation_field(array $field): bool {
        $role = strtolower(trim((string)($field['role'] ?? '')));
        $fieldid = strtolower(trim((string)($field['id'] ?? '')));
        return ($role === 'explanation' || $fieldid === 'explanation');
    }

    /**
     * @param string $student
     * @param string $expected
     * @param string $fieldtype
     * @param array $field
     * @param array $params
     * @return bool
     */
    private function is_field_correct(
        string $student,
        string $expected,
        string $fieldtype,
        array $field,
        array $params
    ): bool {
        $role = strtolower(trim((string)($field['role'] ?? '')));
        $fieldid = strtolower(trim((string)($field['id'] ?? '')));

        $maxlength = isset($params['max_length']) && is_numeric($params['max_length']) ? (int)$params['max_length'] : 0;
        if ($maxlength > 0 && \core_text::strlen($student) > $maxlength) {
            return false;
        }

        if ($role === 'answer' || $fieldid === 'answer') {
            return $this->contains_any_keyword($student, $params['expected_answer_keywords'] ?? []);
        }

        if ($role === 'evidence' || $fieldid === 'evidence') {
            return $this->contains_any_keyword($student, $params['expected_evidence_keywords'] ?? []);
        }

        if ($role === 'explanation' || $fieldid === 'explanation') {
            $minlength = isset($params['explanation_min_length']) && is_numeric($params['explanation_min_length'])
                ? max(0, (int)$params['explanation_min_length'])
                : 20;
            if (\core_text::strlen(trim($student)) < $minlength) {
                return false;
            }

            $linkingphrases = $params['linking_phrases'] ?? ['because', 'this shows', 'therefore', 'this suggests', 'as a result'];
            return $this->contains_any_keyword($student, $linkingphrases);
        }

        if ($fieldtype === 'choice' || $fieldtype === 'tokenbank') {
            return strtolower(trim($student)) === strtolower(trim($expected));
        }

        return trim($student) === trim($expected);
    }

    /**
     * @param string $student
     * @param array $field
     * @param bool $iscorrect
     * @param array $params
     * @return array{error_type:?string,diagnostic_code:?string}
     */
    private function classify_field_error(string $student, array $field, bool $iscorrect, array $params): array {
        if ($iscorrect) {
            return ['error_type' => null, 'diagnostic_code' => null];
        }

        $role = strtolower(trim((string)($field['role'] ?? '')));
        $fieldid = strtolower(trim((string)($field['id'] ?? '')));
        $maxlength = isset($params['max_length']) && is_numeric($params['max_length']) ? (int)$params['max_length'] : 0;

        if ($maxlength > 0 && \core_text::strlen($student) > $maxlength) {
            return ['error_type' => 'communication_error', 'diagnostic_code' => 'EVT_MAX_LENGTH_EXCEEDED'];
        }

        if (trim($student) === '') {
            return ['error_type' => 'procedural_error', 'diagnostic_code' => 'EVT_MISSING_ENTRY'];
        }

        if ($role === 'answer' || $fieldid === 'answer') {
            return ['error_type' => 'conceptual_error', 'diagnostic_code' => 'EVT_ANSWER_KEYWORD_MISSING'];
        }

        if ($role === 'evidence' || $fieldid === 'evidence') {
            return ['error_type' => 'conceptual_error', 'diagnostic_code' => 'EVT_EVIDENCE_KEYWORD_MISSING'];
        }

        if ($role === 'explanation' || $fieldid === 'explanation') {
            return ['error_type' => 'conceptual_error', 'diagnostic_code' => 'EVT_EXPLANATION_INSUFFICIENT'];
        }

        return ['error_type' => 'communication_error', 'diagnostic_code' => 'EVT_RESPONSE_MISMATCH'];
    }

    /**
     * @param string $text
     * @param mixed $keywords
     * @return bool
     */
    private function contains_any_keyword(string $text, $keywords): bool {
        if (!is_array($keywords) || empty($keywords)) {
            return false;
        }

        $normalisedtext = $this->normalise_text($text);
        foreach ($keywords as $keyword) {
            if (!is_scalar($keyword) && $keyword !== null) {
                continue;
            }

            $normalisedkeyword = $this->normalise_text((string)$keyword);
            if ($normalisedkeyword === '') {
                continue;
            }

            if (strpos($normalisedtext, $normalisedkeyword) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string $text
     * @return string
     */
    private function normalise_text(string $text): string {
        $text = \core_text::strtolower($text);
        $text = preg_replace('/[^\\p{L}\\p{N}\\s]/u', ' ', $text);
        $text = preg_replace('/\\s+/u', ' ', (string)$text);
        return trim((string)$text);
    }

    /**
     * @param array<int,string> $errors
     * @return string
     */
    private function resolve_dominant_error_type(array $errors): string {
        return \qtype_structuredsteps\local\diagnostic_taxonomy::resolve_dominant_error_type($errors);
    }
}
