<?php
// This file is part of Moodle - http://moodle.org/

/**
 * Tests for qtype_structuredsteps ledger_poa_engine (TableFillEngine).
 *
 * Covers ledger entry and table-fill question patterns from CXC Accounts (POA).
 *
 * @package   qtype_structuredsteps
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Ledger POA engine grading tests.
 */
class qtype_structuredsteps_ledger_poa_engine_test extends basic_testcase {

    private function make_engine(): \qtype_structuredsteps\local\engine\ledger_poa_engine {
        return new \qtype_structuredsteps\local\engine\ledger_poa_engine();
    }

    /**
     * CXC Accounts ledger entry: identify account, debit side, credit side, amount.
     * Accuracy-only marks (1:0 method:accuracy per spec).
     */
    private function ledger_model(): array {
        return [
            'schema_version' => '1.0',
            'engine' => 'ledger_poa',
            'engine_version' => '1.0',
            'grading' => ['mode' => 'field_sum'],
            'steps' => [
                ['id' => 'row1', 'label' => 'Journal entry — Account 1', 'type' => 'computational', 'marks' => 2],
                ['id' => 'row2', 'label' => 'Journal entry — Account 2', 'type' => 'computational', 'marks' => 2],
            ],
            'fields' => [
                ['id' => 'f1', 'step_id' => 'row1', 'type' => 'choice', 'expected' => 'Cash', 'marks' => 1],
                ['id' => 'f2', 'step_id' => 'row1', 'type' => 'numberbox', 'expected' => '500', 'marks' => 1, 'tolerance' => 0],
                ['id' => 'f3', 'step_id' => 'row2', 'type' => 'choice', 'expected' => 'Sales Revenue', 'marks' => 1],
                ['id' => 'f4', 'step_id' => 'row2', 'type' => 'numberbox', 'expected' => '500', 'marks' => 1, 'tolerance' => 0],
            ],
        ];
    }

    public function test_all_correct_full_marks(): void {
        $engine = $this->make_engine();
        $result = $engine->grade(
            ['responses' => ['f1' => 'Cash', 'f2' => '500', 'f3' => 'Sales Revenue', 'f4' => '500']],
            $this->ledger_model()
        );

        $this->assertSame(4.0, $result['score']);
        $this->assertSame(4.0, $result['max_score']);
        $this->assertSame(1.0, $result['fraction']);
    }

    public function test_partial_credit_account_name_wrong(): void {
        // Student gets the amount right but wrong account name — loses 1 mark per row.
        $engine = $this->make_engine();
        $result = $engine->grade(
            ['responses' => ['f1' => 'Bank', 'f2' => '500', 'f3' => 'Revenue', 'f4' => '500']],
            $this->ledger_model()
        );

        $this->assertSame(2.0, $result['score']);
        $this->assertSame(4.0, $result['max_score']);
        $this->assertSame(0.5, $result['fraction']);
        $this->assertFalse($result['field_results']['f1']['is_correct']);
        $this->assertTrue($result['field_results']['f2']['is_correct']);
    }

    public function test_zero_all_wrong(): void {
        $engine = $this->make_engine();
        $result = $engine->grade(
            ['responses' => ['f1' => 'Wrong', 'f2' => '0', 'f3' => 'Wrong', 'f4' => '0']],
            $this->ledger_model()
        );

        $this->assertSame(0.0, $result['score']);
        $this->assertSame(0.0, $result['fraction']);
    }

    public function test_empty_responses_all_procedural_errors(): void {
        $engine = $this->make_engine();
        $result = $engine->grade(['responses' => []], $this->ledger_model());

        $this->assertSame(0.0, $result['score']);
        foreach (['f1', 'f2', 'f3', 'f4'] as $fid) {
            $this->assertFalse($result['field_results'][$fid]['is_correct']);
        }
    }

    public function test_step_row_results_aggregate(): void {
        $engine = $this->make_engine();
        $result = $engine->grade(
            ['responses' => ['f1' => 'Cash', 'f2' => '500', 'f3' => 'Sales Revenue', 'f4' => '500']],
            $this->ledger_model()
        );

        $this->assertSame(2.0, $result['step_results']['row1']['awarded_marks']);
        $this->assertSame(2.0, $result['step_results']['row2']['awarded_marks']);
        $this->assertTrue($result['step_results']['row1']['is_correct']);
    }

    public function test_metadata_declares_engine_name(): void {
        $engine = $this->make_engine();
        $meta = $engine->get_metadata();
        $this->assertSame('ledger_poa', $meta['engine_name']);
    }

    public function test_validate_response_accepts_structured_responses(): void {
        $engine = $this->make_engine();
        $result = $engine->validate_response(['responses' => ['f1' => 'Cash']], []);
        $this->assertTrue($result['is_valid']);
    }
}
