<?php
// This file is part of Moodle - http://moodle.org/

/**
 * Tests for qtype_structuredsteps grader.
 *
 * @package   qtype_structuredsteps
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Grader tests.
 */
class qtype_structuredsteps_grader_test extends basic_testcase {
    public function test_invalid_model_returns_empty_result(): void {
        $grader = new \qtype_structuredsteps\local\grader();
        $result = $grader->grade_with_details([], '{invalid');

        $this->assertSame(0.0, $result['fraction']);
        $this->assertSame(0.0, $result['max_score']);
        $this->assertSame(['invalid_model'], $result['errors']);
    }

    public function test_fallback_grade_uses_expected_response_for_unknown_engine(): void {
        $grader = new \qtype_structuredsteps\local\grader();
        $modeljson = json_encode([
            'schema_version' => '1.0',
            'engine' => 'unknown_engine',
            'engine_version' => '1.0',
            'grading' => [
                'expected_response' => '42',
            ],
            'steps' => [],
            'fields' => [],
        ]);

        $correct = $grader->grade_with_details(['answer' => '42'], $modeljson);
        $incorrect = $grader->grade_with_details(['answer' => '41'], $modeljson);

        $this->assertSame(1.0, $correct['fraction']);
        $this->assertSame(0.0, $incorrect['fraction']);
    }

    public function test_long_multiplication_engine_grades_structured_fields(): void {
        $grader = new \qtype_structuredsteps\local\grader();
        $modeljson = $this->structured_model_json();

        $result = $grader->grade_with_details([
            'responses' => [
                's1_c1' => '318',
                's2_c1' => '1060',
            ],
        ], $modeljson);

        $this->assertSame(2.0, $result['score']);
        $this->assertSame(2.0, $result['max_score']);
        $this->assertSame(1.0, $result['fraction']);
        $this->assertArrayHasKey('s1_c1', $result['field_results']);
        $this->assertArrayHasKey('step1', $result['step_results']);
        $this->assertTrue($result['step_results']['step1']['is_correct']);
    }

    public function test_long_multiplication_shift_error_is_structural(): void {
        $grader = new \qtype_structuredsteps\local\grader();
        $modeljson = $this->structured_model_json();

        $result = $grader->grade_with_details([
            'responses' => [
                's1_c1' => '318',
                's2_c1' => '106', // Missing tens shift.
            ],
        ], $modeljson);

        $this->assertSame('structural_error', $result['field_results']['s2_c1']['error_type']);
        $this->assertSame('LM_SHIFT_ERROR', $result['field_results']['s2_c1']['diagnostic_code']);
        $this->assertSame('structural_error', $result['step_results']['step2']['dominant_error_type']);
    }

    public function test_long_multiplication_non_numeric_is_formatting_error(): void {
        $grader = new \qtype_structuredsteps\local\grader();
        $modeljson = $this->structured_model_json();

        $result = $grader->grade_with_details([
            'responses' => [
                's1_c1' => 'not-a-number',
                's2_c1' => '1060',
            ],
        ], $modeljson);

        $this->assertSame('formatting_error', $result['field_results']['s1_c1']['error_type']);
        $this->assertSame('LM_INVALID_NUMBER', $result['field_results']['s1_c1']['diagnostic_code']);
    }

    public function test_step_calculation_engine_grades_with_tolerance(): void {
        $grader = new \qtype_structuredsteps\local\grader();
        $modeljson = json_encode([
            'schema_version' => '1.0',
            'engine' => 'step_calculation',
            'engine_version' => '1.0',
            'grading' => [
                'mode' => 'field_sum',
            ],
            'steps' => [
                ['id' => 'calc1', 'label' => 'Calculation', 'type' => 'computational', 'marks' => 2],
            ],
            'fields' => [
                ['id' => 'f_num', 'step_id' => 'calc1', 'type' => 'numberbox', 'expected' => '10', 'marks' => 1, 'tolerance' => 0.5],
                ['id' => 'f_text', 'step_id' => 'calc1', 'type' => 'structured_text', 'expected' => 'method', 'marks' => 1],
            ],
        ]);

        $result = $grader->grade_with_details([
            'responses' => [
                'f_num' => '10.4',
                'f_text' => 'method',
            ],
        ], $modeljson);

        $this->assertSame(2.0, $result['score']);
        $this->assertSame(2.0, $result['max_score']);
        $this->assertSame(1.0, $result['fraction']);
        $this->assertTrue($result['step_results']['calc1']['is_correct']);
    }

    public function test_step_calculation_choice_mismatch_is_conceptual_error(): void {
        $grader = new \qtype_structuredsteps\local\grader();
        $modeljson = json_encode([
            'schema_version' => '1.0',
            'engine' => 'step_calculation',
            'engine_version' => '1.0',
            'grading' => [
                'mode' => 'field_sum',
            ],
            'steps' => [
                ['id' => 'calc2', 'label' => 'Choose formula', 'type' => 'method', 'marks' => 1],
            ],
            'fields' => [
                ['id' => 'f_choice', 'step_id' => 'calc2', 'type' => 'choice', 'expected' => 'A', 'marks' => 1],
            ],
        ]);

        $result = $grader->grade_with_details([
            'responses' => [
                'f_choice' => 'B',
            ],
        ], $modeljson);

        $this->assertSame('conceptual_error', $result['field_results']['f_choice']['error_type']);
        $this->assertSame('SC_FORMULA_SELECTION_ERROR', $result['field_results']['f_choice']['diagnostic_code']);
        $this->assertSame('conceptual_error', $result['step_results']['calc2']['dominant_error_type']);
    }

    public function test_ledger_poa_choice_mismatch_is_conceptual_error(): void {
        $grader = new \qtype_structuredsteps\local\grader();
        $modeljson = $this->ledger_poa_model_json('t_account');

        $result = $grader->grade_with_details([
            'responses' => [
                'entry_side' => 'credit',
                'entry_amount' => '500',
                'entry_narration' => 'Cash received',
            ],
        ], $modeljson);

        $this->assertSame('conceptual_error', $result['field_results']['entry_side']['error_type']);
        $this->assertSame('POA_SIDE_CLASSIFICATION_ERROR', $result['field_results']['entry_side']['diagnostic_code']);
        $this->assertSame('conceptual_error', $result['step_results']['poa_step1']['dominant_error_type']);
    }

    public function test_ledger_poa_trial_balance_mismatch_is_procedural_error(): void {
        $grader = new \qtype_structuredsteps\local\grader();
        $modeljson = $this->ledger_poa_model_json('trial_balance');

        $result = $grader->grade_with_details([
            'responses' => [
                'entry_side' => 'debit',
                'entry_amount' => '400',
                'entry_narration' => 'Cash received',
            ],
        ], $modeljson);

        $this->assertSame('procedural_error', $result['field_results']['entry_amount']['error_type']);
        $this->assertSame('POA_TRIAL_BALANCE_MISMATCH', $result['field_results']['entry_amount']['diagnostic_code']);
    }

    public function test_ledger_poa_narration_mismatch_is_structural_error(): void {
        $grader = new \qtype_structuredsteps\local\grader();
        $modeljson = $this->ledger_poa_model_json('journal_entry');

        $result = $grader->grade_with_details([
            'responses' => [
                'entry_side' => 'debit',
                'entry_amount' => '500',
                'entry_narration' => 'Wrong narration',
            ],
        ], $modeljson);

        $this->assertSame('structural_error', $result['field_results']['entry_narration']['error_type']);
        $this->assertSame('POA_NARRATION_ERROR', $result['field_results']['entry_narration']['diagnostic_code']);
    }

    public function test_ledger_poa_balance_side_mismatch_is_structural_error(): void {
        $grader = new \qtype_structuredsteps\local\grader();
        $modeljson = $this->ledger_poa_model_json('t_account', true, 2);

        $result = $grader->grade_with_details([
            'responses' => [
                'entry_side' => 'credit',
                'entry_amount' => '500',
                'entry_narration' => 'Cash received',
            ],
        ], $modeljson);

        $this->assertSame('structural_error', $result['field_results']['entry_side']['error_type']);
        $this->assertSame('POA_BALANCE_SIDE_ERROR', $result['field_results']['entry_side']['diagnostic_code']);
    }

    public function test_ledger_poa_decimal_places_are_applied_for_numberbox_grading(): void {
        $grader = new \qtype_structuredsteps\local\grader();
        $modeljson = $this->ledger_poa_model_json('journal_entry', false, 2);

        $result = $grader->grade_with_details([
            'responses' => [
                'entry_side' => 'debit',
                'entry_amount' => '500.004',
                'entry_narration' => 'Cash received',
            ],
        ], $modeljson);

        $this->assertTrue($result['field_results']['entry_amount']['is_correct']);
        $this->assertSame(1.0, $result['field_results']['entry_amount']['awarded_marks']);
    }

    public function test_trial_balance_can_award_procedural_method_marks_when_totals_balance(): void {
        $grader = new \qtype_structuredsteps\local\grader();
        $modeljson = $this->ledger_trial_balance_model_json(true, 2.0);

        $result = $grader->grade_with_details([
            'responses' => [
                // Wrong vs expected values, but totals are balanced.
                'tb_debit_1' => '120',
                'tb_credit_1' => '120',
            ],
        ], $modeljson);

        $this->assertSame(2.0, $result['score']);
        $this->assertSame(4.0, $result['max_score']);
        $this->assertSame(0.5, $result['fraction']);
        $this->assertArrayHasKey('tb_proc', $result['step_results']);
        $this->assertTrue($result['step_results']['tb_proc']['is_correct']);
        $this->assertSame(2.0, $result['step_results']['tb_proc']['awarded_marks']);
        $this->assertSame(2.0, $result['step_results']['tb_proc']['max_marks']);
    }

    public function test_trial_balance_procedural_method_marks_not_awarded_when_unbalanced(): void {
        $grader = new \qtype_structuredsteps\local\grader();
        $modeljson = $this->ledger_trial_balance_model_json(true, 2.0);

        $result = $grader->grade_with_details([
            'responses' => [
                'tb_debit_1' => '120',
                'tb_credit_1' => '90',
            ],
        ], $modeljson);

        $this->assertSame(0.0, $result['score']);
        $this->assertSame(4.0, $result['max_score']);
        $this->assertArrayHasKey('tb_proc', $result['step_results']);
        $this->assertFalse($result['step_results']['tb_proc']['is_correct']);
        $this->assertSame('procedural_error', $result['step_results']['tb_proc']['dominant_error_type']);
        $this->assertContains('POA_TRIAL_BALANCE_NOT_BALANCED', $result['step_results']['tb_proc']['diagnostic_codes']);
    }

    public function test_long_division_structured_mode_grades_all_fields(): void {
        $grader = new \qtype_structuredsteps\local\grader();
        $modeljson = $this->long_division_model_json('structured', true);

        $result = $grader->grade_with_details([
            'responses' => [
                'q1' => '6',
                's1_result' => '6',
                'q2' => '5',
                'remainder' => '4',
            ],
        ], $modeljson);

        $this->assertSame(4.0, $result['score']);
        $this->assertSame(4.0, $result['max_score']);
        $this->assertSame(1.0, $result['fraction']);
    }

    public function test_long_division_quotient_error_is_classified(): void {
        $grader = new \qtype_structuredsteps\local\grader();
        $modeljson = $this->long_division_model_json('structured', true);

        $result = $grader->grade_with_details([
            'responses' => [
                'q1' => '5',
                's1_result' => '6',
                'q2' => '5',
                'remainder' => '4',
            ],
        ], $modeljson);

        $this->assertSame('conceptual_error', $result['field_results']['q1']['error_type']);
        $this->assertSame('LD_DIVISION_ERROR', $result['field_results']['q1']['diagnostic_code']);
    }

    public function test_long_division_remainder_must_be_less_than_divisor(): void {
        $grader = new \qtype_structuredsteps\local\grader();
        $modeljson = $this->long_division_model_json('structured', true);

        $result = $grader->grade_with_details([
            'responses' => [
                'q1' => '6',
                's1_result' => '6',
                'q2' => '5',
                'remainder' => '12',
            ],
        ], $modeljson);

        $this->assertSame('computational_error', $result['field_results']['remainder']['error_type']);
        $this->assertSame('LD_REMAINDER_ERROR', $result['field_results']['remainder']['diagnostic_code']);
    }

    public function test_long_division_quotient_only_mode_skips_subtraction_fields(): void {
        $grader = new \qtype_structuredsteps\local\grader();
        $modeljson = $this->long_division_model_json('quotient_only', true);

        $result = $grader->grade_with_details([
            'responses' => [
                'q1' => '6',
                'q2' => '5',
                'remainder' => '4',
            ],
        ], $modeljson);

        $this->assertSame(3.0, $result['score']);
        $this->assertSame(3.0, $result['max_score']);
        $this->assertArrayNotHasKey('s1_result', $result['field_results']);
    }

    public function test_stoichiometry_mass_to_mass_grades_all_steps(): void {
        $grader = new \qtype_structuredsteps\local\grader();
        $modeljson = $this->stoichiometry_model_json();

        $result = $grader->grade_with_details([
            'responses' => [
                'coef_h2' => '2',
                'coef_o2' => '1',
                'coef_h2o' => '2',
                'moles_given' => '2',
                'ratio_numerator' => '2',
                'ratio_denominator' => '2',
                'mass_target' => '36',
                'unit' => 'g',
            ],
        ], $modeljson);

        $this->assertSame(8.0, $result['score']);
        $this->assertSame(8.0, $result['max_score']);
        $this->assertSame(1.0, $result['fraction']);
    }

    public function test_stoichiometry_ratio_error_has_deterministic_diagnostic(): void {
        $grader = new \qtype_structuredsteps\local\grader();
        $modeljson = $this->stoichiometry_model_json();

        $result = $grader->grade_with_details([
            'responses' => [
                'coef_h2' => '2',
                'coef_o2' => '1',
                'coef_h2o' => '2',
                'moles_given' => '2',
                'ratio_numerator' => '1',
                'ratio_denominator' => '2',
                'mass_target' => '36',
                'unit' => 'g',
            ],
        ], $modeljson);

        $this->assertSame('conceptual_error', $result['field_results']['ratio_numerator']['error_type']);
        $this->assertSame('STOICH_RATIO_ERROR', $result['field_results']['ratio_numerator']['diagnostic_code']);
    }

    public function test_stoichiometry_conversion_tolerance_boundary_is_accepted(): void {
        $grader = new \qtype_structuredsteps\local\grader();
        $modeljson = $this->stoichiometry_model_json();

        $result = $grader->grade_with_details([
            'responses' => [
                'coef_h2' => '2',
                'coef_o2' => '1',
                'coef_h2o' => '2',
                'moles_given' => '2',
                'ratio_numerator' => '2',
                'ratio_denominator' => '2',
                'mass_target' => '36.01',
                'unit' => 'g',
            ],
        ], $modeljson);

        $this->assertTrue($result['field_results']['mass_target']['is_correct']);
    }

    public function test_stoichiometry_unit_mismatch_has_unit_diagnostic(): void {
        $grader = new \qtype_structuredsteps\local\grader();
        $modeljson = $this->stoichiometry_model_json();

        $result = $grader->grade_with_details([
            'responses' => [
                'coef_h2' => '2',
                'coef_o2' => '1',
                'coef_h2o' => '2',
                'moles_given' => '2',
                'ratio_numerator' => '2',
                'ratio_denominator' => '2',
                'mass_target' => '36',
                'unit' => 'kg',
            ],
        ], $modeljson);

        $this->assertSame('formatting_error', $result['field_results']['unit']['error_type']);
        $this->assertSame('STOICH_UNIT_ERROR', $result['field_results']['unit']['diagnostic_code']);
    }

    public function test_stoichiometry_zero_coefficient_is_invalid_balancing(): void {
        $grader = new \qtype_structuredsteps\local\grader();
        $modeljson = $this->stoichiometry_model_json();

        $result = $grader->grade_with_details([
            'responses' => [
                'coef_h2' => '2',
                'coef_o2' => '0',
                'coef_h2o' => '2',
                'moles_given' => '2',
                'ratio_numerator' => '2',
                'ratio_denominator' => '2',
                'mass_target' => '36',
                'unit' => 'g',
            ],
        ], $modeljson);

        $this->assertSame('conceptual_error', $result['field_results']['coef_o2']['error_type']);
        $this->assertSame('STOICH_ZERO_COEFFICIENT', $result['field_results']['coef_o2']['diagnostic_code']);
    }

    public function test_evidence_table_correct_keyword_match_gets_full_marks(): void {
        $grader = new \qtype_structuredsteps\local\grader();
        $modeljson = $this->evidence_table_model_json();

        $result = $grader->grade_with_details([
            'responses' => [
                'answer' => 'The character feels lonely.',
                'evidence' => 'Nobody spoke to him in the empty room.',
                'explanation' => 'This shows he is disconnected because no social support is present.',
            ],
        ], $modeljson);

        $this->assertSame(3.0, $result['score']);
        $this->assertSame(3.0, $result['max_score']);
        $this->assertSame(1.0, $result['fraction']);
    }

    public function test_evidence_table_partial_credit_when_only_answer_matches(): void {
        $grader = new \qtype_structuredsteps\local\grader();
        $modeljson = $this->evidence_table_model_json();

        $result = $grader->grade_with_details([
            'responses' => [
                'answer' => 'He feels isolated from others.',
                'evidence' => 'Something irrelevant happened.',
                'explanation' => 'Too short.',
            ],
        ], $modeljson);

        $this->assertSame(1.0, $result['score']);
        $this->assertSame(3.0, $result['max_score']);
        $this->assertSame('conceptual_error', $result['field_results']['evidence']['error_type']);
        $this->assertSame('EVT_EVIDENCE_KEYWORD_MISSING', $result['field_results']['evidence']['diagnostic_code']);
    }

    public function test_evidence_table_keyword_match_is_case_insensitive(): void {
        $grader = new \qtype_structuredsteps\local\grader();
        $modeljson = $this->evidence_table_model_json();

        $result = $grader->grade_with_details([
            'responses' => [
                'answer' => 'The speaker is LONELY.',
                'evidence' => 'NOBODY SPOKE to her all day.',
                'explanation' => 'This shows her exclusion because she was ignored.',
            ],
        ], $modeljson);

        $this->assertTrue($result['field_results']['answer']['is_correct']);
        $this->assertTrue($result['field_results']['evidence']['is_correct']);
    }

    public function test_evidence_table_explanation_length_and_linking_phrase_enforced(): void {
        $grader = new \qtype_structuredsteps\local\grader();
        $modeljson = $this->evidence_table_model_json();

        $result = $grader->grade_with_details([
            'responses' => [
                'answer' => 'He is lonely.',
                'evidence' => 'No friends were with him.',
                'explanation' => 'Valid length but no connector words present anywhere.',
            ],
        ], $modeljson);

        $this->assertFalse($result['field_results']['explanation']['is_correct']);
        $this->assertSame('conceptual_error', $result['field_results']['explanation']['error_type']);
        $this->assertSame('EVT_EXPLANATION_INSUFFICIENT', $result['field_results']['explanation']['diagnostic_code']);
    }

    public function test_evidence_table_max_length_violation_is_communication_error(): void {
        $grader = new \qtype_structuredsteps\local\grader();
        $modeljson = $this->evidence_table_model_json(40);

        $result = $grader->grade_with_details([
            'responses' => [
                'answer' => 'This response is deliberately very long and exceeds the configured maximum length.',
                'evidence' => 'No friends were with him.',
                'explanation' => 'This shows loneliness because isolation is explicit in the scenario.',
            ],
        ], $modeljson);

        $this->assertSame('communication_error', $result['field_results']['answer']['error_type']);
        $this->assertSame('EVT_MAX_LENGTH_EXCEEDED', $result['field_results']['answer']['diagnostic_code']);
    }

    public function test_graphing_correct_point_detection_within_tolerance(): void {
        $grader = new \qtype_structuredsteps\local\grader();
        $modeljson = $this->graphing_model_json();

        $result = $grader->grade_with_details([
            'responses' => [
                'plot' => json_encode(['points' => [['x' => 1.05, 'y' => 2.02], ['x' => 1.99, 'y' => 4.01]]]),
                'gradient' => '2',
                'intercept_y' => '0',
            ],
        ], $modeljson);

        $this->assertTrue($result['field_results']['p1']['is_correct']);
        $this->assertTrue($result['field_results']['p2']['is_correct']);
    }

    public function test_graphing_incorrect_point_outside_tolerance(): void {
        $grader = new \qtype_structuredsteps\local\grader();
        $modeljson = $this->graphing_model_json();

        $result = $grader->grade_with_details([
            'responses' => [
                'plot' => json_encode(['points' => [['x' => 1.5, 'y' => 2.6], ['x' => 2, 'y' => 4]]]),
                'gradient' => '2',
                'intercept_y' => '0',
            ],
        ], $modeljson);

        $this->assertFalse($result['field_results']['p1']['is_correct']);
        $this->assertSame('GR_PLOTTING_ERROR', $result['field_results']['p1']['diagnostic_code']);
    }

    public function test_graphing_gradient_tolerance_boundary(): void {
        $grader = new \qtype_structuredsteps\local\grader();
        $modeljson = $this->graphing_model_json();

        $result = $grader->grade_with_details([
            'responses' => [
                'plot' => json_encode(['points' => [['x' => 1, 'y' => 2], ['x' => 2, 'y' => 4]]]),
                'gradient' => '2.099',
                'intercept_y' => '0',
            ],
        ], $modeljson);

        $this->assertTrue($result['field_results']['gradient']['is_correct']);
    }

    public function test_graphing_intercept_test(): void {
        $grader = new \qtype_structuredsteps\local\grader();
        $modeljson = $this->graphing_model_json();

        $result = $grader->grade_with_details([
            'responses' => [
                'plot' => json_encode(['points' => [['x' => 1, 'y' => 2], ['x' => 2, 'y' => 4]]]),
                'gradient' => '2',
                'intercept_y' => '0.08',
            ],
        ], $modeljson);

        $this->assertTrue($result['field_results']['intercept_y']['is_correct']);
    }

    public function test_graphing_vertical_line_accepts_undefined_gradient(): void {
        $grader = new \qtype_structuredsteps\local\grader();
        $modeljson = $this->graphing_vertical_model_json();

        $result = $grader->grade_with_details([
            'responses' => [
                'plot' => json_encode(['points' => [['x' => 2, 'y' => 1], ['x' => 2, 'y' => 4]]]),
                'gradient' => 'undefined',
            ],
        ], $modeljson);

        $this->assertTrue($result['field_results']['gradient']['is_correct']);
    }

    public function test_graphing_multiple_point_scoring_is_partial(): void {
        $grader = new \qtype_structuredsteps\local\grader();
        $modeljson = $this->graphing_model_json();

        $result = $grader->grade_with_details([
            'responses' => [
                'plot' => json_encode(['points' => [['x' => 1, 'y' => 2], ['x' => 9, 'y' => 9]]]),
                'gradient' => '2',
                'intercept_y' => '0',
            ],
        ], $modeljson);

        $this->assertSame(3.0, $result['score']);
        $this->assertSame(4.0, $result['max_score']);
        $this->assertSame(0.75, $result['fraction']);
    }

    public function test_long_multiplication_engine_returns_validation_error_for_empty_response(): void {
        $grader = new \qtype_structuredsteps\local\grader();
        $modeljson = $this->structured_model_json();

        $result = $grader->grade_with_details([], $modeljson);

        $this->assertSame(0.0, $result['fraction']);
        $this->assertSame(['answer' => 'missing'], $result['errors']);
    }

    public function test_get_expected_response_reads_grading_field(): void {
        $grader = new \qtype_structuredsteps\local\grader();
        $modeljson = json_encode([
            'schema_version' => '1.0',
            'engine' => 'unknown_engine',
            'engine_version' => '1.0',
            'grading' => [
                'expected_response' => 'abc',
            ],
            'steps' => [],
            'fields' => [],
        ]);

        $this->assertSame('abc', $grader->get_expected_response($modeljson));
    }

    private function structured_model_json(): string {
        return json_encode([
            'schema_version' => '1.0',
            'engine' => 'long_multiplication',
            'engine_version' => '1.0',
            'grading' => [
                'mode' => 'field_sum',
            ],
            'steps' => [
                ['id' => 'step1', 'label' => 'Step 1', 'type' => 'computational', 'marks' => 1],
                ['id' => 'step2', 'label' => 'Step 2', 'type' => 'computational', 'marks' => 1],
            ],
            'fields' => [
                ['id' => 's1_c1', 'step_id' => 'step1', 'type' => 'numberbox', 'expected' => '318', 'marks' => 1, 'tolerance' => 0],
                ['id' => 's2_c1', 'step_id' => 'step2', 'type' => 'numberbox', 'expected' => '1060', 'marks' => 1, 'tolerance' => 0],
            ],
        ]);
    }

    private function ledger_poa_model_json(string $mode, bool $balancefield = false, int $decimalplaces = 0): string {
        $sidefield = ['id' => 'entry_side', 'step_id' => 'poa_step1', 'type' => 'choice', 'expected' => 'debit', 'marks' => 1];
        if ($balancefield) {
            $sidefield['role'] = 'balance_side';
        }

        return json_encode([
            'schema_version' => '1.0',
            'engine' => 'ledger_poa',
            'engine_version' => '1.0',
            'params' => [
                'mode' => $mode,
                'decimal_places' => $decimalplaces,
            ],
            'grading' => [
                'mode' => 'field_sum',
            ],
            'steps' => [
                ['id' => 'poa_step1', 'label' => 'Entry', 'type' => 'conceptual', 'marks' => 3],
            ],
            'fields' => [
                $sidefield,
                ['id' => 'entry_amount', 'step_id' => 'poa_step1', 'type' => 'numberbox', 'expected' => '500', 'marks' => 1, 'tolerance' => 0],
                ['id' => 'entry_narration', 'step_id' => 'poa_step1', 'type' => 'text', 'expected' => 'Cash received', 'marks' => 1],
            ],
        ]);
    }

    private function long_division_model_json(string $entrymode, bool $showremainder): string {
        return json_encode([
            'schema_version' => '1.0',
            'engine' => 'long_division',
            'engine_version' => '1.0',
            'params' => [
                'dividend' => 784,
                'divisor' => 12,
                'show_remainder' => $showremainder,
                'entry_mode' => $entrymode,
            ],
            'grading' => [
                'mode' => 'field_sum',
            ],
            'steps' => [
                ['id' => 'ld_step1', 'label' => 'Divide 78 by 12', 'type' => 'procedural', 'marks' => 1],
                ['id' => 'ld_step2', 'label' => 'Subtract 72', 'type' => 'computational', 'marks' => 1],
                ['id' => 'ld_step3', 'label' => 'Divide 64 by 12', 'type' => 'procedural', 'marks' => 1],
                ['id' => 'ld_final', 'label' => 'Remainder', 'type' => 'computational', 'marks' => 1],
            ],
            'fields' => [
                ['id' => 'q1', 'step_id' => 'ld_step1', 'type' => 'digitbox', 'expected' => '6', 'marks' => 1, 'role' => 'quotient_digit'],
                ['id' => 's1_result', 'step_id' => 'ld_step2', 'type' => 'digitbox', 'expected' => '6', 'marks' => 1, 'role' => 'subtraction_result'],
                ['id' => 'q2', 'step_id' => 'ld_step3', 'type' => 'digitbox', 'expected' => '5', 'marks' => 1, 'role' => 'quotient_digit'],
                ['id' => 'remainder', 'step_id' => 'ld_final', 'type' => 'numberbox', 'expected' => '4', 'marks' => 1, 'role' => 'remainder'],
            ],
        ]);
    }

    private function ledger_trial_balance_model_json(bool $awardmethodmarks, float $methodmarks): string {
        return json_encode([
            'schema_version' => '1.0',
            'engine' => 'ledger_poa',
            'engine_version' => '1.0',
            'params' => [
                'mode' => 'trial_balance',
                'award_procedural_if_balanced' => $awardmethodmarks,
                'trial_balance_method_marks' => $methodmarks,
                'trial_balance_procedural_step_id' => 'tb_proc',
                'trial_balance_balance_tolerance' => 0,
            ],
            'grading' => [
                'mode' => 'field_sum',
            ],
            'steps' => [
                ['id' => 'tb_entries', 'label' => 'Trial balance entries', 'type' => 'computational', 'marks' => 2],
            ],
            'fields' => [
                ['id' => 'tb_debit_1', 'step_id' => 'tb_entries', 'type' => 'numberbox', 'expected' => '100', 'marks' => 1, 'role' => 'trial_debit'],
                ['id' => 'tb_credit_1', 'step_id' => 'tb_entries', 'type' => 'numberbox', 'expected' => '100', 'marks' => 1, 'role' => 'trial_credit'],
            ],
        ]);
    }

    private function stoichiometry_model_json(): string {
        return json_encode([
            'schema_version' => '1.0',
            'engine' => 'stoichiometry',
            'engine_version' => '1.0',
            'params' => [
                'mode' => 'mass_to_mass',
                'equation' => '2H2 + O2 -> 2H2O',
                'tolerance' => 0.01,
                'expected_unit' => 'g',
                'allow_fractional_coefficients' => false,
            ],
            'grading' => [
                'mode' => 'field_sum',
            ],
            'steps' => [
                ['id' => 'balance', 'label' => 'Balance equation', 'type' => 'conceptual', 'marks' => 3],
                ['id' => 'moles_given_step', 'label' => 'Mass to moles', 'type' => 'computational', 'marks' => 1],
                ['id' => 'ratio_step', 'label' => 'Mole ratio', 'type' => 'conceptual', 'marks' => 2],
                ['id' => 'mass_target_step', 'label' => 'Moles to mass', 'type' => 'computational', 'marks' => 1],
                ['id' => 'unit_step', 'label' => 'Unit', 'type' => 'procedural', 'marks' => 1],
            ],
            'fields' => [
                ['id' => 'coef_h2', 'step_id' => 'balance', 'type' => 'digitbox', 'expected' => '2', 'marks' => 1, 'role' => 'coefficient'],
                ['id' => 'coef_o2', 'step_id' => 'balance', 'type' => 'digitbox', 'expected' => '1', 'marks' => 1, 'role' => 'coefficient'],
                ['id' => 'coef_h2o', 'step_id' => 'balance', 'type' => 'digitbox', 'expected' => '2', 'marks' => 1, 'role' => 'coefficient'],
                ['id' => 'moles_given', 'step_id' => 'moles_given_step', 'type' => 'numberbox', 'expected' => '2', 'marks' => 1, 'role' => 'conversion'],
                ['id' => 'ratio_numerator', 'step_id' => 'ratio_step', 'type' => 'numberbox', 'expected' => '2', 'marks' => 1, 'role' => 'ratio'],
                ['id' => 'ratio_denominator', 'step_id' => 'ratio_step', 'type' => 'numberbox', 'expected' => '2', 'marks' => 1, 'role' => 'ratio'],
                ['id' => 'mass_target', 'step_id' => 'mass_target_step', 'type' => 'numberbox', 'expected' => '36', 'marks' => 1, 'role' => 'conversion'],
                ['id' => 'unit', 'step_id' => 'unit_step', 'type' => 'unitpicker', 'expected' => 'g', 'marks' => 1, 'role' => 'unit'],
            ],
        ]);
    }

    private function evidence_table_model_json(int $maxlength = 150): string {
        return json_encode([
            'schema_version' => '1.0',
            'engine' => 'evidence_table',
            'engine_version' => '1.0',
            'params' => [
                'mode' => 'answer_evidence',
                'expected_answer_keywords' => ['lonely', 'isolated', 'alone'],
                'expected_evidence_keywords' => ['no friends', 'nobody spoke', 'empty room'],
                'require_explanation' => true,
                'explanation_min_length' => 20,
                'linking_phrases' => ['because', 'this shows', 'therefore'],
                'max_length' => $maxlength,
            ],
            'grading' => [
                'mode' => 'field_sum',
            ],
            'steps' => [
                ['id' => 'answer_step', 'label' => 'Answer', 'type' => 'conceptual', 'marks' => 1],
                ['id' => 'evidence_step', 'label' => 'Evidence', 'type' => 'justification', 'marks' => 1],
                ['id' => 'explanation_step', 'label' => 'Explanation', 'type' => 'justification', 'marks' => 1],
            ],
            'fields' => [
                ['id' => 'answer', 'step_id' => 'answer_step', 'type' => 'structured_text', 'marks' => 1, 'role' => 'answer'],
                ['id' => 'evidence', 'step_id' => 'evidence_step', 'type' => 'structured_text', 'marks' => 1, 'role' => 'evidence'],
                ['id' => 'explanation', 'step_id' => 'explanation_step', 'type' => 'structured_text', 'marks' => 1, 'role' => 'explanation'],
            ],
        ]);
    }

    private function graphing_model_json(): string {
        return json_encode([
            'schema_version' => '1.0',
            'engine' => 'graphing',
            'engine_version' => '1.0',
            'params' => [
                'mode' => 'straight_line',
                'tolerance' => 0.1,
            ],
            'grading' => [
                'mode' => 'field_sum',
            ],
            'steps' => [
                ['id' => 'plot_step', 'label' => 'Plot points', 'type' => 'procedural', 'marks' => 2],
                ['id' => 'grad_step', 'label' => 'Gradient', 'type' => 'computational', 'marks' => 1],
                ['id' => 'int_step', 'label' => 'Intercept', 'type' => 'computational', 'marks' => 1],
            ],
            'fields' => [
                ['id' => 'p1', 'step_id' => 'plot_step', 'type' => 'graphplot', 'marks' => 1, 'role' => 'point', 'expected_x' => 1, 'expected_y' => 2, 'source' => 'plot'],
                ['id' => 'p2', 'step_id' => 'plot_step', 'type' => 'graphplot', 'marks' => 1, 'role' => 'point', 'expected_x' => 2, 'expected_y' => 4, 'source' => 'plot'],
                ['id' => 'gradient', 'step_id' => 'grad_step', 'type' => 'numberbox', 'marks' => 1, 'role' => 'gradient', 'expected' => '2', 'tolerance' => 0.1],
                ['id' => 'intercept_y', 'step_id' => 'int_step', 'type' => 'numberbox', 'marks' => 1, 'role' => 'intercept_y', 'expected' => '0', 'tolerance' => 0.1],
            ],
        ]);
    }

    private function graphing_vertical_model_json(): string {
        return json_encode([
            'schema_version' => '1.0',
            'engine' => 'graphing',
            'engine_version' => '1.0',
            'params' => [
                'mode' => 'gradient',
                'tolerance' => 0.1,
            ],
            'grading' => [
                'mode' => 'field_sum',
            ],
            'steps' => [
                ['id' => 'plot_step', 'label' => 'Plot points', 'type' => 'procedural', 'marks' => 1],
                ['id' => 'grad_step', 'label' => 'Gradient', 'type' => 'computational', 'marks' => 1],
            ],
            'fields' => [
                ['id' => 'p1', 'step_id' => 'plot_step', 'type' => 'graphplot', 'marks' => 1, 'role' => 'point', 'expected_x' => 2, 'expected_y' => 1, 'source' => 'plot'],
                ['id' => 'gradient', 'step_id' => 'grad_step', 'type' => 'structured_text', 'marks' => 1, 'role' => 'gradient', 'expected' => 'undefined'],
            ],
        ]);
    }
}
