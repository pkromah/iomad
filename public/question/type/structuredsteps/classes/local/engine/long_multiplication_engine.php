<?php
// This file is part of Moodle - http://moodle.org/

namespace qtype_structuredsteps\local\engine;

defined('MOODLE_INTERNAL') || die();

/**
 * Baseline long multiplication engine.
 */
class long_multiplication_engine extends base_engine {
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
                $awarded = $iscorrect ? $maxmarks : 0.0;

                $diagnostics = $this->classify_field_error($student, $expected, $fieldtype, $field, $iscorrect);
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
        } else {
            $grading = $model['grading'] ?? [];
            $expected = isset($grading['expected_response']) ? (string)$grading['expected_response'] : '';
            $answer = isset($response['answer']) ? trim((string)$response['answer']) : '';
            $correct = ($expected !== '' && $answer === trim($expected));
            $totalscore = $correct ? 1.0 : 0.0;
            $totalmax = 1.0;
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
            'engine_name' => 'long_multiplication',
            'version' => '1.0',
            'supported_field_types' => ['digitbox', 'numberbox', 'structured_text'],
            'recommended_layout' => 'grid',
            'step_types' => ['computational'],
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
     * @return bool
     */
    private function is_field_correct(string $student, string $expected, string $fieldtype, array $field): bool {
        if ($fieldtype === 'numberbox') {
            $tolerance = \qtype_structuredsteps\local\grading_policy::resolve_tolerance($field);
            return \qtype_structuredsteps\local\grading_policy::numbers_match($student, $expected, $tolerance);
        }

        return trim($student) === trim($expected);
    }

    /**
     * @param string $student
     * @param string $expected
     * @param string $fieldtype
     * @param array $field
     * @param bool $iscorrect
     * @return array{error_type:?string,diagnostic_code:?string}
     */
    private function classify_field_error(
        string $student,
        string $expected,
        string $fieldtype,
        array $field,
        bool $iscorrect
    ): array {
        if ($iscorrect) {
            return ['error_type' => null, 'diagnostic_code' => null];
        }

        if (trim($student) === '') {
            return ['error_type' => 'procedural_error', 'diagnostic_code' => 'LM_MISSING_ENTRY'];
        }

        if ($fieldtype === 'numberbox') {
            if (!is_numeric($student) || !is_numeric($expected)) {
                return ['error_type' => 'formatting_error', 'diagnostic_code' => 'LM_INVALID_NUMBER'];
            }

            $tolerance = isset($field['tolerance']) ? (float)$field['tolerance'] : 0.0;
            if ($this->is_shift_error((float)$student, (float)$expected, $tolerance)) {
                return ['error_type' => 'structural_error', 'diagnostic_code' => 'LM_SHIFT_ERROR'];
            }

            return ['error_type' => 'computational_error', 'diagnostic_code' => 'LM_MULTIPLY_ERROR'];
        }

        if ($fieldtype === 'choice') {
            return ['error_type' => 'conceptual_error', 'diagnostic_code' => 'LM_METHOD_ERROR'];
        }

        return ['error_type' => 'computational_error', 'diagnostic_code' => 'LM_VALUE_MISMATCH'];
    }

    /**
     * @param float $student
     * @param float $expected
     * @param float $tolerance
     * @return bool
     */
    private function is_shift_error(float $student, float $expected, float $tolerance): bool {
        if ($expected == 0.0 || $student == 0.0) {
            return false;
        }

        $checks = [
            abs(($student * 10.0) - $expected),
            abs(($expected * 10.0) - $student),
        ];

        foreach ($checks as $delta) {
            if (\qtype_structuredsteps\local\grading_policy::numbers_match($delta, 0.0, $tolerance)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int,string> $errors
     * @return string
     */
    private function resolve_dominant_error_type(array $errors): string {
        return \qtype_structuredsteps\local\diagnostic_taxonomy::resolve_dominant_error_type($errors);
    }
}
