<?php
// This file is part of Moodle - http://moodle.org/

namespace qtype_structuredsteps\local\engine;

defined('MOODLE_INTERNAL') || die();

/**
 * Graphing and graph interpretation engine.
 */
class graphing_engine extends base_engine {
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
        $tolerance = \qtype_structuredsteps\local\grading_policy::resolve_tolerance([], $params, 0.1);

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
                $studentraw = $this->extract_student_value($response, $responses, $fieldid, $field);
                $iscorrect = $this->is_field_correct($studentraw, $expected, $fieldtype, $field, $tolerance);
                $awarded = $iscorrect ? $maxmarks : 0.0;

                $diagnostics = $this->classify_field_error($studentraw, $fieldtype, $field, $iscorrect);
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
            'engine_name' => 'graphing',
            'version' => '1.0',
            'supported_modes' => ['plot_points', 'straight_line', 'read_values', 'gradient', 'intercepts', 'economics_curve', 'transformation'],
            'supported_field_types' => ['graphplot', 'numberbox', 'mathfield', 'choice', 'structured_text'],
            'recommended_layout' => 'graph_step_cards',
            'step_types' => ['procedural', 'computational', 'interpretation'],
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
            } else if (is_array($value)) {
                $normalised[(string)$key] = json_encode($value);
            }
        }
        return $normalised;
    }

    /**
     * @param array $response
     * @param array<string,string> $responses
     * @param string $fieldid
     * @param array $field
     * @return mixed
     */
    private function extract_student_value(array $response, array $responses, string $fieldid, array $field) {
        if (isset($responses[$fieldid])) {
            return $responses[$fieldid];
        }

        $source = isset($field['source']) ? (string)$field['source'] : '';
        if ($source !== '') {
            if (isset($response['responses'][$source])) {
                return $response['responses'][$source];
            }
            if (isset($response[$source])) {
                return $response[$source];
            }
            if (isset($responses[$source])) {
                return $responses[$source];
            }
        }

        return '';
    }

    /**
     * @param mixed $student
     * @param string $expected
     * @param string $fieldtype
     * @param array $field
     * @param float $defaulttolerance
     * @return bool
     */
    private function is_field_correct($student, string $expected, string $fieldtype, array $field, float $defaulttolerance): bool {
        $role = strtolower(trim((string)($field['role'] ?? '')));
        $tolerance = \qtype_structuredsteps\local\grading_policy::resolve_tolerance($field, [], $defaulttolerance);

        if ($role === 'point' || $fieldtype === 'graphplot') {
            return $this->is_point_within_tolerance($student, $expected, $field, $tolerance);
        }

        if ($role === 'gradient') {
            return $this->is_gradient_correct($student, $expected, $tolerance);
        }

        if ($role === 'intercept_x' || $role === 'intercept_y' || $role === 'intercept') {
            return $this->is_numeric_match($student, $expected, $tolerance);
        }

        if ($role === 'axis_label_x' || $role === 'axis_label_y') {
            return strtolower(trim((string)$student)) === strtolower(trim($expected));
        }

        return $this->is_numeric_match($student, $expected, $tolerance);
    }

    /**
     * @param mixed $student
     * @param string $expected
     * @param array $field
     * @param float $tolerance
     * @return bool
     */
    private function is_point_within_tolerance($student, string $expected, array $field, float $tolerance): bool {
        $expectedpoint = $this->decode_expected_point($expected, $field);
        if ($expectedpoint === null) {
            return false;
        }

        $submission = $this->decode_graph_submission($student);
        if (empty($submission['points']) || !is_array($submission['points'])) {
            return false;
        }

        foreach ($submission['points'] as $point) {
            if (!is_array($point) || !isset($point['x']) || !isset($point['y']) || !is_numeric($point['x']) || !is_numeric($point['y'])) {
                continue;
            }
            $dx = ((float)$point['x'] - (float)$expectedpoint['x']);
            $dy = ((float)$point['y'] - (float)$expectedpoint['y']);
            $distance = sqrt(($dx * $dx) + ($dy * $dy));
            if (\qtype_structuredsteps\local\grading_policy::numbers_match($distance, 0.0, $tolerance)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string $expected
     * @param array $field
     * @return array<string,float>|null
     */
    private function decode_expected_point(string $expected, array $field): ?array {
        if (isset($field['expected_x']) && isset($field['expected_y']) && is_numeric($field['expected_x']) && is_numeric($field['expected_y'])) {
            return ['x' => (float)$field['expected_x'], 'y' => (float)$field['expected_y']];
        }

        if ($expected === '') {
            return null;
        }

        $decoded = json_decode($expected, true);
        if (!is_array($decoded) || !isset($decoded['x']) || !isset($decoded['y']) || !is_numeric($decoded['x']) || !is_numeric($decoded['y'])) {
            return null;
        }
        return ['x' => (float)$decoded['x'], 'y' => (float)$decoded['y']];
    }

    /**
     * @param mixed $student
     * @return array
     */
    private function decode_graph_submission($student): array {
        if (is_array($student)) {
            return $student;
        }

        if (!is_string($student) || trim($student) === '') {
            return [];
        }

        $decoded = json_decode($student, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param mixed $student
     * @param string $expected
     * @param float $tolerance
     * @return bool
     */
    private function is_gradient_correct($student, string $expected, float $tolerance): bool {
        $normalstudent = strtolower(trim((string)$student));
        $normalexpected = strtolower(trim($expected));
        if ($normalexpected === 'undefined') {
            return ($normalstudent === 'undefined' || $normalstudent === 'inf' || $normalstudent === 'infinite');
        }

        return $this->is_numeric_match($student, $expected, $tolerance);
    }

    /**
     * @param mixed $student
     * @param string $expected
     * @param float $tolerance
     * @return bool
     */
    private function is_numeric_match($student, string $expected, float $tolerance): bool {
        return \qtype_structuredsteps\local\grading_policy::numbers_match($student, $expected, $tolerance);
    }

    /**
     * @param mixed $student
     * @param string $fieldtype
     * @param array $field
     * @param bool $iscorrect
     * @return array{error_type:?string,diagnostic_code:?string}
     */
    private function classify_field_error($student, string $fieldtype, array $field, bool $iscorrect): array {
        if ($iscorrect) {
            return ['error_type' => null, 'diagnostic_code' => null];
        }

        $role = strtolower(trim((string)($field['role'] ?? '')));
        if (trim((string)$student) === '') {
            return ['error_type' => 'procedural_error', 'diagnostic_code' => 'GR_MISSING_ENTRY'];
        }

        if ($role === 'point' || $fieldtype === 'graphplot') {
            return ['error_type' => 'procedural_error', 'diagnostic_code' => 'GR_PLOTTING_ERROR'];
        }

        if ($role === 'gradient') {
            return ['error_type' => 'computational_error', 'diagnostic_code' => 'GR_GRADIENT_ERROR'];
        }

        if ($role === 'intercept_x' || $role === 'intercept_y' || $role === 'intercept') {
            return ['error_type' => 'computational_error', 'diagnostic_code' => 'GR_INTERCEPT_ERROR'];
        }

        if ($role === 'axis_label_x' || $role === 'axis_label_y') {
            return ['error_type' => 'structural_error', 'diagnostic_code' => 'GR_AXIS_LABEL_ERROR'];
        }

        return ['error_type' => 'conceptual_error', 'diagnostic_code' => 'GR_INTERPRETATION_ERROR'];
    }

    /**
     * @param array<int,string> $errors
     * @return string
     */
    private function resolve_dominant_error_type(array $errors): string {
        return \qtype_structuredsteps\local\diagnostic_taxonomy::resolve_dominant_error_type($errors);
    }
}
