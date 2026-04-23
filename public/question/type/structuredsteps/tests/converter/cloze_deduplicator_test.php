<?php
// This file is part of Moodle - http://moodle.org/

/**
 * Tests for qtype_structuredsteps cloze_deduplicator.
 *
 * Covers TASK-PSQC-011 acceptance criteria:
 * - Dedup step produces job_<id>_dedup.jsonl listing all merged duplicates.
 * - Count of unique questions after dedup matches expected unique count.
 * - Name-exact deduplication works.
 * - Content-hash deduplication works (same text, different weights/formatting).
 * - Different names + different content = both kept.
 * - JSONL log is written with correct entries.
 *
 * @package qtype_structuredsteps
 */

defined('MOODLE_INTERNAL') || die();

/**
 * CLOZE deduplicator unit tests.
 */
class qtype_structuredsteps_cloze_deduplicator_test extends basic_testcase {

    private function make_deduplicator(): \qtype_structuredsteps\local\converter\cloze_deduplicator {
        return new \qtype_structuredsteps\local\converter\cloze_deduplicator();
    }

    private function make_q(string $name, string $raw_text, string $file = 'test.xml'): array {
        return [
            'name'        => $name,
            'raw_text'    => $raw_text,
            'source_file' => $file,
            'category'    => '',
        ];
    }

    // ── Name-exact deduplication ─────────────────────────────────────────────────

    public function test_name_exact_duplicate_removed(): void {
        $dedup = $this->make_deduplicator();
        $qs = [
            $this->make_q('Time 20103 Jr1', '<p>How many minutes? {1:NUMERICAL:=60:0}</p>', 'file1.xml'),
            $this->make_q('Time 20103 Jr1', '<p>Different text? {1:NUMERICAL:=60:0}</p>', 'file2.xml'),
        ];

        $result = $dedup->deduplicate($qs);

        $this->assertCount(1, $result['unique']);
        $this->assertCount(1, $result['duplicates']);
        $this->assertSame('Time 20103 Jr1', $result['unique'][0]['name']);
        $this->assertSame('name_exact', $result['duplicates'][0]['reason']);
        $this->assertSame('file1.xml', $result['duplicates'][0]['kept']['file']);
        $this->assertSame('file2.xml', $result['duplicates'][0]['discarded']['file']);
    }

    public function test_name_case_insensitive_dedup(): void {
        $dedup = $this->make_deduplicator();
        $qs = [
            $this->make_q('Time 20103 jr1', '<p>A {1:NUMERICAL:=5:0}</p>'),
            $this->make_q('TIME 20103 JR1', '<p>B {1:NUMERICAL:=5:0}</p>'),
        ];

        $result = $dedup->deduplicate($qs);
        $this->assertCount(1, $result['unique']);
        $this->assertSame(1, $result['stats']['duplicates']);
    }

    // ── Content-hash deduplication ───────────────────────────────────────────────

    public function test_content_hash_duplicate_removed(): void {
        // Same question text but different CLOZE weights — should hash to same value.
        $dedup = $this->make_deduplicator();
        $qs = [
            $this->make_q('Q-A', '<p>How many minutes in an hour? {1:NUMERICAL:=60:0#Correct}</p>', 'file1.xml'),
            $this->make_q('Q-B', '<p>How many minutes in an hour? {2:NUMERICAL:=60:0#Correct~*#Wrong}</p>', 'file2.xml'),
        ];

        $result = $dedup->deduplicate($qs);

        $this->assertCount(1, $result['unique']);
        $this->assertSame('Q-A', $result['unique'][0]['name'], 'First question should be kept');
        $this->assertSame('content_hash', $result['duplicates'][0]['reason']);
    }

    public function test_content_hash_html_formatting_ignored(): void {
        // Same question with different HTML wrappers → same hash.
        $dedup = $this->make_deduplicator();
        $qs = [
            $this->make_q('Q1', '<p>Calculate the area. {1:NUMERICAL:=36:0}</p>'),
            $this->make_q('Q2', '<div><p>Calculate the area. {1:NUMERICAL:=36:0}</p></div>'),
        ];

        $result = $dedup->deduplicate($qs);
        $this->assertCount(1, $result['unique']);
    }

    // ── Unique questions kept ─────────────────────────────────────────────────────

    public function test_different_questions_both_kept(): void {
        $dedup = $this->make_deduplicator();
        $qs = [
            $this->make_q('Time Q1', '<p>Minutes in an hour? {1:NUMERICAL:=60:0}</p>'),
            $this->make_q('Time Q2', '<p>Seconds in a minute? {1:NUMERICAL:=60:0}</p>'),
            $this->make_q('Area Q1', '<p>Area of a 4×5 rectangle? {1:NUMERICAL:=20:0}</p>'),
        ];

        $result = $dedup->deduplicate($qs);

        $this->assertCount(3, $result['unique']);
        $this->assertCount(0, $result['duplicates']);
        $this->assertSame(3, $result['stats']['unique']);
        $this->assertSame(0, $result['stats']['duplicates']);
    }

    // ── Multiple duplicates ───────────────────────────────────────────────────────

    public function test_three_duplicates_of_same_question(): void {
        $dedup = $this->make_deduplicator();
        $text  = '<p>How many seconds? {1:NUMERICAL:=60:0}</p>';
        $qs = [
            $this->make_q('Time 001', $text, 'a.xml'),
            $this->make_q('Time 001', $text, 'b.xml'),
            $this->make_q('Time 001', $text, 'c.xml'),
        ];

        $result = $dedup->deduplicate($qs);

        $this->assertCount(1, $result['unique']);
        $this->assertCount(2, $result['duplicates']);
        $this->assertSame(3, $result['stats']['input']);
        $this->assertSame(1, $result['stats']['unique']);
        $this->assertSame(2, $result['stats']['duplicates']);
    }

    // ── JSONL log output ─────────────────────────────────────────────────────────

    public function test_jsonl_log_written_with_duplicate_entries(): void {
        $dedup    = $this->make_deduplicator();
        $log_path = tempnam(sys_get_temp_dir(), 'dedup_') . '.jsonl';
        $qs = [
            $this->make_q('Dup Q', '<p>Area? {1:NUMERICAL:=36:0}</p>', 'file1.xml'),
            $this->make_q('Dup Q', '<p>Area? {1:NUMERICAL:=36:0}</p>', 'file2.xml'),
        ];

        $dedup->deduplicate($qs, $log_path);

        $this->assertFileExists($log_path);
        $lines = array_filter(explode("\n", trim(file_get_contents($log_path))));
        $this->assertGreaterThanOrEqual(1, count($lines));

        // First line should be the duplicate record.
        $first = json_decode($lines[0], true);
        $this->assertSame('duplicate', $first['type']);
        $this->assertSame('Dup Q', $first['kept']['name']);
        $this->assertSame('Dup Q', $first['discarded']['name']);
        $this->assertSame('file1.xml', $first['kept']['file']);
        $this->assertSame('file2.xml', $first['discarded']['file']);

        // Last line should be the summary.
        $last = json_decode(end($lines), true);
        $this->assertSame('summary', $last['type']);
        $this->assertSame(1, $last['stats']['duplicates']);

        unlink($log_path);
    }

    public function test_jsonl_log_written_even_with_no_duplicates(): void {
        $dedup    = $this->make_deduplicator();
        $log_path = tempnam(sys_get_temp_dir(), 'dedup_') . '.jsonl';
        $qs       = [
            $this->make_q('Unique Q1', '<p>A {1:NUMERICAL:=5:0}</p>'),
        ];

        $dedup->deduplicate($qs, $log_path);

        $this->assertFileExists($log_path);
        $contents = trim(file_get_contents($log_path));
        $summary  = json_decode($contents, true);
        $this->assertSame('summary', $summary['type']);
        $this->assertSame(0, $summary['stats']['duplicates']);
        $this->assertSame(1, $summary['stats']['unique']);

        unlink($log_path);
    }

    // ── content_hash helper is public ────────────────────────────────────────────

    public function test_content_hash_is_deterministic(): void {
        $dedup = $this->make_deduplicator();
        $text  = '<p>Calculate: {1:NUMERICAL:=15:0}</p>';
        $this->assertSame($dedup->content_hash($text), $dedup->content_hash($text));
    }

    public function test_content_hash_differs_for_different_text(): void {
        $dedup = $this->make_deduplicator();
        $h1 = $dedup->content_hash('<p>Minutes in an hour?</p>');
        $h2 = $dedup->content_hash('<p>Seconds in a minute?</p>');
        $this->assertNotSame($h1, $h2);
    }

    // ── Empty input ───────────────────────────────────────────────────────────────

    public function test_empty_input_returns_empty_unique(): void {
        $dedup  = $this->make_deduplicator();
        $result = $dedup->deduplicate([]);
        $this->assertEmpty($result['unique']);
        $this->assertEmpty($result['duplicates']);
        $this->assertSame(0, $result['stats']['input']);
    }
}
