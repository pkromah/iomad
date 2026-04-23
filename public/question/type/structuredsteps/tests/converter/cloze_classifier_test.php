<?php
// This file is part of Moodle - http://moodle.org/

/**
 * Tests for qtype_structuredsteps cloze_classifier.
 *
 * Covers TASK-PSQC-007 acceptance criteria:
 * - Classifier assigns confidence 0–100 and engine string to each CLOZE NUMERICAL question.
 * - Image-dependent questions score 0 and return status 'image_review' regardless of other rules.
 * - 10 known questions (mixing real-data categories + synthetic step labels) produce expected
 *   engine and confidence tier.
 *
 * Tiers:
 *  - auto_convert_eligible: confidence ≥ 75 (requires step labels + NUMERICAL + category keyword)
 *  - review_queue:           confidence 50–74
 *  - skip:                   confidence < 50
 *  - image_review:           image refs present (forced 0)
 *
 * @package qtype_structuredsteps
 */

defined('MOODLE_INTERNAL') || die();

/**
 * CLOZE classifier unit tests.
 */
class qtype_structuredsteps_cloze_classifier_test extends basic_testcase {

    private function make_classifier(): \qtype_structuredsteps\local\converter\cloze_classifier {
        return new \qtype_structuredsteps\local\converter\cloze_classifier();
    }

    /**
     * Build a minimal parsed array matching cloze_parser::parse_question() output.
     *
     * @param string $raw_text    HTML question text (may include <img> for image tests).
     * @param string $category    Category name (as extracted from XML).
     * @param bool   $all_numerical
     * @param bool   $mixed_types
     * @param int    $sub_part_count
     * @return array
     */
    private function make_parsed(
        string $raw_text,
        string $category = '',
        bool $all_numerical = true,
        bool $mixed_types = false,
        int $sub_part_count = 1
    ): array {
        $has_image = (bool)(
            preg_match('/<img[^>]+>/', $raw_text)
            || str_contains($raw_text, '@@PLUGINFILE@@')
            || preg_match('/img_\w+/i', $raw_text)
        );

        $sub_parts = [];
        for ($i = 0; $i < $sub_part_count; $i++) {
            $sub_parts[] = ['position' => $i, 'subtype' => 'NUMERICAL', 'answers' => [['correct' => true, 'value' => '1', 'tolerance' => null, 'feedback' => '']]];
        }

        return [
            'name'            => 'test_q',
            'raw_text'        => $raw_text,
            'category'        => $category,
            'has_image_refs'  => $has_image,
            'image_refs'      => $has_image ? ['img.png'] : [],
            'all_numerical'   => $all_numerical,
            'has_mixed_types' => $mixed_types,
            'sub_parts'       => $sub_parts,
            'subtypes'        => $all_numerical ? ['NUMERICAL'] : ['NUMERICAL', 'SHORTANSWER'],
            'step_count'      => $sub_part_count,
        ];
    }

    // ── Image guard ─────────────────────────────────────────────────────────────

    /** Q1: image-blocked — forced 0, status = image_review */
    public function test_image_question_forced_zero(): void {
        $classifier = $this->make_classifier();
        $parsed     = $this->make_parsed(
            '<p><img src="@@PLUGINFILE@@/diagram.png"/>What is the area?</p>',
            'Area and Perimeter'
        );

        $result = $classifier->classify($parsed);
        $this->assertSame(0, $result['confidence']);
        $this->assertSame('image_review', $result['status']);
        $this->assertSame('', $result['engine']);
        $this->assertContains('image_blocked', $result['matched_patterns']);
    }

    /** Q2: img_ token also triggers image guard */
    public function test_img_token_question_forced_zero(): void {
        $classifier = $this->make_classifier();
        $parsed     = $this->make_parsed(
            '<p>img_STD4SHAPE_01 Which shape has the greater area?</p>',
            'Geometry'
        );

        $result = $classifier->classify($parsed);
        $this->assertSame('image_review', $result['status']);
        $this->assertSame(0, $result['confidence']);
    }

    // ── Non-NUMERICAL skip ───────────────────────────────────────────────────────

    /** Q3: SHORTANSWER-only CLOZE → skip (not Phase 1 eligible) */
    public function test_shortanswer_only_is_skip(): void {
        $classifier = $this->make_classifier();
        $parsed     = $this->make_parsed(
            '<p>The unit of mass is _____.</p>',
            'Units',
            false,   // all_numerical = false
            false    // mixed_types = false
        );

        $result = $classifier->classify($parsed);
        $this->assertSame('skip', $result['status']);
        $this->assertSame('', $result['engine']);
        $this->assertContains('non_numerical', $result['matched_patterns']);
    }

    // ── Auto-convert tier: single-step drill (calibrated threshold ≥40) ────────────
    // TASK-PSQC-017: thresholds lowered from 75/50 to 40/25 based on dry-run of 95 questions.
    // Single-step drill questions scoring 40–74 are now auto_convert_eligible, not review_queue.

    /** Q4: Time category, single NUMERICAL sub-part, no step labels → auto_convert_eligible (conf ≥40) */
    public function test_time_category_no_steps_auto_convert(): void {
        // Real data pattern: "Time 20103 Jr1" from source inventory.
        // Scoring: +25 (all_numerical) +15 (category 'time') +10 (text 'minute') = 50 → auto_convert_eligible (≥40).
        $classifier = $this->make_classifier();
        $parsed     = $this->make_parsed(
            '<p>How many minutes are in 56 seconds?</p>',
            'Time drill questions'
        );

        $result = $classifier->classify($parsed);
        $this->assertSame('AlgorithmicWorkingEngine', $result['engine']);
        $this->assertGreaterThanOrEqual(40, $result['confidence']);
        $this->assertSame('auto_convert_eligible', $result['status']);
    }

    /** Q5: Money category, no step labels → auto_convert_eligible (calibrated threshold) */
    public function test_money_category_auto_convert(): void {
        $classifier = $this->make_classifier();
        $parsed     = $this->make_parsed(
            '<p>A shirt costs $38.50. Calculate the change from $50.</p>',
            'Money drill Q'
        );

        $result = $classifier->classify($parsed);
        $this->assertSame('AlgorithmicWorkingEngine', $result['engine']);
        $this->assertSame('auto_convert_eligible', $result['status']);
    }

    /** Q6: Area/perimeter category, single sub-part, no step labels → auto_convert_eligible (calibrated) */
    public function test_area_category_auto_convert(): void {
        $classifier = $this->make_classifier();
        $parsed     = $this->make_parsed(
            '<p>Calculate the perimeter of a rectangle with length 8 m and width 5 m.</p>',
            'Area and Perimeter'
        );

        $result = $classifier->classify($parsed);
        $this->assertSame('StepCalculationEngine', $result['engine']);
        $this->assertGreaterThanOrEqual(40, $result['confidence']);
        $this->assertSame('auto_convert_eligible', $result['status']);
    }

    // ── Auto-convert tier: step-labelled questions ───────────────────────────────

    /** Q7: Time category + step labels → auto_convert_eligible (high confidence) */
    public function test_time_with_step_labels_auto_convert(): void {
        // Scoring: +25 (numerical) +15 (category 'time') +10 (text 'minute') +40 (2 step labels) = 90
        $classifier = $this->make_classifier();
        $parsed     = $this->make_parsed(
            '<p>Step 1: Convert hours to minutes. Step 2: Calculate the total time in minutes.</p>',
            'Time drill questions',
            true, false, 2
        );

        $result = $classifier->classify($parsed);
        $this->assertSame('AlgorithmicWorkingEngine', $result['engine']);
        $this->assertGreaterThanOrEqual(75, $result['confidence']);
        $this->assertSame('auto_convert_eligible', $result['status']);
    }

    /** Q8: Money multi-step with Calculate: label → auto_convert_eligible */
    public function test_money_calculate_label_auto_convert(): void {
        $classifier = $this->make_classifier();
        $parsed     = $this->make_parsed(
            '<p>Kezia earns $240 per week. Calculate: her monthly earnings. Find: the total annual savings.</p>',
            'Money revision Q',
            true, false, 2
        );

        $result = $classifier->classify($parsed);
        $this->assertSame('AlgorithmicWorkingEngine', $result['engine']);
        $this->assertGreaterThanOrEqual(75, $result['confidence']);
        $this->assertSame('auto_convert_eligible', $result['status']);
    }

    /** Q9: Area + step labels → StepCalculationEngine, auto_convert_eligible */
    public function test_area_with_step_labels_auto_convert(): void {
        $classifier = $this->make_classifier();
        $parsed     = $this->make_parsed(
            '<p>Step 1: Find the length of the rectangle. Step 2: Calculate the area.</p>',
            'Area and Perimeter',
            true, false, 2
        );

        $result = $classifier->classify($parsed);
        $this->assertSame('StepCalculationEngine', $result['engine']);
        $this->assertGreaterThanOrEqual(75, $result['confidence']);
        $this->assertSame('auto_convert_eligible', $result['status']);
    }

    // ── Mixed types and penalties ────────────────────────────────────────────────

    /** Q10: Mixed NUMERICAL + SHORTANSWER → reduced confidence, skip tier */
    public function test_mixed_subtypes_reduces_confidence(): void {
        $classifier = $this->make_classifier();
        $parsed     = $this->make_parsed(
            '<p>The mass of the cow is _____ kg.</p>',
            'Units of Measure',
            false,  // all_numerical = false
            true    // mixed_types = true
        );

        $result = $classifier->classify($parsed);
        $this->assertContains('mixed_subtypes', $result['matched_patterns']);
        // Mixed penalty applied: base confidence < 50 without step labels.
        $this->assertLessThan(75, $result['confidence']);
    }

    // ── Ambiguous engine ─────────────────────────────────────────────────────────

    public function test_ambiguous_engine_applies_penalty(): void {
        $classifier = $this->make_classifier();
        // Text contains both algo and step keywords with similar weight.
        $parsed     = $this->make_parsed(
            '<p>Calculate the percentage of money saved as a fraction of total cost.</p>',
            ''
        );

        $result = $classifier->classify($parsed);
        $this->assertContains('ambiguous_engine', $result['matched_patterns']);
    }

    // ── Regression: image blocks regardless of good category signal ──────────────

    public function test_image_block_overrides_good_category(): void {
        $classifier = $this->make_classifier();
        // Excellent category + step labels BUT has image — must still return image_review.
        $parsed     = $this->make_parsed(
            '<p>img_diagram Step 1: Calculate the area. Step 2: Find the perimeter.</p>',
            'Area and Perimeter',
            true, false, 2
        );

        $result = $classifier->classify($parsed);
        $this->assertSame('image_review', $result['status']);
        $this->assertSame(0, $result['confidence']);
    }
}
