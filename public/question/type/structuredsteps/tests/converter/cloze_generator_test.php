<?php
// This file is part of Moodle - http://moodle.org/

/**
 * Tests for qtype_structuredsteps cloze_generator.
 *
 * Covers TASK-PSQC-009 (AlgorithmicWorkingEngine) and TASK-PSQC-010 (StepCalculationEngine)
 * acceptance criteria:
 * - Generator produces valid structuredsteps JSON with partial_credit_enabled=false.
 * - Output passes model_validator.
 * - 3 known drill questions produce AlgorithmicWorkingEngine models (TASK-PSQC-009).
 * - 3 known revision questions produce StepCalculationEngine models (TASK-PSQC-010).
 * - SEA marking: method_marks_enabled=false, all steps computational.
 * - Tolerance carried through from CLOZE sub-part.
 * - Image flag propagates to asset_note.
 *
 * @package qtype_structuredsteps
 */

defined('MOODLE_INTERNAL') || die();

/**
 * CLOZE generator unit tests.
 */
class qtype_structuredsteps_cloze_generator_test extends basic_testcase {

    private function make_generator(): \qtype_structuredsteps\local\converter\cloze_generator {
        return new \qtype_structuredsteps\local\converter\cloze_generator();
    }

    /**
     * Build a minimal parsed array that cloze_generator accepts.
     *
     * @param string  $name
     * @param array   $sub_answers   Each: ['value'=>string, 'tolerance'=>string|null]
     * @param array   $step_labels   Step labels (one per step); steps group answers sequentially.
     * @param string  $category
     * @param bool    $has_image
     */
    private function make_parsed(
        string $name,
        array $sub_answers,
        array $step_labels = [],
        string $category = '',
        bool $has_image = false
    ): array {
        $sub_parts    = [];
        $slot_answers = [];

        foreach ($sub_answers as $i => $a) {
            $sub_parts[] = [
                'position' => $i,
                'subtype'  => 'NUMERICAL',
                'answers'  => [[
                    'correct'   => true,
                    'value'     => $a['value'],
                    'tolerance' => $a['tolerance'] ?? null,
                    'feedback'  => '',
                ]],
            ];
            $slot_answers[] = [
                'slot_index'   => $i,
                'group_number' => 1,
                'correct'      => $a['value'],
                'distractors'  => [],
            ];
        }

        // Build steps: one slot per step (each step holds one sub-part).
        $steps = [];
        $n     = count($sub_answers);
        if (empty($step_labels)) {
            // Single step containing all slots.
            $steps[] = [
                'id'           => 's1',
                'label'        => 'Step 1',
                'slot_indices' => range(0, $n - 1),
            ];
        } else {
            foreach ($step_labels as $j => $label) {
                $steps[] = [
                    'id'           => 's' . ($j + 1),
                    'label'        => $label,
                    'slot_indices' => [$j],
                ];
            }
        }

        return [
            'name'            => $name,
            'raw_text'        => '<p>' . $name . '</p>',
            'category'        => $category,
            'has_image_refs'  => $has_image,
            'image_refs'      => $has_image ? ['img.png'] : [],
            'all_numerical'   => true,
            'has_mixed_types' => false,
            'sub_parts'       => $sub_parts,
            'slot_answers'    => $slot_answers,
            'steps'           => $steps,
            'subtypes'        => ['NUMERICAL'],
            'step_count'      => $n,
        ];
    }

    // ── TASK-PSQC-009: AlgorithmicWorkingEngine — 3 drill questions ─────────────

    /**
     * Drill Q1: Time — "How many minutes in 1 hour?"
     * Single sub-part, no tolerance. AlgorithmicWorkingEngine.
     */
    public function test_algo_drill_time_single_answer(): void {
        $gen    = $this->make_generator();
        $parsed = $this->make_parsed(
            'Time 20103 Jr1',
            [['value' => '60', 'tolerance' => null]],
            [],
            'Time drill'
        );

        $result = $gen->generate($parsed, 'AlgorithmicWorkingEngine');

        $this->assertNull($result['validation_error'], 'Model must pass validator: ' . ($result['validation_error'] ?? ''));
        $this->assertSame(1, $result['step_count']);
        $this->assertSame(1, $result['field_count']);

        $model = json_decode($result['model_json'], true);
        $this->assertSame('AlgorithmicWorkingEngine', $model['engine']);
        $this->assertFalse($model['params']['partial_credit_enabled'], 'SEA: partial_credit must be false');
        $this->assertFalse($model['params']['method_marks_enabled'],   'SEA: method marks must be false');
        $this->assertSame('60', $model['fields'][0]['expected']);
        $this->assertSame('numberbox', $model['fields'][0]['type']);
        $this->assertEquals(0.0, $model['fields'][0]['tolerance']);
    }

    /**
     * Drill Q2: Money — two-step change calculation with tolerance.
     * AlgorithmicWorkingEngine. Tolerance 0.01 (cents rounding).
     */
    public function test_algo_drill_money_two_steps_with_tolerance(): void {
        $gen    = $this->make_generator();
        $parsed = $this->make_parsed(
            'Money 20201 Jr1',
            [
                ['value' => '38.50', 'tolerance' => '0.01'],
                ['value' => '11.50', 'tolerance' => '0.01'],
            ],
            ['Step 1: Total spent', 'Step 2: Change received']
        );

        $result = $gen->generate($parsed, 'AlgorithmicWorkingEngine');

        $this->assertNull($result['validation_error'], 'Validation failed: ' . ($result['validation_error'] ?? ''));
        $this->assertSame(2, $result['step_count']);
        $this->assertSame(2, $result['field_count']);

        $model = json_decode($result['model_json'], true);
        $this->assertSame('AlgorithmicWorkingEngine', $model['engine']);
        $this->assertFalse($model['grading']['partial_credit_enabled']);

        // Check tolerance carried through.
        $this->assertSame(0.01, $model['fields'][0]['tolerance']);
        $this->assertSame(0.01, $model['fields'][1]['tolerance']);

        // All steps must be computational (SEA: no method marks).
        foreach ($model['steps'] as $step) {
            $this->assertSame('computational', $step['type']);
        }
    }

    /**
     * Drill Q3: Decimal multiplication with exact answer.
     * AlgorithmicWorkingEngine. Three sub-parts (multi-step working).
     */
    public function test_algo_drill_decimal_three_steps(): void {
        $gen    = $this->make_generator();
        $parsed = $this->make_parsed(
            'Decimal 20305 Jr2',
            [
                ['value' => '4.5',  'tolerance' => '0'],
                ['value' => '3.0',  'tolerance' => '0'],
                ['value' => '13.5', 'tolerance' => '0'],
            ],
            ['Step 1: Multiply tenths', 'Step 2: Multiply ones', 'Step 3: Add partial products']
        );

        $result = $gen->generate($parsed, 'AlgorithmicWorkingEngine');

        $this->assertNull($result['validation_error'], 'Validation failed: ' . ($result['validation_error'] ?? ''));
        $this->assertSame(3, $result['step_count']);
        $this->assertSame(3, $result['field_count']);

        $model = json_decode($result['model_json'], true);
        $this->assertSame('cloze_conversion', $model['metadata']['source']);
        $this->assertTrue($model['metadata']['sea_marking']);
        // Verify step IDs are unique.
        $step_ids = array_column($model['steps'], 'id');
        $this->assertSame($step_ids, array_unique($step_ids), 'Step IDs must be unique');
    }

    // ── TASK-PSQC-010: StepCalculationEngine — 3 revision questions ──────────────

    /**
     * Revision Q1: Area — rectangle L×W = area. Two-step.
     * StepCalculationEngine.
     */
    public function test_stepcalc_revision_area_rectangle(): void {
        $gen    = $this->make_generator();
        $parsed = $this->make_parsed(
            'Area 20401 Rv1',
            [
                ['value' => '12', 'tolerance' => '0'],
                ['value' => '96', 'tolerance' => '0'],
            ],
            ['Step 1: Identify length', 'Step 2: Calculate area']
        );

        $result = $gen->generate($parsed, 'StepCalculationEngine');

        $this->assertNull($result['validation_error'], 'Validation failed: ' . ($result['validation_error'] ?? ''));
        $this->assertSame(2, $result['step_count']);
        $this->assertSame(2, $result['field_count']);

        $model = json_decode($result['model_json'], true);
        $this->assertSame('StepCalculationEngine', $model['engine']);
        $this->assertFalse($model['params']['partial_credit_enabled'], 'SEA: partial_credit must be false');
        $this->assertSame('numberbox', $model['fields'][0]['type']);
        $this->assertSame('numberbox', $model['fields'][1]['type']);
    }

    /**
     * Revision Q2: Perimeter — triangle with three sides. Three-step.
     * StepCalculationEngine.
     */
    public function test_stepcalc_revision_perimeter_triangle(): void {
        $gen    = $this->make_generator();
        $parsed = $this->make_parsed(
            'Perimeter 20402 Rv1',
            [
                ['value' => '5',  'tolerance' => '0'],
                ['value' => '12', 'tolerance' => '0'],
                ['value' => '34', 'tolerance' => '0'],
            ],
            ['Step 1: Side A', 'Step 2: Side B', 'Step 3: Total perimeter']
        );

        $result = $gen->generate($parsed, 'StepCalculationEngine');

        $this->assertNull($result['validation_error'], 'Validation failed: ' . ($result['validation_error'] ?? ''));
        $this->assertSame(3, $result['step_count']);
        $this->assertSame(3, $result['field_count']);

        $model = json_decode($result['model_json'], true);
        $this->assertSame('StepCalculationEngine', $model['engine']);
        // Verify field IDs reference valid step IDs.
        $valid_step_ids = array_column($model['steps'], 'id');
        foreach ($model['fields'] as $field) {
            $this->assertContains($field['step_id'], $valid_step_ids, "Field '{$field['id']}' references invalid step_id");
        }
    }

    /**
     * Revision Q3: Percentage — find 15% of 240. Two-step.
     * StepCalculationEngine.
     */
    public function test_stepcalc_revision_percentage(): void {
        $gen    = $this->make_generator();
        $parsed = $this->make_parsed(
            'Percent 20403 Rv2',
            [
                ['value' => '36', 'tolerance' => '0'],
                ['value' => '204', 'tolerance' => '0'],
            ],
            ['Step 1: Calculate 15% of 240', 'Step 2: Find remainder']
        );

        $result = $gen->generate($parsed, 'StepCalculationEngine');

        $this->assertNull($result['validation_error'], 'Validation failed: ' . ($result['validation_error'] ?? ''));
        $this->assertSame(2, $result['step_count']);
        $this->assertSame(2, $result['field_count']);

        $model = json_decode($result['model_json'], true);
        $this->assertFalse($model['grading']['partial_credit_enabled']);
        $this->assertSame('field_sum', $model['grading']['mode']);
        $this->assertSame('36', $model['fields'][0]['expected']);
        $this->assertSame('204', $model['fields'][1]['expected']);
    }

    // ── SEA contract tests (apply to both engines) ───────────────────────────────

    /** model_validator must accept AlgorithmicWorkingEngine. */
    public function test_validator_accepts_algorithmic_engine(): void {
        $gen    = $this->make_generator();
        $parsed = $this->make_parsed('Q', [['value' => '5', 'tolerance' => null]]);
        $result = $gen->generate($parsed, 'AlgorithmicWorkingEngine');
        $this->assertNull($result['validation_error'], 'model_validator rejected AlgorithmicWorkingEngine');
    }

    /** model_validator must accept StepCalculationEngine. */
    public function test_validator_accepts_step_calculation_engine(): void {
        $gen    = $this->make_generator();
        $parsed = $this->make_parsed('Q', [['value' => '5', 'tolerance' => null]]);
        $result = $gen->generate($parsed, 'StepCalculationEngine');
        $this->assertNull($result['validation_error'], 'model_validator rejected StepCalculationEngine');
    }

    /** Image refs propagate as asset_note on each field. */
    public function test_image_flag_adds_asset_note_to_fields(): void {
        $gen    = $this->make_generator();
        $parsed = $this->make_parsed(
            'Image Q',
            [['value' => '36', 'tolerance' => null]],
            [],
            'Area',
            true   // has_image
        );

        $result = $gen->generate($parsed, 'StepCalculationEngine');
        $this->assertNull($result['validation_error'], 'Validation failed: ' . ($result['validation_error'] ?? ''));

        $model = json_decode($result['model_json'], true);
        foreach ($model['fields'] as $field) {
            $this->assertArrayHasKey('asset_note', $field, 'Image question fields must have asset_note');
        }
    }

    /** Tolerance null in sub_part → tolerance 0.0 in model field. */
    public function test_null_tolerance_defaults_to_zero(): void {
        $gen    = $this->make_generator();
        $parsed = $this->make_parsed('Q', [['value' => '42', 'tolerance' => null]]);
        $result = $gen->generate($parsed, 'AlgorithmicWorkingEngine');
        $model  = json_decode($result['model_json'], true);
        $this->assertEquals(0.0, $model['fields'][0]['tolerance']);
    }
}
