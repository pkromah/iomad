<?php
// This file is part of Moodle - http://moodle.org/

namespace qtype_structuredsteps\local\engine;

defined('MOODLE_INTERNAL') || die();

/**
 * Generic step calculation engine.
 */
class step_calculation_engine extends base_engine {
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

        // Pre-pass: detect fields that duplicate a sibling's value within a unique_within_step group.
        $uniqueness_violations = $this->detect_uniqueness_violations($model['fields'] ?? [], $responses);

        if (!empty($model['fields']) && is_array($model['fields'])) {
            foreach ($model['fields'] as $field) {
                if (!is_array($field) || empty($field['id'])) {
                    continue;
                }

                $fieldid = (string)$field['id'];
                $stepid = isset($field['step_id']) ? (string)$field['step_id'] : '';
                $expected = isset($field['expected']) ? (string)$field['expected'] : '';
                $fieldtype = isset($field['type']) ? (string)$field['type'] : 'structured_text';
                $maxmarks = isset($field['marks']) ? (float)$field['marks'] : 1.0;
                $student = isset($responses[$fieldid]) ? (string)$responses[$fieldid] : '';
                $iscorrect = $this->is_field_correct($student, $expected, $fieldtype, $field);

                // Uniqueness override: a duplicate within its step group is always wrong,
                // even if the value coincidentally matches expected.
                $unique_violation = isset($uniqueness_violations[$fieldid]);
                if ($unique_violation) {
                    $iscorrect = false;
                }

                $awarded = $iscorrect ? $maxmarks : 0.0;

                $diagnostics = $this->classify_field_error($student, $expected, $fieldtype, $field, $iscorrect, $unique_violation);
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
            'engine_name' => 'step_calculation',
            'version' => '1.0',
            'supported_field_types' => ['digitbox', 'numberbox', 'structured_text', 'choice'],
            'recommended_layout' => 'vertical',
            'step_types' => ['computational', 'method'],
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
     * Check whether a student response is correct for a given field.
     *
     * Rules (in order):
     *  1. numberbox  → numeric tolerance match via grading_policy
     *  2. choice / structured_text → case-insensitive exact match against `expected`
     *  3. If still no match → check `expected_alternatives` array (case-insensitive)
     *
     * @param string $student
     * @param string $expected
     * @param string $fieldtype
     * @param array $field
     * @return bool
     */
    private function is_field_correct(string $student, string $expected, string $fieldtype, array $field): bool {
        if ($fieldtype === 'numberbox') {
            $tolerance = \qtype_structuredsteps\local\grading_policy::resolve_tolerance($field);
            return \qtype_structuredsteps\local\grading_policy::numbers_match($student, $expected, $tolerance);
        }

        // Case-insensitive exact match for all non-numeric field types.
        if (strtolower(trim($student)) === strtolower(trim($expected))) {
            return true;
        }

        // Check expected_alternatives — any entry is accepted with the same case-insensitive rule.
        $alternatives = $field['expected_alternatives'] ?? [];
        if (is_array($alternatives)) {
            $normalised_student = strtolower(trim($student));
            foreach ($alternatives as $alt) {
                if ($normalised_student === strtolower(trim((string)$alt))) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Detect fields that submitted a value already used by an earlier sibling field
     * within the same step, where both fields carry `unique_within_step: true`.
     *
     * Returns a map of [ field_id => true ] for every field that is a duplicate violator.
     * The first field to submit a given value is NOT flagged; only later duplicates are.
     *
     * @param array $fields   All field definitions from the model.
     * @param array $responses Normalised [ field_id => student_value ] map.
     * @return array<string,bool>
     */
    private function detect_uniqueness_violations(array $fields, array $responses): array {
        // Group unique_within_step field IDs by step_id.
        $step_groups = [];
        foreach ($fields as $field) {
            if (empty($field['id']) || empty($field['unique_within_step'])) {
                continue;
            }
            $stepid = (string)($field['step_id'] ?? '');
            $step_groups[$stepid][] = (string)$field['id'];
        }

        $violations = [];
        foreach ($step_groups as $field_ids) {
            $seen = []; // normalised_value => first_field_id
            foreach ($field_ids as $fid) {
                $val = strtolower(trim((string)($responses[$fid] ?? '')));
                if ($val === '') {
                    continue;
                }
                if (isset($seen[$val])) {
                    // This field duplicates a value already used earlier in this step.
                    $violations[$fid] = true;
                } else {
                    $seen[$val] = $fid;
                }
            }
        }
        return $violations;
    }

    /**
     * @param string $student
     * @param string $expected
     * @param string $fieldtype
     * @param array $field
     * @param bool $iscorrect
     * @param bool $unique_violation  True when this field has a uniqueness-duplicate error.
     * @return array{error_type:?string,diagnostic_code:?string}
     */
    private function classify_field_error(
        string $student,
        string $expected,
        string $fieldtype,
        array $field,
        bool $iscorrect,
        bool $unique_violation = false
    ): array {
        if ($iscorrect) {
            return ['error_type' => null, 'diagnostic_code' => null];
        }

        if ($unique_violation && trim($student) !== '') {
            return ['error_type' => 'procedural_error', 'diagnostic_code' => 'SC_DUPLICATE_IN_STEP'];
        }

        if (trim($student) === '') {
            return ['error_type' => 'procedural_error', 'diagnostic_code' => 'SC_MISSING_ENTRY'];
        }

        if ($fieldtype === 'numberbox') {
            if (!is_numeric($student) || !is_numeric($expected)) {
                return ['error_type' => 'formatting_error', 'diagnostic_code' => 'SC_INVALID_NUMBER'];
            }
            return ['error_type' => 'computational_error', 'diagnostic_code' => 'SC_CALCULATION_ERROR'];
        }

        if ($fieldtype === 'choice') {
            return ['error_type' => 'conceptual_error', 'diagnostic_code' => 'SC_FORMULA_SELECTION_ERROR'];
        }

        return ['error_type' => 'conceptual_error', 'diagnostic_code' => 'SC_METHOD_ERROR'];
    }

    /**
     * @param array<int,string> $errors
     * @return string
     */
    private function resolve_dominant_error_type(array $errors): string {
        return \qtype_structuredsteps\local\diagnostic_taxonomy::resolve_dominant_error_type($errors);
    }
}
