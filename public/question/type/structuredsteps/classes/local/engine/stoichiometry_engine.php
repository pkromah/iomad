<?php
// This file is part of Moodle - http://moodle.org/

namespace qtype_structuredsteps\local\engine;

defined('MOODLE_INTERNAL') || die();

/**
 * Stoichiometry and equations engine.
 */
class stoichiometry_engine extends base_engine {
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
        $mode = isset($params['mode']) ? (string)$params['mode'] : 'mass_to_mass';

        if (!empty($model['fields']) && is_array($model['fields'])) {
            foreach ($model['fields'] as $field) {
                if (!is_array($field) || empty($field['id'])) {
                    continue;
                }

                $fieldid = (string)$field['id'];
                $stepid = isset($field['step_id']) ? (string)$field['step_id'] : '';
                $expected = isset($field['expected']) ? (string)$field['expected'] : '';
                $fieldtype = isset($field['type']) ? (string)$field['type'] : 'numberbox';
                $maxmarks = isset($field['marks']) ? (float)$field['marks'] : 1.0;
                $student = isset($responses[$fieldid]) ? (string)$responses[$fieldid] : '';
                $iscorrect = $this->is_field_correct($student, $expected, $fieldtype, $field, $params);
                $awarded = $iscorrect ? $maxmarks : 0.0;

                $diagnostics = $this->classify_field_error($student, $expected, $field, $fieldtype, $mode, $iscorrect, $params);
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
            'engine_name' => 'stoichiometry',
            'version' => '1.0',
            'supported_modes' => ['balance_equation', 'mass_to_mass', 'mole_ratio'],
            'supported_field_types' => ['numberbox', 'digitbox', 'mathfield', 'unitpicker', 'tokenbank'],
            'recommended_layout' => 'step_cards',
            'step_types' => ['conceptual', 'computational', 'procedural'],
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
     * @param string $student
     * @param string $expected
     * @param string $fieldtype
     * @param array $field
     * @param array $params
     * @return bool
     */
    private function is_field_correct(string $student, string $expected, string $fieldtype, array $field, array $params): bool {
        if ($fieldtype === 'numberbox' || $fieldtype === 'digitbox') {
            if (!is_numeric($student) || !is_numeric($expected)) {
                return false;
            }

            $role = strtolower(trim((string)($field['role'] ?? '')));
            if ($role === 'coefficient') {
                if ((float)$student == 0.0) {
                    return false;
                }
                $allowfractional = !empty($params['allow_fractional_coefficients']);
                if (!$allowfractional && floor((float)$student) != (float)$student) {
                    return false;
                }
            }

            $tolerance = \qtype_structuredsteps\local\grading_policy::resolve_tolerance($field, $params);
            return \qtype_structuredsteps\local\grading_policy::numbers_match($student, $expected, $tolerance);
        }

        if ($fieldtype === 'unitpicker') {
            return strtolower(trim($student)) === strtolower(trim($expected));
        }

        return trim($student) === trim($expected);
    }

    /**
     * @param string $student
     * @param string $expected
     * @param array $field
     * @param string $fieldtype
     * @param string $mode
     * @param bool $iscorrect
     * @param array $params
     * @return array{error_type:?string,diagnostic_code:?string}
     */
    private function classify_field_error(
        string $student,
        string $expected,
        array $field,
        string $fieldtype,
        string $mode,
        bool $iscorrect,
        array $params
    ): array {
        if ($iscorrect) {
            return ['error_type' => null, 'diagnostic_code' => null];
        }

        $role = strtolower(trim((string)($field['role'] ?? '')));
        $fieldid = strtolower(trim((string)($field['id'] ?? '')));

        if (trim($student) === '') {
            return ['error_type' => 'procedural_error', 'diagnostic_code' => 'STOICH_MISSING_ENTRY'];
        }

        if (($fieldtype === 'numberbox' || $fieldtype === 'digitbox') && !is_numeric($student)) {
            return ['error_type' => 'formatting_error', 'diagnostic_code' => 'STOICH_INVALID_NUMBER'];
        }

        if ($role === 'coefficient') {
            if (is_numeric($student) && (float)$student == 0.0) {
                return ['error_type' => 'conceptual_error', 'diagnostic_code' => 'STOICH_ZERO_COEFFICIENT'];
            }
            $allowfractional = !empty($params['allow_fractional_coefficients']);
            if (is_numeric($student) && !$allowfractional && floor((float)$student) != (float)$student) {
                return ['error_type' => 'conceptual_error', 'diagnostic_code' => 'STOICH_NON_INTEGER_COEFFICIENT'];
            }
            return ['error_type' => 'conceptual_error', 'diagnostic_code' => 'STOICH_BALANCING_ERROR'];
        }

        if ($role === 'ratio' || strpos($fieldid, 'ratio_') === 0 || $mode === 'mole_ratio') {
            return ['error_type' => 'conceptual_error', 'diagnostic_code' => 'STOICH_RATIO_ERROR'];
        }

        if ($role === 'unit' || $fieldtype === 'unitpicker') {
            return ['error_type' => 'formatting_error', 'diagnostic_code' => 'STOICH_UNIT_ERROR'];
        }

        if ($role === 'target_substance') {
            return ['error_type' => 'conceptual_error', 'diagnostic_code' => 'STOICH_CONCEPTUAL_ERROR'];
        }

        return ['error_type' => 'computational_error', 'diagnostic_code' => 'STOICH_CONVERSION_ERROR'];
    }

    /**
     * @param array<int,string> $errors
     * @return string
     */
    private function resolve_dominant_error_type(array $errors): string {
        return \qtype_structuredsteps\local\diagnostic_taxonomy::resolve_dominant_error_type($errors);
    }
}
