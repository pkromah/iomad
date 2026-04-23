<?php
// This file is part of Moodle - http://moodle.org/

/**
 * Tests for qtype_structuredsteps model template factory.
 *
 * @package   qtype_structuredsteps
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Model template factory tests.
 */
class qtype_structuredsteps_model_template_factory_test extends basic_testcase {
    public function test_starter_templates_are_valid_for_all_available_engines(): void {
        $validator = new \qtype_structuredsteps\local\model_validator();

        foreach (\qtype_structuredsteps\local\engine_registry::get_available_engines() as $engine) {
            $json = \qtype_structuredsteps\local\model_template_factory::starter_json($engine);
            $this->assertNotSame('', trim($json), 'Starter JSON must not be empty.');

            $error = $validator->validate($json, $engine);
            $this->assertNull($error, "Starter model should validate for engine {$engine}. Error: {$error}");
        }
    }

    public function test_unknown_engine_falls_back_to_long_multiplication_template(): void {
        $json = \qtype_structuredsteps\local\model_template_factory::starter_json('not_a_real_engine');
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        $this->assertIsArray($decoded);
        $this->assertSame('long_multiplication', $decoded['engine']);
    }
}
