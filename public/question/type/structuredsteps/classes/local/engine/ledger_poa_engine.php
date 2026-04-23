<?php
// This file is part of Moodle - http://moodle.org/

namespace qtype_structuredsteps\local\engine;

defined('MOODLE_INTERNAL') || die();

/**
 * Ledger and POA structured marking engine.
 */
class ledger_poa_engine extends base_engine {
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
        $mode = isset($model['params']['mode']) ? (string)$model['params']['mode'] : 't_account';
        $decimalplaces = isset($model['params']['decimal_places']) && is_numeric($model['params']['decimal_places'])
            ? max(0, (int)$model['params']['decimal_places'])
            : null;
        $trialsummary = [
            'hasdebit' => false,
            'hascredit' => false,
            'debit' => 0.0,
            'credit' => 0.0,
        ];

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
                $iscorrect = $this->is_field_correct($student, $expected, $fieldtype, $field, $decimalplaces);
                $awarded = $iscorrect ? $maxmarks : 0.0;

                $diagnostics = $this->classify_field_error($student, $expected, $field, $fieldtype, $mode, $iscorrect);
                $fieldresults[$fieldid] = [
                    'is_correct' => $iscorrect,
                    'awarded_marks' => $awarded,
                    'max_marks' => $maxmarks,
                    'error_type' => $diagnostics['error_type'],
                    'diagnostic_code' => $diagnostics['diagnostic_code'],
                ];

                $totalscore += $awarded;
                $totalmax += $maxmarks;

                if ($mode === 'trial_balance') {
                    $this->collect_trial_balance_totals($trialsummary, $field, $student);
                }

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

        if ($mode === 'trial_balance') {
            $this->apply_trial_balance_procedural_marks($trialsummary, $model, $totalscore, $totalmax, $stepaccumulator);
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
            'engine_name' => 'ledger_poa',
            'version' => '1.0',
            'supported_field_types' => ['choice', 'numberbox', 'tokenbank', 'text', 'structured_text'],
            'recommended_layout' => 'ledger',
            'step_types' => ['conceptual', 'computational', 'procedural'],
            'supported_modes' => ['t_account', 'journal_entry', 'cash_book', 'trial_balance', 'balance_only'],
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
     * @param int|null $decimalplaces
     * @return bool
     */
    private function is_field_correct(
        string $student,
        string $expected,
        string $fieldtype,
        array $field,
        ?int $decimalplaces
    ): bool {
        if ($fieldtype === 'numberbox') {
            $tolerance = \qtype_structuredsteps\local\grading_policy::resolve_tolerance($field);
            return \qtype_structuredsteps\local\grading_policy::numbers_match($student, $expected, $tolerance, $decimalplaces);
        }

        if ($fieldtype === 'choice' || $fieldtype === 'tokenbank') {
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
     * @return array{error_type:?string,diagnostic_code:?string}
     */
    private function classify_field_error(
        string $student,
        string $expected,
        array $field,
        string $fieldtype,
        string $mode,
        bool $iscorrect
    ): array {
        if ($iscorrect) {
            return ['error_type' => null, 'diagnostic_code' => null];
        }

        if (trim($student) === '') {
            return ['error_type' => 'procedural_error', 'diagnostic_code' => 'POA_MISSING_ENTRY'];
        }

        if ($fieldtype === 'choice') {
            if ($this->is_balance_side_field($field)) {
                return ['error_type' => 'structural_error', 'diagnostic_code' => 'POA_BALANCE_SIDE_ERROR'];
            }
            return ['error_type' => 'conceptual_error', 'diagnostic_code' => 'POA_SIDE_CLASSIFICATION_ERROR'];
        }

        if ($fieldtype === 'tokenbank') {
            return ['error_type' => 'conceptual_error', 'diagnostic_code' => 'POA_ACCOUNT_CLASSIFICATION_ERROR'];
        }

        if ($fieldtype === 'numberbox') {
            if (!is_numeric($student) || !is_numeric($expected)) {
                return ['error_type' => 'formatting_error', 'diagnostic_code' => 'POA_INVALID_NUMBER'];
            }

            if ($mode === 'trial_balance') {
                return ['error_type' => 'procedural_error', 'diagnostic_code' => 'POA_TRIAL_BALANCE_MISMATCH'];
            }

            return ['error_type' => 'computational_error', 'diagnostic_code' => 'POA_AMOUNT_ERROR'];
        }

        if ($fieldtype === 'text' || $fieldtype === 'structured_text') {
            return ['error_type' => 'structural_error', 'diagnostic_code' => 'POA_NARRATION_ERROR'];
        }

        return ['error_type' => 'conceptual_error', 'diagnostic_code' => 'POA_VALUE_MISMATCH'];
    }

    /**
     * @param array $field
     * @return bool
     */
    private function is_balance_side_field(array $field): bool {
        $role = '';
        if (isset($field['role'])) {
            $role = strtolower(trim((string)$field['role']));
        } elseif (isset($field['poa_role'])) {
            $role = strtolower(trim((string)$field['poa_role']));
        }

        return ($role === 'balance_side' || $role === 'balance-placement');
    }

    /**
     * @param array<string,mixed> $summary
     * @param array $field
     * @param string $student
     * @return void
     */
    private function collect_trial_balance_totals(array &$summary, array $field, string $student): void {
        if (!is_numeric($student)) {
            return;
        }

        $role = strtolower(trim((string)($field['role'] ?? $field['poa_role'] ?? '')));
        $value = (float)$student;

        if ($role === 'trial_debit' || $role === 'trial_balance_debit') {
            $summary['hasdebit'] = true;
            $summary['debit'] += $value;
        }

        if ($role === 'trial_credit' || $role === 'trial_balance_credit') {
            $summary['hascredit'] = true;
            $summary['credit'] += $value;
        }
    }

    /**
     * @param array<string,mixed> $summary
     * @param array $model
     * @param float $totalscore
     * @param float $totalmax
     * @param array<string,array<string,mixed>> $stepaccumulator
     * @return void
     */
    private function apply_trial_balance_procedural_marks(
        array $summary,
        array $model,
        float &$totalscore,
        float &$totalmax,
        array &$stepaccumulator
    ): void {
        if (empty($summary['hasdebit']) || empty($summary['hascredit'])) {
            return;
        }

        $params = $model['params'] ?? [];
        $awardmethodmarks = !empty($params['award_procedural_if_balanced']) || !empty($params['award_method_marks_if_balanced']);
        if (!$awardmethodmarks) {
            return;
        }

        $methodmarks = \qtype_structuredsteps\local\grading_policy::resolve_method_marks($params, 'trial_balance_method_marks', 1.0);
        if ($methodmarks <= 0.0) {
            return;
        }

        $stepid = isset($params['trial_balance_procedural_step_id']) && trim((string)$params['trial_balance_procedural_step_id']) !== ''
            ? trim((string)$params['trial_balance_procedural_step_id'])
            : 'trial_balance_procedural';
        $tolerance = isset($params['trial_balance_balance_tolerance']) && is_numeric($params['trial_balance_balance_tolerance'])
            ? max(0.0, (float)$params['trial_balance_balance_tolerance'])
            : 0.00001;
        $balanced = \qtype_structuredsteps\local\grading_policy::totals_balanced(
            (float)$summary['debit'],
            (float)$summary['credit'],
            $tolerance
        );

        if (!isset($stepaccumulator[$stepid])) {
            $stepaccumulator[$stepid] = [
                'awarded' => 0.0,
                'max' => 0.0,
                'allcorrect' => true,
                'error_types' => [],
                'diagnostic_codes' => [],
            ];
        }

        $stepaccumulator[$stepid]['max'] += $methodmarks;
        $totalmax += $methodmarks;

        if ($balanced) {
            $stepaccumulator[$stepid]['awarded'] += $methodmarks;
            $totalscore += $methodmarks;
            return;
        }

        $stepaccumulator[$stepid]['allcorrect'] = false;
        $stepaccumulator[$stepid]['error_types'][] = 'procedural_error';
        $stepaccumulator[$stepid]['diagnostic_codes'][] = 'POA_TRIAL_BALANCE_NOT_BALANCED';
    }

    /**
     * @param array<int,string> $errors
     * @return string
     */
    private function resolve_dominant_error_type(array $errors): string {
        return \qtype_structuredsteps\local\diagnostic_taxonomy::resolve_dominant_error_type($errors);
    }
}
