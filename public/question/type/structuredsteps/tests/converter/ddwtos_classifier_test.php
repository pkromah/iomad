<?php
// This file is part of Moodle - http://moodle.org/

/**
 * Tests for qtype_structuredsteps ddwtos_classifier.
 *
 * @package   qtype_structuredsteps
 */

defined('MOODLE_INTERNAL') || die();

/**
 * DDWTOS classifier tests.
 */
class qtype_structuredsteps_ddwtos_classifier_test extends basic_testcase {

    private function make_classifier(): \qtype_structuredsteps\local\converter\ddwtos_classifier {
        return new \qtype_structuredsteps\local\converter\ddwtos_classifier();
    }

    private function make_parsed(string $text, array $dragbox_texts, int $step_count, bool $has_images = false): array {
        $dragboxes = array_map(fn($t) => ['text' => $t, 'group' => 1, 'position' => 0], $dragbox_texts);
        return [
            'raw_text' => $text,
            'dragboxes' => $dragboxes,
            'step_count' => $step_count,
            'has_image_refs' => $has_images,
            'image_refs' => $has_images ? ['img_test'] : [],
        ];
    }

    public function test_multiplication_keyword_classifies_to_long_multiplication(): void {
        $classifier = $this->make_classifier();
        $parsed = $this->make_parsed(
            '<p>Step 1: Multiply 53 × 26 [[1]]</p><p>Step 2: [[2]]</p>',
            ['1378', '2', '3', '1300', '1000', '1200'],
            2
        );

        $result = $classifier->classify($parsed, 'Computation');
        $this->assertSame('long_multiplication', $result['engine']);
        $this->assertGreaterThanOrEqual(75, $result['confidence']);
        $this->assertSame('auto_convert_eligible', $result['status']);
    }

    public function test_fractions_topic_classifies_to_step_calculation(): void {
        $classifier = $this->make_classifier();
        $parsed = $this->make_parsed(
            '<p>Step 1: Find 15% of 240 [[1]]</p><p>Step 2: Calculate final [[2]]</p>',
            ['15/100 × 240 = 36', '36', 'wrong1', 'wrong2'],
            2
        );

        $result = $classifier->classify($parsed, 'Fractions & Decimals');
        $this->assertSame('step_calculation', $result['engine']);
        $this->assertGreaterThanOrEqual(75, $result['confidence']);
    }

    public function test_ledger_keyword_classifies_to_ledger_poa(): void {
        $classifier = $this->make_classifier();
        $parsed = $this->make_parsed(
            '<p>Record the debit entry in the Cash account [[1]]</p><p>Record the credit [[2]]</p>',
            ['Cash 500', 'Sales Revenue 500', 'Bank 500', 'Expense 500'],
            2
        );

        $result = $classifier->classify($parsed, 'Accounts');
        $this->assertSame('ledger_poa', $result['engine']);
        $this->assertSame('auto_convert_eligible', $result['status']);
    }

    public function test_division_keyword_classifies_to_long_division(): void {
        $classifier = $this->make_classifier();
        $parsed = $this->make_parsed(
            '<p>Step 1: Divide 784 ÷ 12 [[1]]</p>',
            ['65 r 4', '65', '64', '66'],
            1
        );

        $result = $classifier->classify($parsed, 'Computation');
        $this->assertSame('long_division', $result['engine']);
    }

    public function test_image_refs_force_review_queue(): void {
        $classifier = $this->make_classifier();
        $parsed = $this->make_parsed(
            '<p>Step 1: From the diagram img_fig1 [[1]]</p>',
            ['answer', 'wrong'],
            1,
            true  // has_images
        );

        $result = $classifier->classify($parsed, 'Measurement');
        $this->assertSame('review_queue', $result['status']);
        $this->assertStringContainsString('Image refs', implode(' ', $result['notes']));
    }

    public function test_statistics_topic_routes_to_evidence_mapping(): void {
        // Phase 2: Statistics → evidence_mapping engine (added after initial test was written).
        $classifier = $this->make_classifier();
        $parsed = $this->make_parsed('<p>[[1]] [[2]]</p>', ['A', 'B', 'C', 'D'], 0);

        $result = $classifier->classify($parsed, 'Statistics');
        $this->assertSame('evidence_mapping', $result['engine']);
        $this->assertSame('auto_convert_eligible', $result['status']);
    }

    public function test_matrices_topic_routes_to_graph(): void {
        // Phase 2: Matrices → graph engine (added after initial test was written).
        $classifier = $this->make_classifier();
        $parsed = $this->make_parsed('<p>[[1]]</p>', ['A', 'B'], 0);

        $result = $classifier->classify($parsed, 'Matrices');
        $this->assertSame('graph', $result['engine']);
        $this->assertSame('auto_convert_eligible', $result['status']);
    }

    public function test_no_step_labels_reduces_confidence(): void {
        $classifier = $this->make_classifier();
        $parsed = $this->make_parsed(
            '<p>Calculate the result [[1]]</p>',
            ['36', '42', '30'],
            0  // no step labels
        );

        $result = $classifier->classify($parsed, 'Fractions & Decimals');
        // Confidence should be lower than a question with step labels
        $this->assertLessThan(75, $result['confidence']);
    }

    public function test_low_confidence_question_goes_to_skip_or_review(): void {
        $classifier = $this->make_classifier();
        $parsed = $this->make_parsed('<p>Vague question [[1]] [[2]]</p>', ['X', 'Y', 'Z'], 0);

        $result = $classifier->classify($parsed, '');
        $this->assertContains($result['status'], ['skip', 'review_queue']);
    }

    public function test_numeric_dragboxes_boost_confidence(): void {
        $classifier = $this->make_classifier();

        $numeric_parsed = $this->make_parsed(
            '<p>Step 1: Multiply [[1]]</p><p>Step 2: Add [[2]]</p><p>Step 3: Total [[3]]</p>',
            ['$$4.71 \times 8.3 = 39.09$$', '$$2.5^2 = 6.25$$', '$$39.09 - 6.25 = 32.84$$',
             '$$4.71 \times 8.3 = 39.00$$', '$$2.5^2 = 6.20$$', '$$39.00 - 6.20 = 32.80$$'],
            3
        );

        $text_parsed = $this->make_parsed(
            '<p>Step 1: [[1]]</p><p>Step 2: [[2]]</p><p>Step 3: [[3]]</p>',
            ['Maybe this', 'Perhaps that', 'Possibly another', 'Also this', 'Or that', 'And this'],
            3
        );

        $r_numeric = $this->make_classifier()->classify($numeric_parsed, 'Fractions & Decimals');
        $r_text    = $this->make_classifier()->classify($text_parsed, 'Fractions & Decimals');

        $this->assertGreaterThan($r_text['confidence'], $r_numeric['confidence']);
    }
}
