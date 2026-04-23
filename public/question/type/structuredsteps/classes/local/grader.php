<?php
// This file is part of Moodle - http://moodle.org/

namespace qtype_structuredsteps\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Deterministic baseline grader for qtype_structuredsteps.
 */
class grader {
    /**
     * @param array $response
     * @param string $modeljson
     * @return float fraction 0..1
     */
    public function grade_response(array $response, string $modeljson): float {
        $answer = trim((string) ($response['answer'] ?? ''));
        if ($answer === '') {
            return 0.0;
        }

        $expected = $this->get_expected_response($modeljson);
        if ($expected === null || trim($expected) === '') {
            return 0.0;
        }

        return trim($answer) === trim($expected) ? 1.0 : 0.0;
    }

    /**
     * Extract expected response from model JSON.
     *
     * @param string $modeljson
     * @return string|null
     */
    public function get_expected_response(string $modeljson): ?string {
        try {
            $model = json_decode($modeljson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return null;
        }

        if (isset($model['grading']['expected_response']) && is_string($model['grading']['expected_response'])) {
            return $model['grading']['expected_response'];
        }

        if (isset($model['expected_response']) && is_string($model['expected_response'])) {
            return $model['expected_response'];
        }

        return null;
    }
}
