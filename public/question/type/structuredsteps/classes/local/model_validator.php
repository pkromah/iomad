<?php
// This file is part of Moodle - http://moodle.org/

namespace qtype_structuredsteps\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Validates structuredsteps model JSON.
 */
class model_validator {
    /**
     * @param string $modeljson
     * @return string|null Error message or null when valid.
     */
    public function validate(string $modeljson): ?string {
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

        foreach (['schema_version', 'engine', 'engine_version', 'grading'] as $required) {
            if (!array_key_exists($required, $decoded)) {
                return "Missing required key: {$required}";
            }
        }

        return null;
    }
}
