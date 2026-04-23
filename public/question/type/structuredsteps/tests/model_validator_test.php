<?php
// This file is part of Moodle - http://moodle.org/

/**
 * Tests for qtype_structuredsteps model validator.
 *
 * @package   qtype_structuredsteps
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Model validator tests.
 */
class qtype_structuredsteps_model_validator_test extends basic_testcase {
    public function test_valid_model_returns_null(): void {
        $validator = new \qtype_structuredsteps\local\model_validator();
        $error = $validator->validate($this->valid_model_json(), 'long_multiplication');
        $this->assertNull($error);
    }

    public function test_missing_key_returns_error(): void {
        $validator = new \qtype_structuredsteps\local\model_validator();
        $json = json_encode([
            'schema_version' => '1.0',
            'engine' => 'long_multiplication',
            'engine_version' => '1.0',
            'steps' => [],
            'fields' => [],
        ]);

        $error = $validator->validate($json);
        $this->assertSame('Missing required key: grading', $error);
    }

    public function test_unsupported_engine_returns_error(): void {
        $validator = new \qtype_structuredsteps\local\model_validator();
        $json = json_encode([
            'schema_version' => '1.0',
            'engine' => 'not_supported',
            'engine_version' => '1.0',
            'grading' => ['mode' => 'field_sum'],
            'steps' => [],
            'fields' => [],
        ]);

        $error = $validator->validate($json);
        $this->assertSame('Unsupported engine: not_supported', $error);
    }

    public function test_step_calculation_engine_is_available(): void {
        $validator = new \qtype_structuredsteps\local\model_validator();
        $model = $this->valid_model_array();
        $model['engine'] = 'step_calculation';

        $error = $validator->validate(json_encode($model), 'step_calculation');
        $this->assertNull($error);
    }

    public function test_ledger_poa_engine_is_available(): void {
        $validator = new \qtype_structuredsteps\local\model_validator();
        $model = $this->valid_model_array();
        $model['engine'] = 'ledger_poa';

        $error = $validator->validate(json_encode($model), 'ledger_poa');
        $this->assertNull($error);
    }

    public function test_long_division_engine_is_available(): void {
        $validator = new \qtype_structuredsteps\local\model_validator();
        $model = $this->valid_model_array();
        $model['engine'] = 'long_division';

        $error = $validator->validate(json_encode($model), 'long_division');
        $this->assertNull($error);
    }

    public function test_stoichiometry_engine_is_available(): void {
        $validator = new \qtype_structuredsteps\local\model_validator();
        $model = $this->valid_model_array();
        $model['engine'] = 'stoichiometry';

        $error = $validator->validate(json_encode($model), 'stoichiometry');
        $this->assertNull($error);
    }

    public function test_evidence_table_engine_is_available(): void {
        $validator = new \qtype_structuredsteps\local\model_validator();
        $model = $this->valid_model_array();
        $model['engine'] = 'evidence_table';

        $error = $validator->validate(json_encode($model), 'evidence_table');
        $this->assertNull($error);
    }

    public function test_graphing_engine_is_available(): void {
        $validator = new \qtype_structuredsteps\local\model_validator();
        $model = $this->valid_model_array();
        $model['engine'] = 'graphing';

        $error = $validator->validate(json_encode($model), 'graphing');
        $this->assertNull($error);
    }

    public function test_engine_mismatch_returns_error(): void {
        $validator = new \qtype_structuredsteps\local\model_validator();
        $error = $validator->validate($this->valid_model_json(), 'graphing');
        $this->assertSame("Model engine 'long_multiplication' does not match selected engine 'graphing'", $error);
    }

    public function test_empty_json_returns_error(): void {
        $validator = new \qtype_structuredsteps\local\model_validator();
        $this->assertSame('JSON cannot be empty', $validator->validate('   '));
    }

    public function test_duplicate_step_id_returns_error(): void {
        $validator = new \qtype_structuredsteps\local\model_validator();
        $model = $this->valid_model_array();
        $model['steps'][] = ['id' => 'step1', 'label' => 'Duplicate', 'type' => 'computational', 'marks' => 1];

        $this->assertSame('Duplicate step id: step1', $validator->validate(json_encode($model)));
    }

    public function test_duplicate_field_id_returns_error(): void {
        $validator = new \qtype_structuredsteps\local\model_validator();
        $model = $this->valid_model_array();
        $model['fields'][] = ['id' => 'f1', 'step_id' => 'step1', 'type' => 'numberbox', 'expected' => '99', 'marks' => 1, 'tolerance' => 0];

        $this->assertSame('Duplicate field id: f1', $validator->validate(json_encode($model)));
    }

    public function test_field_referencing_unknown_step_returns_error(): void {
        $validator = new \qtype_structuredsteps\local\model_validator();
        $model = $this->valid_model_array();
        $model['fields'][0]['step_id'] = 'step_missing';

        $this->assertSame("Field 'f1' references unknown step_id 'step_missing'", $validator->validate(json_encode($model)));
    }

    public function test_negative_field_marks_returns_error(): void {
        $validator = new \qtype_structuredsteps\local\model_validator();
        $model = $this->valid_model_array();
        $model['fields'][0]['marks'] = -1;

        $this->assertSame("Field 'f1' has invalid marks", $validator->validate(json_encode($model)));
    }

    public function test_non_numeric_tolerance_returns_error(): void {
        $validator = new \qtype_structuredsteps\local\model_validator();
        $model = $this->valid_model_array();
        $model['fields'][0]['tolerance'] = 'abc';

        $this->assertSame("Field 'f1' has invalid tolerance", $validator->validate(json_encode($model)));
    }

    public function test_missing_grading_mode_returns_error(): void {
        $validator = new \qtype_structuredsteps\local\model_validator();
        $model = $this->valid_model_array();
        $model['grading'] = [];

        $this->assertSame('grading.mode must be a non-empty string', $validator->validate(json_encode($model)));
    }

    private function valid_model_json(): string {
        return json_encode($this->valid_model_array());
    }

    /**
     * @return array<string,mixed>
     */
    private function valid_model_array(): array {
        return [
            'schema_version' => '1.0',
            'engine' => 'long_multiplication',
            'engine_version' => '1.0',
            'grading' => [
                'mode' => 'field_sum',
            ],
            'steps' => [
                ['id' => 'step1', 'label' => 'Step 1', 'type' => 'computational', 'marks' => 1],
            ],
            'fields' => [
                ['id' => 'f1', 'step_id' => 'step1', 'type' => 'numberbox', 'expected' => '12', 'marks' => 1, 'tolerance' => 0],
            ],
        ];
    }
}
