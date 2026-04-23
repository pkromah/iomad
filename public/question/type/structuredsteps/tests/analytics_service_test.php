<?php
// This file is part of Moodle - http://moodle.org/

/**
 * Tests for analytics_service.
 *
 * @package   qtype_structuredsteps
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Analytics service tests.
 */
class qtype_structuredsteps_analytics_service_test extends advanced_testcase {
    public function test_build_fact_record_sets_dominant_error(): void {
        $service = new \qtype_structuredsteps\local\analytics_service();

        $record = $service->build_fact_record([
            'questionid' => 10,
            'attemptid' => 20,
            'userid' => 30,
            'engine' => 'step_calculation',
            'tenantid' => 99,
            'total_score' => 1,
            'max_score' => 2,
            'step_results' => [
                'step1' => ['is_correct' => false, 'awarded_marks' => 0, 'max_marks' => 1],
            ],
            'field_results' => [
                'f1' => ['error_type' => 'structural_error'],
                'f2' => ['error_type' => 'structural_error'],
                'f3' => ['error_type' => 'computational_error'],
            ],
            'timecreated' => 1700000000,
        ]);

        $this->assertSame('structural_error', $record['dominant_error']);

        $summary = json_decode((string)$record['step_error_summary'], true);
        $this->assertSame(2, $summary['error_type_counts']['structural_error']);
        $this->assertArrayHasKey('step1', $summary['step_results']);
    }

    public function test_insert_and_csv_export_are_tenant_scoped(): void {
        $this->resetAfterTest(true);

        $service = new \qtype_structuredsteps\local\analytics_service();

        $service->insert_fact_record([
            'questionid' => 101,
            'attemptid' => 1001,
            'userid' => 501,
            'engine' => 'long_multiplication',
            'tenantid' => 1,
            'total_score' => 1,
            'max_score' => 2,
            'field_results' => ['a' => ['error_type' => 'mismatch']],
            'timecreated' => 1700000001,
        ]);

        $service->insert_fact_record([
            'questionid' => 102,
            'attemptid' => 1002,
            'userid' => 502,
            'engine' => 'step_calculation',
            'tenantid' => 2,
            'total_score' => 2,
            'max_score' => 2,
            'field_results' => ['b' => ['error_type' => 'none']],
            'timecreated' => 1700000002,
        ]);

        $tenant1rows = $service->export_rows_csv(1);
        $tenant2rows = $service->export_rows_csv(2);

        $this->assertCount(1, $tenant1rows);
        $this->assertCount(1, $tenant2rows);
        $this->assertSame(101, $tenant1rows[0]['question_id']);
        $this->assertSame(102, $tenant2rows[0]['question_id']);
    }

    public function test_json_export_anonymised_removes_ids_and_hides_answers(): void {
        $this->resetAfterTest(true);

        $service = new \qtype_structuredsteps\local\analytics_service();
        $service->insert_fact_record([
            'questionid' => 201,
            'attemptid' => 2001,
            'userid' => 601,
            'engine' => 'step_calculation',
            'tenantid' => 3,
            'total_score' => 3,
            'max_score' => 4,
            'step_results' => [
                's1' => ['is_correct' => true, 'awarded_marks' => 1, 'max_marks' => 1],
            ],
            'field_results' => [
                'f1' => ['error_type' => 'mismatch'],
            ],
            'timecreated' => 1700000003,
        ]);

        $rows = $service->export_rows_json(3, [], true);

        $this->assertCount(1, $rows);
        $row = $rows[0];
        $this->assertArrayNotHasKey('userid', $row);
        $this->assertArrayNotHasKey('attemptid', $row);
        $this->assertArrayNotHasKey('model_json', $row);
        $this->assertArrayNotHasKey('answer', $row);
        $this->assertSame('step_calculation', $row['engine']);
        $this->assertNotEmpty($row['steps']);
    }
}
