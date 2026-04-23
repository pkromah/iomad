<?php
// This file is part of Moodle - http://moodle.org/

namespace qtype_structuredsteps\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Validates structuredsteps model JSON.
 */
class model_validator {
    /**
     * Validate model JSON.
     *
     * @param string $modeljson
     * @param string|null $expectedengine
     * @return string|null Error message or null when valid.
     */
    public function validate(string $modeljson, ?string $expectedengine = null): ?string {
        if (trim($modeljson) === '') {
            return 'JSON cannot be empty';
        }

        try {
            $decoded = json_decode($modeljson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return $e->getMessage();
        }

        if (!is_array($decoded)) {
            return 'Top-level JSON must be an object';
        }

        foreach (['schema_version', 'engine', 'engine_version', 'grading', 'steps', 'fields'] as $required) {
            if (!array_key_exists($required, $decoded)) {
                return "Missing required key: {$required}";
            }
        }

        if (!is_string($decoded['schema_version']) || trim($decoded['schema_version']) === '') {
            return 'schema_version must be a non-empty string';
        }

        if (!is_string($decoded['engine']) || trim($decoded['engine']) === '') {
            return 'engine must be a non-empty string';
        }

        if (!\qtype_structuredsteps\local\engine_registry::is_supported($decoded['engine'])) {
            return "Unsupported engine: {$decoded['engine']}";
        }

        if (!\qtype_structuredsteps\local\engine_registry::is_available($decoded['engine'])) {
            return "Engine not yet implemented: {$decoded['engine']}";
        }

        if ($expectedengine !== null && $expectedengine !== '' && $decoded['engine'] !== $expectedengine) {
            return "Model engine '{$decoded['engine']}' does not match selected engine '{$expectedengine}'";
        }

        if (!is_string($decoded['engine_version']) || trim($decoded['engine_version']) === '') {
            return 'engine_version must be a non-empty string';
        }

        if (!is_array($decoded['grading'])) {
            return 'grading must be an object';
        }

        if (empty($decoded['grading']['mode']) || !is_string($decoded['grading']['mode'])) {
            return 'grading.mode must be a non-empty string';
        }

        if (!is_array($decoded['steps'])) {
            return 'steps must be an array';
        }

        $stepids = $this->validate_steps($decoded['steps']);
        if (is_string($stepids)) {
            return $stepids;
        }

        if (!is_array($decoded['fields'])) {
            return 'fields must be an array';
        }

        return $this->validate_fields($decoded['fields'], $stepids);
    }

    /**
     * @param array $steps
     * @return array<string,bool>|string
     */
    private function validate_steps(array $steps) {
        $stepids = [];

        foreach ($steps as $index => $step) {
            if (!is_array($step)) {
                return "Step at index {$index} must be an object";
            }

            if (!isset($step['id']) || !is_string($step['id']) || trim($step['id']) === '') {
                return "Step at index {$index} must include a non-empty id";
            }

            $stepid = trim($step['id']);
            if (isset($stepids[$stepid])) {
                return "Duplicate step id: {$stepid}";
            }

            if (isset($step['marks']) && (!is_numeric($step['marks']) || (float)$step['marks'] < 0)) {
                return "Step '{$stepid}' has invalid marks";
            }

            $stepids[$stepid] = true;
        }

        return $stepids;
    }

    /**
     * @param array $fields
     * @param array<string,bool> $stepids
     * @return string|null
     */
    private function validate_fields(array $fields, array $stepids): ?string {
        $fieldids = [];

        foreach ($fields as $index => $field) {
            if (!is_array($field)) {
                return "Field at index {$index} must be an object";
            }

            if (!isset($field['id']) || !is_string($field['id']) || trim($field['id']) === '') {
                return "Field at index {$index} must include a non-empty id";
            }

            $fieldid = trim($field['id']);
            if (isset($fieldids[$fieldid])) {
                return "Duplicate field id: {$fieldid}";
            }
            $fieldids[$fieldid] = true;

            if (empty($field['type']) || !is_string($field['type'])) {
                return "Field '{$fieldid}' must include a non-empty type";
            }

            if (isset($field['marks']) && (!is_numeric($field['marks']) || (float)$field['marks'] < 0)) {
                return "Field '{$fieldid}' has invalid marks";
            }

            if (isset($field['step_id']) && trim((string)$field['step_id']) !== '') {
                $stepid = trim((string)$field['step_id']);
                if (!isset($stepids[$stepid])) {
                    return "Field '{$fieldid}' references unknown step_id '{$stepid}'";
                }
            }

            if ((string)$field['type'] === 'numberbox' && isset($field['tolerance'])) {
                if (!is_numeric($field['tolerance']) || (float)$field['tolerance'] < 0) {
                    return "Field '{$fieldid}' has invalid tolerance";
                }
            }
        }

        return null;
    }
}
