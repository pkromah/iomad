<?php
// This file is part of Moodle - http://moodle.org/

/**
 * Tests for TraceEngine (Phase 2 — Sets and Logic Sequence Patterns).
 *
 * @package   qtype_structuredsteps
 */

defined('MOODLE_INTERNAL') || die();

use qtype_structuredsteps\local\converter\engines\TraceEngine;

/**
 * TraceEngine unit tests.
 */
class qtype_structuredsteps_trace_engine_test extends basic_testcase {

    private function engine(): TraceEngine {
        return new TraceEngine();
    }

    // ------------------------------------------------------------------
    // getEngineName
    // ------------------------------------------------------------------

    public function test_engine_name(): void {
        $this->assertSame('trace', $this->engine()->getEngineName());
    }

    // ------------------------------------------------------------------
    // canHandle
    // ------------------------------------------------------------------

    public function test_can_handle_sets_topic(): void {
        $this->assertTrue($this->engine()->canHandle(['raw_text' => 'Find A ∪ B.', 'topic' => 'sets']));
    }

    public function test_can_handle_logic_topic(): void {
        $this->assertTrue($this->engine()->canHandle(['raw_text' => 'Find the next term.', 'topic' => 'logic sequence patterns']));
    }

    public function test_can_handle_union_keyword(): void {
        $this->assertTrue($this->engine()->canHandle([
            'raw_text' => 'Given sets A and B, find their union.',
            'topic'    => '',
        ]));
    }

    public function test_cannot_handle_unrelated_question(): void {
        $this->assertFalse($this->engine()->canHandle([
            'raw_text' => 'Find the determinant of matrix A.',
            'topic'    => 'matrices',
        ]));
    }

    // ------------------------------------------------------------------
    // Image-flagged → review_queue
    // ------------------------------------------------------------------

    public function test_image_flagged_returns_review_queue(): void {
        $result = $this->engine()->convertToSteps([
            'raw_text'       => 'Find the next element in the sequence shown.',
            'topic'          => 'logic sequence patterns',
            'has_image_refs' => true,
        ]);

        $this->assertSame('review_queue', $result['status']);
        $this->assertEmpty($result['steps']);
    }

    // ------------------------------------------------------------------
    // Sets — union
    // ------------------------------------------------------------------

    public function test_set_union_steps(): void {
        $result = $this->engine()->convertToSteps([
            'raw_text' => '<p>Given A = {1,2,3} and B = {3,4,5}, find A ∪ B.</p>',
            'topic'    => 'sets',
        ]);

        $this->assertSame('auto_convert_eligible', $result['status']);
        $this->assertContains('set_op:union', $result['notes']);
        $this->assertGreaterThanOrEqual(3, count($result['steps']));
    }

    // ------------------------------------------------------------------
    // Sets — intersection
    // ------------------------------------------------------------------

    public function test_set_intersection_steps(): void {
        $result = $this->engine()->convertToSteps([
            'raw_text' => '<p>Find A ∩ B where A = {1,2,3,4} and B = {2,4,6}.</p>',
            'topic'    => 'sets',
        ]);

        $this->assertSame('auto_convert_eligible', $result['status']);
        $this->assertContains('set_op:intersection', $result['notes']);
    }

    // ------------------------------------------------------------------
    // Sets — complement
    // ------------------------------------------------------------------

    public function test_set_complement_steps(): void {
        $result = $this->engine()->convertToSteps([
            'raw_text' => "<p>Given U = {1,2,3,4,5} and A = {1,3}, find A'.</p>",
            'topic'    => 'sets',
        ]);

        $this->assertSame('auto_convert_eligible', $result['status']);
        $this->assertContains('set_op:complement', $result['notes']);
    }

    // ------------------------------------------------------------------
    // Sets — Venn diagram
    // ------------------------------------------------------------------

    public function test_venn_diagram_steps(): void {
        $result = $this->engine()->convertToSteps([
            'raw_text' => '<p>Using a Venn diagram, illustrate the given sets.</p>',
            'topic'    => 'sets',
        ]);

        $this->assertSame('auto_convert_eligible', $result['status']);
        $this->assertContains('set_op:venn', $result['notes']);
    }

    // ------------------------------------------------------------------
    // Sequences — arithmetic
    // ------------------------------------------------------------------

    public function test_arithmetic_sequence_steps(): void {
        $result = $this->engine()->convertToSteps([
            'raw_text' => '<p>The arithmetic sequence 3, 7, 11, ... Find the common difference and the 10th term.</p>',
            'topic'    => 'logic sequence patterns',
        ]);

        $this->assertSame('auto_convert_eligible', $result['status']);
        $this->assertContains('sequence_type:arithmetic', $result['notes']);
        $this->assertGreaterThanOrEqual(3, count($result['steps']));
    }

    // ------------------------------------------------------------------
    // Sequences — geometric
    // ------------------------------------------------------------------

    public function test_geometric_sequence_steps(): void {
        $result = $this->engine()->convertToSteps([
            'raw_text' => '<p>Find the common ratio and the next three terms of the geometric sequence 2, 6, 18, ...</p>',
            'topic'    => 'logic sequence patterns',
        ]);

        $this->assertSame('auto_convert_eligible', $result['status']);
        $this->assertContains('sequence_type:geometric', $result['notes']);
    }

    // ------------------------------------------------------------------
    // Sequences — generic pattern
    // ------------------------------------------------------------------

    public function test_generic_pattern_steps(): void {
        $result = $this->engine()->convertToSteps([
            'raw_text' => '<p>Find the next term in the sequence: 1, 4, 9, 16, ...</p>',
            'topic'    => 'logic sequence patterns',
        ]);

        $this->assertSame('auto_convert_eligible', $result['status']);
        $this->assertContains('sequence_type:pattern', $result['notes']);
    }

    // ------------------------------------------------------------------
    // Step structure consistency
    // ------------------------------------------------------------------

    public function test_steps_have_label_and_content_keys(): void {
        $result = $this->engine()->convertToSteps([
            'raw_text' => '<p>Find A ∪ B.</p>',
            'topic'    => 'sets',
        ]);

        foreach ($result['steps'] as $step) {
            $this->assertArrayHasKey('label', $step);
            $this->assertArrayHasKey('content', $step);
            $this->assertNotEmpty($step['content']);
        }
    }
}
