<?php
// This file is part of Moodle - http://moodle.org/

/**
 * Tests for ddwtos_classifier v2.0 — Phase 2 engine routing.
 *
 * Validates:
 * - All Phase 1 routing still works (regression guard)
 * - Phase 2 topic hints route to the correct engine name
 * - Image-flagged Phase 2 questions produce review_queue status
 * - Non-image Phase 2 questions produce auto_convert_eligible status
 *
 * @package   qtype_structuredsteps
 */

defined('MOODLE_INTERNAL') || die();

use qtype_structuredsteps\local\converter\ddwtos_classifier;

/**
 * Classifier v2.0 tests.
 */
class qtype_structuredsteps_classifier_v2_test extends basic_testcase {

    private function classifier(): ddwtos_classifier {
        return new ddwtos_classifier();
    }

    // ------------------------------------------------------------------
    // Version
    // ------------------------------------------------------------------

    public function test_classifier_version_is_2(): void {
        $this->assertSame('2.0', $this->classifier()->get_version());
    }

    // ------------------------------------------------------------------
    // Phase 1 regression — these must still work after v2 changes
    // ------------------------------------------------------------------

    public function test_phase1_long_multiplication_still_routes(): void {
        $result = $this->classifier()->classify(
            ['raw_text' => 'Calculate 53 × 26 using long multiplication.', 'step_count' => 3, 'dragboxes' => [], 'has_image_refs' => false],
            'computation'
        );
        $this->assertSame('long_multiplication', $result['engine']);
        $this->assertSame('auto_convert_eligible', $result['status']);
    }

    public function test_phase1_long_division_still_routes(): void {
        $result = $this->classifier()->classify(
            ['raw_text' => 'Divide 784 by 12 using long division.', 'step_count' => 3, 'dragboxes' => [], 'has_image_refs' => false],
            'computation'
        );
        $this->assertSame('long_division', $result['engine']);
    }

    public function test_phase1_fractions_routes_to_step_calculation(): void {
        $result = $this->classifier()->classify(
            ['raw_text' => 'Calculate 3/4 + 1/2.', 'step_count' => 2, 'dragboxes' => [], 'has_image_refs' => false],
            'fractions_decimals'
        );
        $this->assertSame('step_calculation', $result['engine']);
    }

    public function test_phase1_consumer_arithmetic_routes_to_step_calculation(): void {
        $result = $this->classifier()->classify(
            ['raw_text' => 'Calculate the simple interest on $500 at 5% for 2 years.', 'step_count' => 3, 'dragboxes' => [], 'has_image_refs' => false],
            'consumer_arithmetic'
        );
        $this->assertSame('step_calculation', $result['engine']);
    }

    public function test_phase1_measurement_routes_to_step_calculation(): void {
        $result = $this->classifier()->classify(
            ['raw_text' => 'Find the area of the rectangle.', 'step_count' => 2, 'dragboxes' => [], 'has_image_refs' => false],
            'measurement'
        );
        $this->assertSame('step_calculation', $result['engine']);
    }

    public function test_phase1_relations_routes_to_step_calculation(): void {
        $result = $this->classifier()->classify(
            ['raw_text' => 'Solve the equation 2x + 3 = 7.', 'step_count' => 3, 'dragboxes' => [], 'has_image_refs' => false],
            'relations_functions_graphs'
        );
        $this->assertSame('step_calculation', $result['engine']);
    }

    // ------------------------------------------------------------------
    // Phase 2 — TraceEngine routing
    // ------------------------------------------------------------------

    public function test_sets_topic_routes_to_trace(): void {
        $result = $this->classifier()->classify(
            ['raw_text' => 'Find A ∪ B.', 'step_count' => 3, 'dragboxes' => [], 'has_image_refs' => false],
            'sets'
        );
        $this->assertSame('trace', $result['engine']);
        $this->assertSame('auto_convert_eligible', $result['status']);
    }

    public function test_logic_sequence_topic_routes_to_trace(): void {
        $result = $this->classifier()->classify(
            ['raw_text' => 'Find the next three terms.', 'step_count' => 2, 'dragboxes' => [], 'has_image_refs' => false],
            'logic sequence patterns'
        );
        $this->assertSame('trace', $result['engine']);
    }

    public function test_sets_image_flagged_routes_to_review_queue(): void {
        $result = $this->classifier()->classify(
            ['raw_text' => 'The Venn diagram shows sets A and B.', 'step_count' => 2, 'dragboxes' => [], 'has_image_refs' => true],
            'sets'
        );
        $this->assertSame('trace', $result['engine']);
        $this->assertSame('review_queue', $result['status']);
    }

    // ------------------------------------------------------------------
    // Phase 2 — GraphEngine routing
    // ------------------------------------------------------------------

    public function test_matrices_topic_routes_to_graph(): void {
        $result = $this->classifier()->classify(
            ['raw_text' => 'Find the determinant of the matrix.', 'step_count' => 3, 'dragboxes' => [], 'has_image_refs' => false],
            'matrices'
        );
        $this->assertSame('graph', $result['engine']);
        $this->assertSame('auto_convert_eligible', $result['status']);
    }

    public function test_vectors_topic_routes_to_graph(): void {
        $result = $this->classifier()->classify(
            ['raw_text' => 'Find the magnitude of vector v.', 'step_count' => 2, 'dragboxes' => [], 'has_image_refs' => false],
            'vectors'
        );
        $this->assertSame('graph', $result['engine']);
    }

    public function test_vectors_image_flagged_routes_to_review_queue(): void {
        $result = $this->classifier()->classify(
            ['raw_text' => 'The diagram shows vectors.', 'step_count' => 2, 'dragboxes' => [], 'has_image_refs' => true],
            'vectors'
        );
        $this->assertSame('graph', $result['engine']);
        $this->assertSame('review_queue', $result['status']);
    }

    // ------------------------------------------------------------------
    // Phase 2 — EvidenceMappingEngine routing
    // ------------------------------------------------------------------

    public function test_statistics_topic_routes_to_evidence_mapping(): void {
        $result = $this->classifier()->classify(
            ['raw_text' => 'Calculate the mean of the data.', 'step_count' => 3, 'dragboxes' => [], 'has_image_refs' => false],
            'statistics'
        );
        $this->assertSame('evidence_mapping', $result['engine']);
        $this->assertSame('auto_convert_eligible', $result['status']);
    }

    public function test_statistics_image_flagged_routes_to_review_queue(): void {
        $result = $this->classifier()->classify(
            ['raw_text' => 'The histogram shows the frequency distribution.', 'step_count' => 2, 'dragboxes' => [], 'has_image_refs' => true],
            'statistics'
        );
        $this->assertSame('evidence_mapping', $result['engine']);
        $this->assertSame('review_queue', $result['status']);
    }

    // ------------------------------------------------------------------
    // Phase 2 confidence values
    // ------------------------------------------------------------------

    public function test_phase2_non_image_confidence_is_80(): void {
        $result = $this->classifier()->classify(
            ['raw_text' => 'Compute the matrix product.', 'step_count' => 3, 'dragboxes' => [], 'has_image_refs' => false],
            'matrices'
        );
        $this->assertSame(80, $result['confidence']);
    }

    public function test_phase2_image_flagged_confidence_is_70(): void {
        $result = $this->classifier()->classify(
            ['raw_text' => 'See diagram.', 'step_count' => 2, 'dragboxes' => [], 'has_image_refs' => true],
            'statistics'
        );
        $this->assertSame(70, $result['confidence']);
    }
}
