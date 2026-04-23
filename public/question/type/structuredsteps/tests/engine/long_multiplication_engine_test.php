<?php
// This file is part of Moodle - http://moodle.org/

/**
 * Tests for qtype_structuredsteps long_multiplication_engine.
 *
 * @package   qtype_structuredsteps
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Long multiplication engine grading tests.
 */
class qtype_structuredsteps_long_multiplication_engine_test extends basic_testcase {

    private function make_engine(): \qtype_structuredsteps\local\engine\long_multiplication_engine {
        return new \qtype_structuredsteps\local\engine\long_multiplication_engine();
    }

    private function three_step_model(): array {
        return [
            'schema_version' => '1.0',
            'engine' => 'long_multiplication',
            'engine_version' => '1.0',
            'grading' => ['mode' => 'field_sum'],
            'steps' => [
                ['id' => 'step1', 'label' => 'Step 1: Multiply units', 'type' => 'computational', 'marks' => 1],
                ['id' => 'step2', 'label' => 'Step 2: Multiply tens', 'type' => 'computational', 'marks' => 1],
                ['id' => 'step3', 'label' => 'Step 3: Add partials', 'type' => 'computational', 'marks' => 1],
            ],
            'fields' => [
                ['id' => 'f1', 'step_id' => 'step1', 'type' => 'numberbox', 'expected' => '318', 'marks' => 1, 'tolerance' => 0],
                ['id' => 'f2', 'step_id' => 'step2', 'type' => 'numberbox', 'expected' => '1060', 'marks' => 1, 'tolerance' => 0],
                ['id' => 'f3', 'step_id' => 'step3', 'type' => 'numberbox', 'expected' => '1378', 'marks' => 1, 'tolerance' => 0],
            ],
        ];
    }

    public function test_all_correct_returns_full_marks(): void {
        $engine = $this->make_engine();
        $result = $engine->grade(
            ['responses' => ['f1' => '318', 'f2' => '1060', 'f3' => '1378']],
            $this->three_step_model()
        );

        $this->assertSame(3.0, $result['score']);
        $this->assertSame(3.0, $result['max_score']);
        $this->assertSame(1.0, $result['fraction']);
        $this->assertTrue($result['field_results']['f1']['is_correct']);
        $this->assertTrue($result['field_results']['f2']['is_correct']);
        $this->assertTrue($result['field_results']['f3']['is_correct']);
    }

    public function test_partial_credit_step1_correct_only(): void {
        $engine = $this->make_engine();
        $result = $engine->grade(
            ['responses' => ['f1' => '318', 'f2' => '1000', 'f3' => '1300']],
            $this->three_step_model()
        );

        $this->assertSame(1.0, $result['score']);
        $this->assertSame(3.0, $result['max_score']);
        $this->assertEqualsWithDelta(0.333, $result['fraction'], 0.001);
        $this->assertTrue($result['field_results']['f1']['is_correct']);
        $this->assertFalse($result['field_results']['f2']['is_correct']);
        $this->assertFalse($result['field_results']['f3']['is_correct']);
    }

    public function test_partial_credit_steps1_and_2_correct(): void {
        $engine = $this->make_engine();
        $result = $engine->grade(
            ['responses' => ['f1' => '318', 'f2' => '1060', 'f3' => '1300']],
            $this->three_step_model()
        );

        $this->assertSame(2.0, $result['score']);
        $this->assertSame(3.0, $result['max_score']);
        $this->assertEqualsWithDelta(0.667, $result['fraction'], 0.001);
    }

    public function test_zero_score_all_wrong(): void {
        $engine = $this->make_engine();
        $result = $engine->grade(
            ['responses' => ['f1' => '0', 'f2' => '0', 'f3' => '0']],
            $this->three_step_model()
        );

        $this->assertSame(0.0, $result['score']);
        $this->assertSame(0.0, $result['fraction']);
    }

    public function test_empty_response_zero_score(): void {
        $engine = $this->make_engine();
        $result = $engine->grade(['responses' => []], $this->three_step_model());

        $this->assertSame(0.0, $result['score']);
        $this->assertSame('procedural_error', $result['field_results']['f1']['error_type']);
        $this->assertSame('LM_MISSING_ENTRY', $result['field_results']['f1']['diagnostic_code']);
    }

    public function test_shift_error_detected(): void {
        $engine = $this->make_engine();
        // 3180 = 318 * 10 — classic column-shift error
        $result = $engine->grade(
            ['responses' => ['f1' => '3180', 'f2' => '1060', 'f3' => '1378']],
            $this->three_step_model()
        );

        $this->assertFalse($result['field_results']['f1']['is_correct']);
        $this->assertSame('structural_error', $result['field_results']['f1']['error_type']);
        $this->assertSame('LM_SHIFT_ERROR', $result['field_results']['f1']['diagnostic_code']);
    }

    public function test_step_results_aggregate_correctly(): void {
        $engine = $this->make_engine();
        $result = $engine->grade(
            ['responses' => ['f1' => '318', 'f2' => '1060', 'f3' => '1378']],
            $this->three_step_model()
        );

        $this->assertTrue($result['step_results']['step1']['is_correct']);
        $this->assertSame(1.0, $result['step_results']['step1']['awarded_marks']);
        $this->assertSame(1.0, $result['step_results']['step3']['awarded_marks']);
    }

    public function test_long_division_three_steps(): void {
        // Engine also handles long division model (same engine class, different params)
        $engine = $this->make_engine();
        $model = [
            'schema_version' => '1.0',
            'engine' => 'long_multiplication',
            'engine_version' => '1.0',
            'grading' => ['mode' => 'field_sum'],
            'steps' => [
                ['id' => 'step1', 'label' => 'Divide', 'type' => 'computational', 'marks' => 1],
                ['id' => 'step2', 'label' => 'Multiply back', 'type' => 'computational', 'marks' => 1],
                ['id' => 'step3', 'label' => 'Remainder', 'type' => 'computational', 'marks' => 1],
            ],
            'fields' => [
                ['id' => 'f1', 'step_id' => 'step1', 'type' => 'numberbox', 'expected' => '6', 'marks' => 1, 'tolerance' => 0],
                ['id' => 'f2', 'step_id' => 'step2', 'type' => 'numberbox', 'expected' => '72', 'marks' => 1, 'tolerance' => 0],
                ['id' => 'f3', 'step_id' => 'step3', 'type' => 'numberbox', 'expected' => '4', 'marks' => 1, 'tolerance' => 0],
            ],
        ];

        $result = $engine->grade(
            ['responses' => ['f1' => '6', 'f2' => '72', 'f3' => '4']],
            $model
        );
        $this->assertSame(1.0, $result['fraction']);
    }

    public function test_metadata_declares_supported_field_types(): void {
        $engine = $this->make_engine();
        $meta = $engine->get_metadata();
        $this->assertContains('numberbox', $meta['supported_field_types']);
        $this->assertContains('digitbox', $meta['supported_field_types']);
        $this->assertSame('long_multiplication', $meta['engine_name']);
    }

    public function test_validate_response_accepts_responses_key(): void {
        $engine = $this->make_engine();
        $result = $engine->validate_response(['responses' => ['f1' => '1']], []);
        $this->assertTrue($result['is_valid']);
    }

    public function test_validate_response_rejects_empty(): void {
        $engine = $this->make_engine();
        $result = $engine->validate_response([], []);
        $this->assertFalse($result['is_valid']);
    }
}
