<?php
// This file is part of Moodle - http://moodle.org/

/**
 * Tests for qtype_structuredsteps grading policy helpers.
 *
 * @package   qtype_structuredsteps
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Grading policy tests.
 */
class qtype_structuredsteps_grading_policy_test extends basic_testcase {
    public function test_resolve_tolerance_prefers_field_then_params_then_fallback(): void {
        $field = ['tolerance' => 0.5];
        $params = ['tolerance' => 0.2];
        $this->assertSame(0.5, \qtype_structuredsteps\local\grading_policy::resolve_tolerance($field, $params, 0.1));

        $field = [];
        $this->assertSame(0.2, \qtype_structuredsteps\local\grading_policy::resolve_tolerance($field, $params, 0.1));
        $this->assertSame(0.1, \qtype_structuredsteps\local\grading_policy::resolve_tolerance([], [], 0.1));
    }

    public function test_numbers_match_respects_tolerance_with_float_epsilon(): void {
        $this->assertTrue(\qtype_structuredsteps\local\grading_policy::numbers_match('2.1', '2.0', 0.1));
        $this->assertFalse(\qtype_structuredsteps\local\grading_policy::numbers_match('2.11', '2.0', 0.1));
    }

    public function test_numbers_match_applies_decimal_rounding_when_requested(): void {
        $this->assertTrue(\qtype_structuredsteps\local\grading_policy::numbers_match('500.004', '500', 0.0, 2));
        $this->assertFalse(\qtype_structuredsteps\local\grading_policy::numbers_match('500.015', '500', 0.0, 2));
    }

    public function test_totals_balanced_uses_shared_numeric_policy(): void {
        $this->assertTrue(\qtype_structuredsteps\local\grading_policy::totals_balanced(100.0000000005, 100.0, 0.0));
        $this->assertFalse(\qtype_structuredsteps\local\grading_policy::totals_balanced(100.2, 100.0, 0.1));
    }

    public function test_resolve_method_marks_never_returns_negative(): void {
        $params = ['trial_balance_method_marks' => -3];
        $this->assertSame(0.0, \qtype_structuredsteps\local\grading_policy::resolve_method_marks($params, 'trial_balance_method_marks', 1.0));

        $params = ['trial_balance_method_marks' => 2.5];
        $this->assertSame(2.5, \qtype_structuredsteps\local\grading_policy::resolve_method_marks($params, 'trial_balance_method_marks', 1.0));
    }
}
