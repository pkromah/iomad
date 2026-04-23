<?php
// This file is part of Moodle - http://moodle.org/

/**
 * Tests for GraphEngine (Phase 2 — Vectors and Matrices).
 *
 * @package   qtype_structuredsteps
 */

defined('MOODLE_INTERNAL') || die();

use qtype_structuredsteps\local\converter\engines\GraphEngine;

/**
 * GraphEngine unit tests.
 */
class qtype_structuredsteps_graph_engine_test extends basic_testcase {

    private function engine(): GraphEngine {
        return new GraphEngine();
    }

    // ------------------------------------------------------------------
    // getEngineName
    // ------------------------------------------------------------------

    public function test_engine_name(): void {
        $this->assertSame('graph', $this->engine()->getEngineName());
    }

    // ------------------------------------------------------------------
    // canHandle
    // ------------------------------------------------------------------

    public function test_can_handle_matrix_topic(): void {
        $engine = $this->engine();
        $this->assertTrue($engine->canHandle(['raw_text' => 'Find the inverse.', 'topic' => 'matrices']));
    }

    public function test_can_handle_vector_topic(): void {
        $engine = $this->engine();
        $this->assertTrue($engine->canHandle(['raw_text' => 'Add the two vectors.', 'topic' => 'vectors']));
    }

    public function test_can_handle_determinant_keyword(): void {
        $engine = $this->engine();
        $this->assertTrue($engine->canHandle([
            'raw_text' => 'Find the determinant of the 2×2 matrix.',
            'topic'    => '',
        ]));
    }

    public function test_cannot_handle_unrelated_question(): void {
        $engine = $this->engine();
        $this->assertFalse($engine->canHandle([
            'raw_text' => 'Calculate the mean of the data set.',
            'topic'    => 'statistics',
        ]));
    }

    // ------------------------------------------------------------------
    // Image-flagged → review_queue
    // ------------------------------------------------------------------

    public function test_image_flagged_returns_review_queue(): void {
        $engine = $this->engine();
        $result = $engine->convertToSteps([
            'raw_text'       => 'Given the diagram, find the determinant.',
            'topic'          => 'matrices',
            'has_image_refs' => true,
        ]);

        $this->assertSame('review_queue', $result['status']);
        $this->assertEmpty($result['steps']);
        $this->assertSame('graph', $result['engine']);
    }

    // ------------------------------------------------------------------
    // Matrix operations
    // ------------------------------------------------------------------

    public function test_determinant_steps(): void {
        $engine = $this->engine();
        $result = $engine->convertToSteps([
            'raw_text' => '<p>Find the determinant of matrix A = [[2,3],[1,4]].</p>',
            'topic'    => 'matrices',
        ]);

        $this->assertSame('auto_convert_eligible', $result['status']);
        $this->assertNotEmpty($result['steps']);
        $this->assertSame('Step 1', $result['steps'][0]['label']);
        $this->assertStringContainsString('matrix', strtolower($result['steps'][0]['content']));
        $this->assertContains('matrix_op:determinant', $result['notes']);
    }

    public function test_inverse_steps(): void {
        $engine = $this->engine();
        $result = $engine->convertToSteps([
            'raw_text' => '<p>Find the inverse of matrix M.</p>',
            'topic'    => 'matrices',
        ]);

        $this->assertSame('auto_convert_eligible', $result['status']);
        $this->assertContains('matrix_op:inverse', $result['notes']);
        $this->assertGreaterThanOrEqual(4, count($result['steps']));
    }

    public function test_matrix_multiplication_steps(): void {
        $engine = $this->engine();
        $result = $engine->convertToSteps([
            'raw_text' => '<p>Find the product of matrices A and B.</p>',
            'topic'    => 'matrices',
        ]);

        $this->assertSame('auto_convert_eligible', $result['status']);
        $this->assertContains('matrix_op:multiply', $result['notes']);
    }

    public function test_transpose_steps(): void {
        $engine = $this->engine();
        $result = $engine->convertToSteps([
            'raw_text' => '<p>Find the transpose of matrix P.</p>',
            'topic'    => 'matrices',
        ]);

        $this->assertSame('auto_convert_eligible', $result['status']);
        $this->assertContains('matrix_op:transpose', $result['notes']);
    }

    public function test_matrix_addition_default(): void {
        $engine = $this->engine();
        $result = $engine->convertToSteps([
            'raw_text' => '<p>Add the two matrices A and B.</p>',
            'topic'    => 'matrices',
        ]);

        $this->assertSame('auto_convert_eligible', $result['status']);
        $this->assertContains('matrix_op:add', $result['notes']);
    }

    // ------------------------------------------------------------------
    // Vector operations
    // ------------------------------------------------------------------

    public function test_vector_magnitude_steps(): void {
        $engine = $this->engine();
        $result = $engine->convertToSteps([
            'raw_text' => '<p>Find the magnitude of vector v = (3, 4).</p>',
            'topic'    => 'vectors',
        ]);

        $this->assertSame('auto_convert_eligible', $result['status']);
        $this->assertContains('vector_op:magnitude', $result['notes']);
        $this->assertGreaterThanOrEqual(3, count($result['steps']));
    }

    public function test_vector_addition_default(): void {
        $engine = $this->engine();
        $result = $engine->convertToSteps([
            'raw_text' => '<p>Add the two vectors a and b.</p>',
            'topic'    => 'vectors',
        ]);

        $this->assertSame('auto_convert_eligible', $result['status']);
        $this->assertContains('vector_op:add', $result['notes']);
    }

    public function test_dot_product_steps(): void {
        $engine = $this->engine();
        $result = $engine->convertToSteps([
            'raw_text' => '<p>Find the dot product of vectors u and v.</p>',
            'topic'    => 'vectors',
        ]);

        $this->assertSame('auto_convert_eligible', $result['status']);
        $this->assertContains('vector_op:dot product', $result['notes']);
    }

    // ------------------------------------------------------------------
    // Step structure consistency
    // ------------------------------------------------------------------

    public function test_steps_have_label_and_content_keys(): void {
        $engine = $this->engine();
        $result = $engine->convertToSteps([
            'raw_text' => '<p>Find the determinant of A.</p>',
            'topic'    => 'matrices',
        ]);

        foreach ($result['steps'] as $step) {
            $this->assertArrayHasKey('label', $step);
            $this->assertArrayHasKey('content', $step);
            $this->assertNotEmpty($step['label']);
            $this->assertNotEmpty($step['content']);
        }
    }
}
