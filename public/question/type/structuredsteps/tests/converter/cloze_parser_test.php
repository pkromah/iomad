<?php
// This file is part of Moodle - http://moodle.org/

/**
 * Tests for qtype_structuredsteps cloze_parser.
 *
 * Covers TASK-PSQC-006 acceptance criteria:
 * - Parser extracts: name / questiontext / per-sub-part: position / subtype /
 *   correct_answer / alternate_answers / feedback.
 * - Round-trip invariant: all correct answers survive parse → slot_answers.
 * - Image detection works.
 * - Wildcard (*) answers are excluded.
 * - Generic feedback strings are discarded.
 * - Tolerance extraction for NUMERICAL.
 * - Step labels derived from surrounding text.
 *
 * @package qtype_structuredsteps
 */

defined('MOODLE_INTERNAL') || die();

/**
 * CLOZE parser unit tests.
 */
class qtype_structuredsteps_cloze_parser_test extends basic_testcase {

    private function make_parser(): \qtype_structuredsteps\local\converter\cloze_parser {
        return new \qtype_structuredsteps\local\converter\cloze_parser();
    }

    /** Build a minimal CLOZE question XML string. */
    private function make_xml(string $name, string $qtext, string $category = ''): string {
        $cat_block = '';
        if ($category !== '') {
            $cat_block = <<<XML
<question type="category">
  <category><text>\$course\$/{$category}</text></category>
</question>
XML;
        }
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<quiz>
{$cat_block}
<question type="cloze">
  <name><text>{$name}</text></name>
  <questiontext format="html"><text><![CDATA[{$qtext}]]></text></questiontext>
</question>
</quiz>
XML;
    }

    /** Write XML to a temp file, return path. */
    private function write_tmp(string $xml): string {
        $path = tempnam(sys_get_temp_dir(), 'cloze_') . '.xml';
        file_put_contents($path, $xml);
        return $path;
    }

    // ---- parse_answers ----

    public function test_parse_answers_numerical_exact(): void {
        $parser  = $this->make_parser();
        $answers = $parser->parse_answers('=15#Excellent', 'NUMERICAL');
        $this->assertCount(1, $answers);
        $this->assertTrue($answers[0]['correct']);
        $this->assertSame('15', $answers[0]['value']);
        $this->assertNull($answers[0]['tolerance']);
        $this->assertSame('', $answers[0]['feedback'], 'Generic "Excellent" should be discarded');
    }

    public function test_parse_answers_numerical_with_tolerance(): void {
        $parser  = $this->make_parser();
        $answers = $parser->parse_answers('=11.75:0#Excellent', 'NUMERICAL');
        $this->assertCount(1, $answers);
        $this->assertSame('11.75', $answers[0]['value']);
        $this->assertSame('0', $answers[0]['tolerance']);
    }

    public function test_parse_answers_wildcard_excluded(): void {
        $parser  = $this->make_parser();
        $answers = $parser->parse_answers('=36#Correct~*#Incorrect', 'NUMERICAL');
        $this->assertCount(1, $answers, 'Wildcard * must be excluded');
        $this->assertSame('36', $answers[0]['value']);
    }

    public function test_parse_answers_shortanswer_multiple_alternates(): void {
        $parser  = $this->make_parser();
        $raw     = '=9/100#Excellent~=9 hundredths#Excellent~=nine hundredths#Excellent~=0.09#Excellent';
        $answers = $parser->parse_answers($raw, 'SHORTANSWER');
        $this->assertCount(4, $answers);
        foreach ($answers as $a) {
            $this->assertTrue($a['correct']);
            $this->assertSame('', $a['feedback'], 'All feedback strings are generic — should be discarded');
        }
        $this->assertSame('9/100', $answers[0]['value']);
        $this->assertSame('0.09', $answers[3]['value']);
    }

    public function test_parse_answers_multichoice_leading_tilde(): void {
        $parser  = $this->make_parser();
        // MULTICHOICE format starts with ~ before =correct
        $raw     = '~=correct_answer#Correct~wrong1#Incorrect~wrong2#Incorrect';
        $answers = $parser->parse_answers($raw, 'MULTICHOICE');
        // First is correct, others are wrong
        $this->assertTrue($answers[0]['correct']);
        $this->assertSame('correct_answer', $answers[0]['value']);
        $this->assertSame('', $answers[0]['feedback'], 'Generic "Correct" discarded');
        $this->assertFalse($answers[1]['correct']);
        $this->assertSame('wrong1', $answers[1]['value']);
        $this->assertSame('', $answers[1]['feedback'], 'Generic "Incorrect" discarded');
    }

    public function test_parse_answers_multiresponse_shorthand(): void {
        // RX is Moodle shorthand for MULTIRESPONSE — sub-type alias must be applied upstream
        // (cloze_parser normalises in extract_sub_parts, not parse_answers).
        // Substantive feedback (not in GENERIC_FEEDBACK) is retained.
        $parser  = $this->make_parser();
        $raw     = '~=ans1#Good work~ans2#Try again';
        $answers = $parser->parse_answers($raw, 'MULTIRESPONSE');
        $this->assertTrue($answers[0]['correct']);
        $this->assertSame('ans1', $answers[0]['value']);
        // "Good work" is not generic, should be retained.
        $this->assertSame('Good work', $answers[0]['feedback']);
        $this->assertFalse($answers[1]['correct']);
    }

    public function test_parse_answers_substantive_feedback_retained(): void {
        $parser  = $this->make_parser();
        $answers = $parser->parse_answers('=Paris#Well done, the capital is Paris!', 'SHORTANSWER');
        $this->assertSame('Well done, the capital is Paris!', $answers[0]['feedback']);
    }

    // ---- extract_sub_parts ----

    public function test_extract_sub_parts_single_numerical(): void {
        $parser = $this->make_parser();
        $text   = '<p>How many minutes? {1:NUMERICAL:=15#Excellent}</p>';
        $parts  = $parser->extract_sub_parts($text);
        $this->assertCount(1, $parts);
        $this->assertSame('NUMERICAL', $parts[0]['subtype']);
        $this->assertSame(0, $parts[0]['position']);
        $this->assertSame('15', $parts[0]['answers'][0]['value']);
    }

    public function test_extract_sub_parts_multiple_subtypes(): void {
        $parser = $this->make_parser();
        $text   = '{1:NUMERICAL:=27:0#Correct} kg and {1:SHORTANSWER:=Kilograms#Excellent}';
        $parts  = $parser->extract_sub_parts($text);
        $this->assertCount(2, $parts);
        $this->assertSame('NUMERICAL', $parts[0]['subtype']);
        $this->assertSame('SHORTANSWER', $parts[1]['subtype']);
        $this->assertSame(1, $parts[1]['position']);
    }

    public function test_extract_sub_parts_no_cloze(): void {
        $parser = $this->make_parser();
        $text   = '<p>Plain question text with no CLOZE sub-parts.</p>';
        $parts  = $parser->extract_sub_parts($text);
        $this->assertEmpty($parts);
    }

    // ---- build_slot_answers ----

    public function test_build_slot_answers_correct_and_alternates(): void {
        $parser = $this->make_parser();
        $parts  = $parser->extract_sub_parts(
            '{1:SHORTANSWER:=9/100#Excellent~=0.09#Excellent~=nine hundredths#Excellent}'
        );
        $slot_answers = $parser->build_slot_answers($parts);

        $this->assertCount(1, $slot_answers);
        $this->assertSame(0, $slot_answers[0]['slot_index']);
        $this->assertSame('9/100', $slot_answers[0]['correct']);
        // Alternates land in distractors so generator can surface them as options.
        $this->assertContains('0.09', $slot_answers[0]['distractors']);
        $this->assertContains('nine hundredths', $slot_answers[0]['distractors']);
    }

    // ---- parse_question (full) ----

    public function test_parse_question_numerical_all_fields(): void {
        $parser = $this->make_parser();
        $xml    = $this->make_xml(
            'Time 20103 Jr1',
            '<p>Calculate: {1:NUMERICAL:=15#Excellent} mins and {1:NUMERICAL:=56#Excellent} secs.</p>',
            'Time'
        );
        $path   = $this->write_tmp($xml);
        $result = $parser->parse_file($path);
        unlink($path);

        $this->assertEmpty($result['parse_errors']);
        $this->assertCount(1, $result['questions']);

        $q = $result['questions'][0];
        $this->assertSame('Time 20103 Jr1', $q['name']);
        $this->assertSame('Time', $q['category']);
        $this->assertTrue($q['all_numerical']);
        $this->assertFalse($q['has_image_refs']);
        $this->assertCount(2, $q['sub_parts']);
        $this->assertCount(2, $q['steps']);
        $this->assertCount(2, $q['slot_answers']);
        $this->assertSame('15', $q['slot_answers'][0]['correct']);
        $this->assertSame('56', $q['slot_answers'][1]['correct']);
    }

    public function test_parse_question_mixed_subtype(): void {
        $parser = $this->make_parser();
        $xml    = $this->make_xml(
            'Mixed Q',
            '<p>Mass: {1:SHORTANSWER:=Kilograms#Excellent} {1:NUMERICAL:=5:0#Correct}</p>'
        );
        $path   = $this->write_tmp($xml);
        $result = $parser->parse_file($path);
        unlink($path);

        $q = $result['questions'][0];
        $this->assertFalse($q['all_numerical']);
        $this->assertTrue($q['has_mixed_types']);
        $this->assertContains('SHORTANSWER', $q['subtypes']);
        $this->assertContains('NUMERICAL', $q['subtypes']);
    }

    public function test_parse_question_image_flagged(): void {
        $parser = $this->make_parser();
        $xml    = $this->make_xml(
            'Image Q',
            '<p><img src="@@PLUGINFILE@@/diagram.png"/>{1:NUMERICAL:=36#Correct~*#Incorrect} cm</p>'
        );
        $path   = $this->write_tmp($xml);
        $result = $parser->parse_file($path);
        unlink($path);

        $q = $result['questions'][0];
        $this->assertTrue($q['has_image_refs']);
        $this->assertNotEmpty($q['image_refs']);
    }

    public function test_parse_question_step_labels_list_markers(): void {
        $parser = $this->make_parser();
        $xml    = $this->make_xml(
            'STD4DECIMALS_35',
            '<p>Kiara had 480 marbles.</p>' .
            '<p>a) Lost to Sherry: {1:NUMERICAL:=180:0#Correct~*#Incorrect} marbles</p>' .
            '<p>b) Lost to Kelly: {1:NUMERICAL:=192:0#Correct~*#Incorrect} marbles</p>' .
            '<p>c) She kept: {1:NUMERICAL:=108:0#Correct~*#Incorrect} marbles</p>'
        );
        $path   = $this->write_tmp($xml);
        $result = $parser->parse_file($path);
        unlink($path);

        $q = $result['questions'][0];
        $this->assertCount(3, $q['steps']);
        $this->assertCount(3, $q['slot_answers']);
        $this->assertSame('180', $q['slot_answers'][0]['correct']);
        $this->assertSame('192', $q['slot_answers'][1]['correct']);
        $this->assertSame('108', $q['slot_answers'][2]['correct']);
    }

    // ---- Round-trip invariant ----

    public function test_roundtrip_no_content_loss(): void {
        // Parse a realistic multi-part question and verify all correct answers are preserved.
        $parser = $this->make_parser();
        $cases  = [
            ['=180:0#Correct~*#Incorrect', 'NUMERICAL', '180'],
            ['=9/100#Excellent~=0.09#Excellent', 'SHORTANSWER', '9/100'],
            ['~=CorrectChoice#Correct~Wrong1#Incorrect', 'MULTICHOICE', 'CorrectChoice'],
        ];

        foreach ($cases as [$answers_str, $subtype, $expected_correct]) {
            $parts = $parser->extract_sub_parts("{1:{$subtype}:{$answers_str}}");
            $this->assertCount(1, $parts, "Sub-part not found for: {$subtype}");
            $slot  = $parser->build_slot_answers($parts);
            $this->assertSame(
                $expected_correct,
                $slot[0]['correct'],
                "Correct answer lost for {$subtype}"
            );
        }
    }

    // ---- File-level error handling ----

    public function test_parse_file_missing(): void {
        $parser = $this->make_parser();
        $result = $parser->parse_file('/nonexistent/path/file.xml');
        $this->assertNotEmpty($result['parse_errors']);
        $this->assertEmpty($result['questions']);
    }

    public function test_parse_file_skips_non_cloze(): void {
        $parser = $this->make_parser();
        $xml    = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<quiz>
<question type="multichoice">
  <name><text>MC Q</text></name>
  <questiontext format="html"><text>Which?</text></questiontext>
</question>
<question type="cloze">
  <name><text>Cloze Q</text></name>
  <questiontext format="html"><text>{1:NUMERICAL:=5:0#Correct}</text></questiontext>
</question>
</quiz>
XML;
        $path   = $this->write_tmp($xml);
        $result = $parser->parse_file($path);
        unlink($path);

        $this->assertCount(1, $result['questions'], 'Only CLOZE questions should be parsed');
        $this->assertSame('Cloze Q', $result['questions'][0]['name']);
        $this->assertSame(1, $result['file_stats']['total']);
    }
}
