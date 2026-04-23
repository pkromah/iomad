<?php
// This file is part of Moodle - http://moodle.org/

/**
 * Tests for qtype_structuredsteps ddwtos_generator.
 *
 * @package   qtype_structuredsteps
 */

defined('MOODLE_INTERNAL') || die();

/**
 * DDWTOS generator tests.
 */
class qtype_structuredsteps_ddwtos_generator_test extends basic_testcase {

    private function make_generator(): \qtype_structuredsteps\local\converter\ddwtos_generator {
        return new \qtype_structuredsteps\local\converter\ddwtos_generator();
    }

    private function three_step_parsed(bool $has_images = false): array {
        return [
            'name' => 'Test: (4.71×8.3)−2.5² to 1dp',
            'raw_text' => '<p>Step 1: Multiply [[1]]</p><p>Step 2: Square [[2]]</p><p>Step 3: Subtract [[3]]</p>',
            'has_image_refs' => $has_images,
            'steps' => [
                ['id' => 'step1', 'label' => 'Step 1: Multiply 4.71 by 8.3:', 'slot_indices' => [0], 'type' => 'computational'],
                ['id' => 'step2', 'label' => 'Step 2: Find the square of 2.5:', 'slot_indices' => [1], 'type' => 'computational'],
                ['id' => 'step3', 'label' => 'Step 3: Subtract and round:', 'slot_indices' => [2], 'type' => 'computational'],
            ],
            'slot_answers' => [
                ['slot_index' => 0, 'group_number' => 1, 'correct' => '$$4.71\\times8.3=39.093$$', 'distractors' => ['$$4.71\\times8.3=39.091$$', '$$4.71\\times8.3=39.092$$']],
                ['slot_index' => 1, 'group_number' => 1, 'correct' => '$$2.5\\times2.5=6.25$$', 'distractors' => ['$$2.5\\times2.5=6.24$$', '$$2.5\\times2.5=6.20$$']],
                ['slot_index' => 2, 'group_number' => 1, 'correct' => '$$39.093-6.25=32.8$$', 'distractors' => ['$$39.091-6.25=32.8$$', '$$39.092-6.25=32.9$$']],
            ],
        ];
    }

    public function test_generates_valid_json_for_step_calculation(): void {
        $gen = $this->make_generator();
        $result = $gen->generate($this->three_step_parsed(), 'step_calculation');

        $this->assertNull($result['validation_error'], 'Expected valid JSON but got: ' . $result['validation_error']);
        $this->assertSame(3, $result['step_count']);
        $this->assertSame(3, $result['field_count']);

        $model = json_decode($result['model_json'], true);
        $this->assertSame('step_calculation', $model['engine']);
        $this->assertSame('1.0', $model['schema_version']);
        $this->assertSame('field_sum', $model['grading']['mode']);
    }

    public function test_step_ids_match_parsed_input(): void {
        $gen = $this->make_generator();
        $result = $gen->generate($this->three_step_parsed(), 'step_calculation');

        $model = json_decode($result['model_json'], true);
        $step_ids = array_column($model['steps'], 'id');
        $this->assertSame(['step1', 'step2', 'step3'], $step_ids);
    }

    public function test_field_step_id_links_to_step(): void {
        $gen = $this->make_generator();
        $result = $gen->generate($this->three_step_parsed(), 'step_calculation');

        $model = json_decode($result['model_json'], true);
        $step_ids = array_column($model['steps'], 'id');

        foreach ($model['fields'] as $field) {
            $this->assertContains($field['step_id'], $step_ids,
                "Field '{$field['id']}' references unknown step_id '{$field['step_id']}'");
        }
    }

    public function test_choice_field_has_correct_expected_value(): void {
        $gen = $this->make_generator();
        $result = $gen->generate($this->three_step_parsed(), 'step_calculation');

        $model = json_decode($result['model_json'], true);
        $f1 = $model['fields'][0];

        $this->assertSame('choice', $f1['type']);
        $this->assertStringContainsString('39.093', $f1['expected']);
        $this->assertNotEmpty($f1['options']);
        $this->assertContains($f1['expected'], $f1['options']);
    }

    public function test_last_step_is_computational_type(): void {
        // For step_calculation engine, last step should be 'computational' (accuracy mark).
        $gen = $this->make_generator();
        $result = $gen->generate($this->three_step_parsed(), 'step_calculation');

        $model = json_decode($result['model_json'], true);
        $last_step = end($model['steps']);
        $this->assertSame('computational', $last_step['type']);
    }

    public function test_preceding_steps_are_method_type_for_step_calculation(): void {
        $gen = $this->make_generator();
        $result = $gen->generate($this->three_step_parsed(), 'step_calculation');

        $model = json_decode($result['model_json'], true);
        $this->assertSame('method', $model['steps'][0]['type']);
        $this->assertSame('method', $model['steps'][1]['type']);
    }

    public function test_image_ref_adds_asset_note_to_fields(): void {
        $gen = $this->make_generator();
        $result = $gen->generate($this->three_step_parsed(true), 'step_calculation');

        $model = json_decode($result['model_json'], true);
        foreach ($model['fields'] as $field) {
            $this->assertArrayHasKey('asset_note', $field);
            $this->assertStringContainsString('image', $field['asset_note']);
        }
    }

    public function test_long_multiplication_engine_produces_valid_json(): void {
        $gen = $this->make_generator();
        $parsed = [
            'name' => 'Long mult: 53 × 26',
            'raw_text' => '<p>Step 1: Multiply [[1]] Step 2: Carry [[2]] Step 3: Total [[3]]</p>',
            'has_image_refs' => false,
            'steps' => [
                ['id' => 'step1', 'label' => 'Step 1: Units column', 'slot_indices' => [0], 'type' => 'computational'],
                ['id' => 'step2', 'label' => 'Step 2: Tens column', 'slot_indices' => [1], 'type' => 'computational'],
                ['id' => 'step3', 'label' => 'Step 3: Add partials', 'slot_indices' => [2], 'type' => 'computational'],
            ],
            'slot_answers' => [
                ['slot_index' => 0, 'group_number' => 1, 'correct' => '318', 'distractors' => ['310', '300']],
                ['slot_index' => 1, 'group_number' => 1, 'correct' => '1060', 'distractors' => ['1000', '1100']],
                ['slot_index' => 2, 'group_number' => 1, 'correct' => '1378', 'distractors' => ['1368', '1388']],
            ],
        ];

        $result = $gen->generate($parsed, 'long_multiplication');

        $this->assertNull($result['validation_error']);
        $model = json_decode($result['model_json'], true);
        $this->assertSame('long_multiplication', $model['engine']);
        // Numeric-only expected values should use numberbox field type
        $this->assertSame('numberbox', $model['fields'][0]['type']);
    }

    public function test_phantom_slot_skipped(): void {
        // Multi-part DDWTOS questions place an empty dragbox at position 0
        // before the first step label. The generator must skip slots where
        // expected="" to avoid invalid fields in the model.
        $gen = $this->make_generator();
        $parsed = [
            'name' => 'Multi-part: (2¾−1¼)/(1½×1½)',
            'raw_text' => '<p>[[1]] Step 1: Numerator [[2]] Step 2: Denominator [[3]] Step 3: Divide [[4]]</p>',
            'has_image_refs' => false,
            'steps' => [
                ['id' => 'step0', 'label' => '', 'slot_indices' => [0], 'type' => 'computational'],
                ['id' => 'step1', 'label' => 'Step 1: Calculate numerator', 'slot_indices' => [1], 'type' => 'computational'],
                ['id' => 'step2', 'label' => 'Step 2: Calculate denominator', 'slot_indices' => [2], 'type' => 'computational'],
                ['id' => 'step3', 'label' => 'Step 3: Divide', 'slot_indices' => [3], 'type' => 'computational'],
            ],
            'slot_answers' => [
                ['slot_index' => 0, 'group_number' => 1, 'correct' => '', 'distractors' => []],
                ['slot_index' => 1, 'group_number' => 1, 'correct' => '1½', 'distractors' => ['1¼', '1⅓']],
                ['slot_index' => 2, 'group_number' => 1, 'correct' => '2¼', 'distractors' => ['2', '2½']],
                ['slot_index' => 3, 'group_number' => 1, 'correct' => '⅔', 'distractors' => ['½', '¾']],
            ],
        ];

        $result = $gen->generate($parsed, 'long_division');

        $this->assertNull($result['validation_error'], 'Expected valid JSON but got: ' . $result['validation_error']);
        $this->assertSame(3, $result['field_count'], 'Phantom slot (expected="") must be excluded from field count');

        $model = json_decode($result['model_json'], true);
        foreach ($model['fields'] as $field) {
            $this->assertNotSame('', $field['expected'], 'No field may have an empty expected value');
        }
    }

    public function test_generated_json_has_source_metadata(): void {
        $gen = $this->make_generator();
        $result = $gen->generate($this->three_step_parsed(), 'step_calculation');

        $model = json_decode($result['model_json'], true);
        $this->assertSame('ddwtos_conversion', $model['metadata']['source']);
        $this->assertStringContainsString('4.71', $model['metadata']['source_name']);
    }
}
