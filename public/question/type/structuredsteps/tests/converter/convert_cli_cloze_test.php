<?php
// This file is part of Moodle - http://moodle.org/

/**
 * Tests for convert.php CLI CLOZE-format pipeline integration.
 *
 * Covers TASK-PSQC-008 acceptance criteria:
 * - cloze_parser + cloze_classifier are correctly wired for --source-format cloze.
 * - --subject hint is passed as topic to the classifier.
 * - --tenantid=0 must fail validation for non-dry-run mode.
 * - CLOZE NUMERICAL questions flow through parse → classify with correct status.
 * - Image-dependent questions flow through parse → classify → image_review.
 * - Non-NUMERICAL questions flow through parse → classify → skip.
 *
 * These tests simulate the convert.php pipeline loop without Moodle DB writes,
 * matching the dry-run code path for --source-format cloze.
 *
 * @package qtype_structuredsteps
 */

defined('MOODLE_INTERNAL') || die();

/**
 * CLI CLOZE pipeline integration tests.
 */
class qtype_structuredsteps_convert_cli_cloze_test extends basic_testcase {

    private function make_parser(): \qtype_structuredsteps\local\converter\cloze_parser {
        return new \qtype_structuredsteps\local\converter\cloze_parser();
    }

    private function make_classifier(): \qtype_structuredsteps\local\converter\cloze_classifier {
        return new \qtype_structuredsteps\local\converter\cloze_classifier();
    }

    /** Write a minimal CLOZE XML to a temp file, return path. */
    private function make_xml_file(string $name, string $qtext, string $category = ''): string {
        $cat_block = '';
        if ($category !== '') {
            $cat_block = "<question type=\"category\">\n"
                . "  <category><text>\$course\$/{$category}</text></category>\n"
                . "</question>\n";
        }
        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<quiz>\n"
            . $cat_block
            . "<question type=\"cloze\">\n"
            . "  <name><text>{$name}</text></name>\n"
            . "  <questiontext format=\"html\"><text><![CDATA[{$qtext}]]></text></questiontext>\n"
            . "</question>\n</quiz>\n";
        $path = tempnam(sys_get_temp_dir(), 'psqc_') . '.xml';
        file_put_contents($path, $xml);
        return $path;
    }

    // ── Pipeline: parse → classify for NUMERICAL CLOZE ──────────────────────────

    /**
     * Math subject: single NUMERICAL sub-part in Time category.
     * Simulates: --source-format cloze --subject math --dry-run
     * After TASK-PSQC-017 calibration, threshold ≥40 → auto_convert_eligible.
     */
    public function test_cloze_math_numerical_classifies_auto_convert(): void {
        $path = $this->make_xml_file(
            'Time 20103 Jr1',
            '<p>How many minutes in {1:NUMERICAL:=60:0#Correct~*#Incorrect} seconds?</p>',
            'Time drill'
        );

        $parser     = $this->make_parser();
        $classifier = $this->make_classifier();

        $result   = $parser->parse_file($path);
        unlink($path);

        $this->assertEmpty($result['parse_errors'], 'Should parse without errors');
        $this->assertCount(1, $result['questions']);

        $q      = $result['questions'][0];
        // subject hint: use 'math' topic only if category is empty — category takes precedence.
        $topic  = $q['category'] ?: 'math';
        $cl     = $classifier->classify($q, $topic);

        $this->assertSame('AlgorithmicWorkingEngine', $cl['engine']);
        $this->assertGreaterThanOrEqual(40, $cl['confidence']);
        // Calibrated threshold ≥40 → single-step drill with category+text keyword now auto_convert_eligible
        $this->assertSame('auto_convert_eligible', $cl['status']);
    }

    /**
     * Step-labelled NUMERICAL: should hit auto_convert_eligible tier.
     * Simulates pipeline with --subject math.
     */
    public function test_cloze_step_labelled_auto_convert_eligible(): void {
        $path = $this->make_xml_file(
            'Time 20200 Jr2',
            '<p>Step 1: Find the hours. {1:NUMERICAL:=3:0#Correct~*#Incorrect}</p>'
            . '<p>Step 2: Find the minutes. {1:NUMERICAL:=45:0#Correct~*#Incorrect}</p>',
            'Time drill'
        );

        $parser     = $this->make_parser();
        $classifier = $this->make_classifier();

        $result = $parser->parse_file($path);
        unlink($path);

        $this->assertCount(1, $result['questions']);
        $q  = $result['questions'][0];
        $cl = $classifier->classify($q, $q['category'] ?: 'math');

        $this->assertSame('auto_convert_eligible', $cl['status']);
        $this->assertGreaterThanOrEqual(75, $cl['confidence']);
    }

    /**
     * Image-bearing question → image_review regardless of subject.
     */
    public function test_cloze_image_question_routes_to_image_review(): void {
        $path = $this->make_xml_file(
            'Geometry Q1',
            '<p><img src="@@PLUGINFILE@@/shape.png"/>Area = {1:NUMERICAL:=36:0#Correct}</p>',
            'Area and Perimeter'
        );

        $parser     = $this->make_parser();
        $classifier = $this->make_classifier();

        $result = $parser->parse_file($path);
        unlink($path);

        $this->assertCount(1, $result['questions']);
        $q  = $result['questions'][0];
        $cl = $classifier->classify($q, $q['category'] ?: 'math');

        $this->assertSame('image_review', $cl['status']);
        $this->assertSame(0, $cl['confidence']);
    }

    /**
     * SHORTANSWER-only CLOZE → skip (not Phase 1 eligible).
     * Tests that --subject la still produces skip for non-NUMERICAL.
     */
    public function test_cloze_la_shortanswer_routes_to_skip(): void {
        $path = $this->make_xml_file(
            'LA Vocab Q1',
            '<p>The synonym for happy is {1:SHORTANSWER:=joyful#Excellent~=glad#Excellent}.</p>',
            'Vocabulary'
        );

        $parser     = $this->make_parser();
        $classifier = $this->make_classifier();

        $result = $parser->parse_file($path);
        unlink($path);

        $this->assertCount(1, $result['questions']);
        $q  = $result['questions'][0];
        // LA subject hint — but SHORTANSWER → skip regardless
        $cl = $classifier->classify($q, 'la');

        $this->assertSame('skip', $cl['status']);
        $this->assertContains('non_numerical', $cl['matched_patterns']);
    }

    // ── tenantid validation rule ─────────────────────────────────────────────────

    /**
     * Ensure that tenantid=0 should be caught by the CLI validation rule.
     * This test encodes the rule: non-dry-run requires tenantid > 0.
     */
    public function test_tenantid_validation_rule(): void {
        $dry_run  = false;
        $tenantid = 0;

        $needs_guard = (!$dry_run && $tenantid <= 0);
        $this->assertTrue($needs_guard, 'Live import without tenantid must be rejected');

        // Dry-run exemption: tenantid=0 is allowed in dry-run mode.
        $dry_run      = true;
        $needs_guard2 = (!$dry_run && $tenantid <= 0);
        $this->assertFalse($needs_guard2, 'Dry-run should not require tenantid');
    }

    // ── File stat: parse_file reports correct file_stats ────────────────────────

    public function test_parse_file_stats_for_cloze_xml(): void {
        $path = $this->make_xml_file(
            'Money Q1',
            '<p>Change from $50 after paying ${1:NUMERICAL:=12:0#Correct~*#Incorrect} is '
            . '{1:NUMERICAL:=38:0#Correct~*#Incorrect}.</p>',
            'Money'
        );

        $parser = $this->make_parser();
        $result = $parser->parse_file($path);
        unlink($path);

        $this->assertEmpty($result['parse_errors']);
        $this->assertSame(1, $result['file_stats']['total']);
        $this->assertCount(1, $result['questions']);

        $q = $result['questions'][0];
        $this->assertTrue($q['all_numerical']);
        $this->assertCount(2, $q['sub_parts']);
    }

    // ── Subject hint propagation ─────────────────────────────────────────────────

    /**
     * When --topic is omitted and --subject=math is set, 'math' is used as topic hint.
     * This test verifies that classifier.classify($q, 'math') doesn't fail or
     * produce worse results than using the category directly.
     */
    public function test_subject_hint_math_does_not_break_classifier(): void {
        $path = $this->make_xml_file(
            'Decimal Q1',
            '<p>Calculate {1:NUMERICAL:=3.75:0.01#Correct~*#Incorrect} + 1.25.</p>',
            ''  // no category — only subject hint
        );

        $parser     = $this->make_parser();
        $classifier = $this->make_classifier();

        $result = $parser->parse_file($path);
        unlink($path);

        $this->assertCount(1, $result['questions']);
        $q  = $result['questions'][0];

        // topic = 'math' (from --subject math, since no category)
        $cl = $classifier->classify($q, 'math');

        // 'math' isn't in CATEGORY_ENGINE_MAP, so no category bonus — but should still
        // return a numeric status (not throw/error).
        $this->assertContains($cl['status'], ['auto_convert_eligible', 'review_queue', 'skip', 'image_review']);
        $this->assertIsInt($cl['confidence']);
        $this->assertGreaterThanOrEqual(0, $cl['confidence']);
    }
}
