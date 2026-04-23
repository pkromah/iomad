<?php
// This file is part of Moodle - http://moodle.org/

namespace qtype_structuredsteps\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Shared grading policy helpers for tolerance and method-mark behavior.
 */
class grading_policy {
    /** @var float */
    private const FLOAT_EPSILON = 0.000000001;

    /**
     * Resolve numeric tolerance from field > params > fallback.
     *
     * @param array $field
     * @param array $params
     * @param float $fallback
     * @return float
     */
    public static function resolve_tolerance(array $field, array $params = [], float $fallback = 0.0): float {
        if (isset($field['tolerance']) && is_numeric($field['tolerance'])) {
            return max(0.0, (float)$field['tolerance']);
        }

        if (isset($params['tolerance']) && is_numeric($params['tolerance'])) {
            return max(0.0, (float)$params['tolerance']);
        }

        return max(0.0, $fallback);
    }

    /**
     * Compare numeric values with tolerance and optional decimal-place rounding.
     *
     * @param mixed $student
     * @param mixed $expected
     * @param float $tolerance
     * @param int|null $decimalplaces
     * @return bool
     */
    public static function numbers_match($student, $expected, float $tolerance = 0.0, ?int $decimalplaces = null): bool {
        if (!is_numeric($student) || !is_numeric($expected)) {
            return false;
        }

        $studentvalue = (float)$student;
        $expectedvalue = (float)$expected;
        if ($decimalplaces !== null) {
            $decimalplaces = max(0, $decimalplaces);
            $studentvalue = round($studentvalue, $decimalplaces);
            $expectedvalue = round($expectedvalue, $decimalplaces);
        }

        $delta = abs($studentvalue - $expectedvalue);
        return $delta <= (max(0.0, $tolerance) + self::FLOAT_EPSILON);
    }

    /**
     * Determine whether two totals are balanced within tolerance.
     *
     * @param float $left
     * @param float $right
     * @param float $tolerance
     * @return bool
     */
    public static function totals_balanced(float $left, float $right, float $tolerance = 0.0): bool {
        return self::numbers_match($left, $right, $tolerance);
    }

    /**
     * Resolve method-mark configuration to a non-negative float.
     *
     * @param array $params
     * @param string $key
     * @param float $default
     * @return float
     */
    public static function resolve_method_marks(array $params, string $key, float $default = 1.0): float {
        if (isset($params[$key]) && is_numeric($params[$key])) {
            return max(0.0, (float)$params[$key]);
        }
        return max(0.0, $default);
    }
}
