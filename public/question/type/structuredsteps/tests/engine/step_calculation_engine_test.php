<?php
// This file is part of Moodle - http://moodle.org/

/**
 * Tests for qtype_structuredsteps step_calculation_engine.
 *
 * Covers formula-selection → substitution → computation step sequences
 * matching CXC marking scheme: method marks on procedure, accuracy on result.
 *
 * @package   qtype_structuredsteps
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Step calculation engine grading tests.
 */
class qtype_structuredsteps_step_calculation_engine_test extends basic_testcase {

    private function make_engine(): \qtype_structuredsteps\local\engine\step_calculation_engine {
        return new \qtype_structuredsteps\local\engine\step_calculation_engine();
    }

    /**
     * CXC-style 3-step physics model: select formula (method), substitute (method), compute (accuracy).
     * Method:accuracy ratio 2:1 per spec.
     */
    private function physics_model(): array {
        return [
            'schema_version' => '1.0',
            'engine' => 'step_calculation',
            'engine_version' => '1.0',
            'grading' => ['mode' => 'field_sum'],
            'steps' => [
                ['id' => 'step1', 'label' => 'Select formula', 'type' => 'method', 'marks' => 2],
                ['id' => 'step2', 'label' => 'Substitute values', 'type' => 'method', 'marks' => 2],
                ['id' => 'step3', 'label' => 'Calculate result', 'type' => 'computational', 'marks' => 1],
            ],
            'fields' => [
                ['id' => 'f1', 'step_id' => 'step1', 'type' => 'choice', 'expected' => 'KE = ½mv²', 'marks' => 2],
                ['id' => 'f2', 'step_id' => 'step2', 'type' => 'choice', 'expected' => 'KE = ½ × 2 × 9² = 81 J', 'marks' => 2],
                ['id' => 'f3', 'step_id' => 'step3', 'type' => 'numberbox', 'expected' => '81', 'marks' => 1, 'tolerance' => 0],
            ],
        ];
    }

    public function test_all_correct_full_marks(): void {
        $engine = $this->make_engine();
        $result = $engine->grade(
            ['responses' => [
                'f1' => 'KE = ½mv²',
                'f2' => 'KE = ½ × 2 × 9² = 81 J',
                'f3' => '81',
            ]],
            $this->physics_model()
        );

        $this->assertSame(5.0, $result['score']);
        $this->assertSame(5.0, $result['max_score']);
        $this->assertSame(1.0, $result['fraction']);
    }

    public function test_correct_method_wrong_answer_earns_method_marks(): void {
        // Student selects correct formula and substitutes correctly but computes wrong.
        // Should earn 4/5 marks — this is the CXC partial credit model.
        $engine = $this->make_engine();
        $result = $engine->grade(
            ['responses' => [
                'f1' => 'KE = ½mv²',
                'f2' => 'KE = ½ × 2 × 9² = 81 J',
                'f3' => '80',         // wrong final answer
            ]],
            $this->physics_model()
        );

        $this->assertSame(4.0, $result['score']);
        $this->assertSame(5.0, $result['max_score']);
        $this->assertEqualsWithDelta(0.8, $result['fraction'], 0.001);
        $this->assertTrue($result['field_results']['f1']['is_correct']);
        $this->assertTrue($result['field_results']['f2']['is_correct']);
        $this->assertFalse($result['field_results']['f3']['is_correct']);
    }

    public function test_wrong_formula_loses_all_method_marks(): void {
        $engine = $this->make_engine();
        $result = $engine->grade(
            ['responses' => [
                'f1' => 'KE = mv²',   // wrong — missing ½
                'f2' => 'KE = 2 × 81 = 162 J',
                'f3' => '162',
            ]],
            $this->physics_model()
        );

        $this->assertSame(0.0, $result['score']);
        $this->assertSame('conceptual_error', $result['field_results']['f1']['error_type']);
        $this->assertSame('SC_FORMULA_SELECTION_ERROR', $result['field_results']['f1']['diagnostic_code']);
    }

    public function test_empty_choice_field_is_procedural_error(): void {
        $engine = $this->make_engine();
        $result = $engine->grade(
            ['responses' => ['f1' => '', 'f2' => '', 'f3' => '']],
            $this->physics_model()
        );

        $this->assertSame(0.0, $result['score']);
        $this->assertSame('procedural_error', $result['field_results']['f1']['error_type']);
        $this->assertSame('SC_MISSING_ENTRY', $result['field_results']['f1']['diagnostic_code']);
    }

    public function test_step_results_method_step_aggregate(): void {
        $engine = $this->make_engine();
        $result = $engine->grade(
            ['responses' => ['f1' => 'KE = ½mv²', 'f2' => 'KE = ½ × 2 × 9² = 81 J', 'f3' => '81']],
            $this->physics_model()
        );

        $this->assertSame(2.0, $result['step_results']['step1']['awarded_marks']);
        $this->assertSame(2.0, $result['step_results']['step1']['max_marks']);
        $this->assertTrue($result['step_results']['step1']['is_correct']);
    }

    public function test_consumer_arithmetic_three_steps(): void {
        // Typical consumer arithmetic: find percentage, apply, round.
        $engine = $this->make_engine();
        $model = [
            'schema_version' => '1.0',
            'engine' => 'step_calculation',
            'engine_version' => '1.0',
            'grading' => ['mode' => 'field_sum'],
            'steps' => [
                ['id' => 'step1', 'label' => 'Write rate as fraction', 'type' => 'method', 'marks' => 1],
                ['id' => 'step2', 'label' => 'Multiply by principal', 'type' => 'method', 'marks' => 1],
                ['id' => 'step3', 'label' => 'State final answer', 'type' => 'computational', 'marks' => 1],
            ],
            'fields' => [
                ['id' => 'f1', 'step_id' => 'step1', 'type' => 'choice', 'expected' => '15/100', 'marks' => 1],
                ['id' => 'f2', 'step_id' => 'step2', 'type' => 'choice', 'expected' => '15/100 × 240 = 36', 'marks' => 1],
                ['id' => 'f3', 'step_id' => 'step3', 'type' => 'numberbox', 'expected' => '36', 'marks' => 1, 'tolerance' => 0],
            ],
        ];

        $result = $engine->grade(
            ['responses' => ['f1' => '15/100', 'f2' => '15/100 × 240 = 36', 'f3' => '36']],
            $model
        );
        $this->assertSame(1.0, $result['fraction']);
    }

    public function test_metadata_includes_choice_field_type(): void {
        $engine = $this->make_engine();
        $meta = $engine->get_metadata();
        $this->assertContains('choice', $meta['supported_field_types']);
        $this->assertSame('step_calculation', $meta['engine_name']);
    }
}
