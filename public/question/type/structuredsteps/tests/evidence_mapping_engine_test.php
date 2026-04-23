<?php
// This file is part of Moodle - http://moodle.org/

/**
 * Tests for EvidenceMappingEngine (Phase 2 — Statistics).
 *
 * @package   qtype_structuredsteps
 */

defined('MOODLE_INTERNAL') || die();

use qtype_structuredsteps\local\converter\engines\EvidenceMappingEngine;

/**
 * EvidenceMappingEngine unit tests.
 *
 * Note: All current CXC Statistics source questions are image-flagged.
 * These tests use synthetic inputs to validate the engine independently.
 */
class qtype_structuredsteps_evidence_mapping_engine_test extends basic_testcase {

    private function engine(): EvidenceMappingEngine {
        return new EvidenceMappingEngine();
    }

    // ------------------------------------------------------------------
    // getEngineName
    // ------------------------------------------------------------------

    public function test_engine_name(): void {
        $this->assertSame('evidence_mapping', $this->engine()->getEngineName());
    }

    // ------------------------------------------------------------------
    // canHandle
    // ------------------------------------------------------------------

    public function test_can_handle_statistics_topic(): void {
        $this->assertTrue($this->engine()->canHandle([
            'raw_text' => 'Find the mean.',
            'topic'    => 'statistics',
        ]));
    }

    public function test_can_handle_probability_keyword(): void {
        $this->assertTrue($this->engine()->canHandle([
            'raw_text' => 'Find the probability that the event occurs.',
            'topic'    => '',
        ]));
    }

    public function test_cannot_handle_unrelated_question(): void {
        $this->assertFalse($this->engine()->canHandle([
            'raw_text' => 'Find the union of sets A and B.',
            'topic'    => 'sets',
        ]));
    }

    // ------------------------------------------------------------------
    // Image-flagged → review_queue
    // ------------------------------------------------------------------

    public function test_image_flagged_returns_review_queue(): void {
        $result = $this->engine()->convertToSteps([
            'raw_text'       => 'The table shows the scores. Find the mean.',
            'topic'          => 'statistics',
            'has_image_refs' => true,
        ]);

        $this->assertSame('review_queue', $result['status']);
        $this->assertEmpty($result['steps']);
        $this->assertSame('evidence_mapping', $result['engine']);
    }

    // ------------------------------------------------------------------
    // Mean
    // ------------------------------------------------------------------

    public function test_mean_steps(): void {
        $result = $this->engine()->convertToSteps([
            'raw_text' => '<p>Calculate the mean of the following values: 4, 7, 9, 11, 14.</p>',
            'topic'    => 'statistics',
        ]);

        $this->assertSame('auto_convert_eligible', $result['status']);
        $this->assertContains('stat_op:mean', $result['notes']);
        $this->assertGreaterThanOrEqual(4, count($result['steps']));
    }

    // ------------------------------------------------------------------
    // Median
    // ------------------------------------------------------------------

    public function test_median_steps(): void {
        $result = $this->engine()->convertToSteps([
            'raw_text' => '<p>Find the median of the data set.</p>',
            'topic'    => 'statistics',
        ]);

        $this->assertSame('auto_convert_eligible', $result['status']);
        $this->assertContains('stat_op:median', $result['notes']);
    }

    // ------------------------------------------------------------------
    // Mode
    // ------------------------------------------------------------------

    public function test_mode_steps(): void {
        $result = $this->engine()->convertToSteps([
            'raw_text' => '<p>State the mode of the distribution.</p>',
            'topic'    => 'statistics',
        ]);

        $this->assertSame('auto_convert_eligible', $result['status']);
        $this->assertContains('stat_op:mode', $result['notes']);
    }

    // ------------------------------------------------------------------
    // Probability
    // ------------------------------------------------------------------

    public function test_probability_steps(): void {
        $result = $this->engine()->convertToSteps([
            'raw_text' => '<p>A bag contains 4 red and 6 blue balls. Find P(red).</p>',
            'topic'    => 'statistics',
        ]);

        $this->assertSame('auto_convert_eligible', $result['status']);
        $this->assertContains('stat_op:probability', $result['notes']);
        $this->assertGreaterThanOrEqual(4, count($result['steps']));
    }

    // ------------------------------------------------------------------
    // Standard deviation
    // ------------------------------------------------------------------

    public function test_standard_deviation_steps(): void {
        $result = $this->engine()->convertToSteps([
            'raw_text' => '<p>Calculate the standard deviation of the data.</p>',
            'topic'    => 'statistics',
        ]);

        $this->assertSame('auto_convert_eligible', $result['status']);
        $this->assertContains('stat_op:standard_deviation', $result['notes']);
    }

    // ------------------------------------------------------------------
    // Table data prefix step
    // ------------------------------------------------------------------

    public function test_embedded_table_adds_extraction_step(): void {
        $result = $this->engine()->convertToSteps([
            'raw_text' => '<p>Use the table to find the mean.</p><table><tr><td>5</td><td>10</td></tr></table>',
            'topic'    => 'statistics',
        ]);

        $this->assertSame('auto_convert_eligible', $result['status']);
        $this->assertStringContainsString('table', strtolower($result['steps'][0]['content']));
        // Table extraction step prepended, so total steps = template steps + 1
        $this->assertGreaterThanOrEqual(5, count($result['steps']));
    }

    // ------------------------------------------------------------------
    // Cumulative frequency
    // ------------------------------------------------------------------

    public function test_cumulative_frequency_steps(): void {
        $result = $this->engine()->convertToSteps([
            'raw_text' => '<p>Draw a cumulative frequency curve and estimate the median.</p>',
            'topic'    => 'statistics',
        ]);

        $this->assertSame('auto_convert_eligible', $result['status']);
        $this->assertContains('stat_op:cumulative_frequency', $result['notes']);
    }

    // ------------------------------------------------------------------
    // Step structure consistency
    // ------------------------------------------------------------------

    public function test_steps_have_label_and_content_keys(): void {
        $result = $this->engine()->convertToSteps([
            'raw_text' => '<p>Find the mean of 2, 4, 6, 8.</p>',
            'topic'    => 'statistics',
        ]);

        foreach ($result['steps'] as $step) {
            $this->assertArrayHasKey('label', $step);
            $this->assertArrayHasKey('content', $step);
            $this->assertNotEmpty($step['content']);
        }
    }
}
