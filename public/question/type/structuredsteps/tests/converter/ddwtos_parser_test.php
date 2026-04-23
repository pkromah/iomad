<?php
// This file is part of Moodle - http://moodle.org/

/**
 * Tests for qtype_structuredsteps ddwtos_parser.
 *
 * @package   qtype_structuredsteps
 */

defined('MOODLE_INTERNAL') || die();

/**
 * DDWTOS parser tests.
 */
class qtype_structuredsteps_ddwtos_parser_test extends basic_testcase {

    private function make_parser(): \qtype_structuredsteps\local\converter\ddwtos_parser {
        return new \qtype_structuredsteps\local\converter\ddwtos_parser();
    }

    /** Build a minimal DDWTOS XML string with N steps and M dragboxes per group. */
    private function make_xml(string $name, string $qtext, array $dragboxes): string {
        $dbs = '';
        foreach ($dragboxes as [$text, $group]) {
            $dbs .= "<dragbox><text>{$text}</text><group>{$group}</group></dragbox>\n";
        }
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<quiz>
<question type="ddwtos">
  <name><text>{$name}</text></name>
  <questiontext format="html"><text><![CDATA[{$qtext}]]></text></questiontext>
  {$dbs}
</question>
</quiz>
XML;
    }

    public function test_parse_placeholder_positions_standard(): void {
        $parser = $this->make_parser();
        $xml = $this->make_xml('Q1',
            '<p>Step 1: Multiply [[1]]</p><p>Step 2: Add [[2]]</p>',
            [['39.09', 1], ['6.25', 1], ['32.8', 1], ['39.00', 1], ['6.20', 1], ['32.1', 1]]
        );

        $tmpfile = tempnam(sys_get_temp_dir(), 'ddwtos_') . '.xml';
        file_put_contents($tmpfile, $xml);
        $result = $parser->parse_file($tmpfile);
        unlink($tmpfile);

        $this->assertEmpty($result['parse_errors']);
        $this->assertCount(1, $result['questions']);

        $q = $result['questions'][0];
        $this->assertSame('Q1', $q['name']);
        $this->assertCount(2, $q['placeholders']);
        $this->assertSame(0, $q['placeholders'][0]['slot_index']);
        $this->assertSame(1, $q['placeholders'][0]['group_number']);
        $this->assertSame(1, $q['placeholders'][1]['slot_index']);
    }

    public function test_encoding_variant_normalised(): void {
        // ((N]] should be normalised to [[N]] before parsing.
        $parser = $this->make_parser();
        $xml = $this->make_xml('Q_enc',
            '<p>Step 1: ((1]]</p><p>Step 2: ((2]]</p>',
            [['A', 1], ['B', 1], ['C', 1], ['D', 1]]
        );

        $tmpfile = tempnam(sys_get_temp_dir(), 'ddwtos_') . '.xml';
        file_put_contents($tmpfile, $xml);
        $result = $parser->parse_file($tmpfile);
        unlink($tmpfile);

        $this->assertEmpty($result['parse_errors']);
        $this->assertCount(2, $result['questions'][0]['placeholders']);
    }

    public function test_correct_answers_are_first_n_dragboxes(): void {
        // For 3 slots, dragbox[0], [1], [2] are the correct answers.
        $parser = $this->make_parser();
        $xml = $this->make_xml('Q3',
            '<p>Step 1: [[1]] Step 2: [[2]] Step 3: [[3]]</p>',
            [
                ['correct_1', 1], ['correct_2', 1], ['correct_3', 1],
                ['wrong_a', 1],   ['wrong_b', 1],   ['wrong_c', 1],
            ]
        );

        $tmpfile = tempnam(sys_get_temp_dir(), 'ddwtos_') . '.xml';
        file_put_contents($tmpfile, $xml);
        $result = $parser->parse_file($tmpfile);
        unlink($tmpfile);

        $q = $result['questions'][0];
        $this->assertSame('correct_1', $q['slot_answers'][0]['correct']);
        $this->assertSame('correct_2', $q['slot_answers'][1]['correct']);
        $this->assertSame('correct_3', $q['slot_answers'][2]['correct']);

        // Distractors for slot 0 should include correct_2, correct_3, wrong_a, wrong_b, wrong_c
        $this->assertContains('wrong_a', $q['slot_answers'][0]['distractors']);
        $this->assertNotContains('correct_1', $q['slot_answers'][0]['distractors']);
    }

    public function test_step_labels_extracted(): void {
        $parser = $this->make_parser();
        $xml = $this->make_xml('Q_steps',
            '<p>Step 1: Multiply 4.71 by 8.3: [[1]]</p><p>Step 2: Find the square of 2.5: [[2]]</p><p>Step 3: Subtract: [[3]]</p>',
            [['39.09', 1], ['6.25', 1], ['32.8', 1], ['a', 1], ['b', 1], ['c', 1]]
        );

        $tmpfile = tempnam(sys_get_temp_dir(), 'ddwtos_') . '.xml';
        file_put_contents($tmpfile, $xml);
        $result = $parser->parse_file($tmpfile);
        unlink($tmpfile);

        $q = $result['questions'][0];
        $this->assertSame(3, $q['step_count']);
        $this->assertStringContainsString('Step 1', $q['steps'][0]['label']);
        $this->assertStringContainsString('Step 2', $q['steps'][1]['label']);
        $this->assertStringContainsString('Step 3', $q['steps'][2]['label']);
    }

    public function test_no_step_labels_fallback_one_per_placeholder(): void {
        $parser = $this->make_parser();
        $xml = $this->make_xml('Q_nolabels',
            '<p>Calculate: [[1]] Then: [[2]]</p>',
            [['A', 1], ['B', 1], ['C', 1], ['D', 1]]
        );

        $tmpfile = tempnam(sys_get_temp_dir(), 'ddwtos_') . '.xml';
        file_put_contents($tmpfile, $xml);
        $result = $parser->parse_file($tmpfile);
        unlink($tmpfile);

        $q = $result['questions'][0];
        $this->assertSame(2, $q['step_count']);
        $this->assertSame([0], $q['steps'][0]['slot_indices']);
        $this->assertSame([1], $q['steps'][1]['slot_indices']);
    }

    public function test_image_refs_detected(): void {
        $parser = $this->make_parser();
        $xml = $this->make_xml('Q_img',
            '<p>See diagram img_jan2013_01 below. [[1]]</p>',
            [['A', 1], ['B', 1]]
        );

        $tmpfile = tempnam(sys_get_temp_dir(), 'ddwtos_') . '.xml';
        file_put_contents($tmpfile, $xml);
        $result = $parser->parse_file($tmpfile);
        unlink($tmpfile);

        $q = $result['questions'][0];
        $this->assertTrue($q['has_image_refs']);
        $this->assertNotEmpty($q['image_refs']);
        $this->assertStringContainsString('img_jan2013_01', $q['image_refs'][0]);
    }

    public function test_missing_file_returns_parse_error(): void {
        $parser = $this->make_parser();
        $result = $parser->parse_file('/nonexistent/path/file.xml');

        $this->assertEmpty($result['questions']);
        $this->assertNotEmpty($result['parse_errors']);
        $this->assertStringContainsString('not found', $result['parse_errors'][0]['error']);
    }

    public function test_file_stats_accurate(): void {
        $parser = $this->make_parser();
        $xml = $this->make_xml('Q1', '<p>[[1]]</p>', [['A', 1], ['B', 1]]) .
               "\n<!-- extra content -->";

        $tmpfile = tempnam(sys_get_temp_dir(), 'ddwtos_') . '.xml';
        file_put_contents($tmpfile, $xml);
        $result = $parser->parse_file($tmpfile);
        unlink($tmpfile);

        $this->assertSame(1, $result['file_stats']['total']);
        $this->assertSame(1, $result['file_stats']['parsed']);
        $this->assertSame(0, $result['file_stats']['failed']);
    }
}
