<?php
// This file is part of Moodle - http://moodle.org/

namespace qtype_structuredsteps\local\converter;

defined('MOODLE_INTERNAL') || die();

/**
 * Batch deduplication pre-step for CLOZE conversion pipeline.
 *
 * Implements TASK-PSQC-011: identify and remove duplicate questions from the
 * parsed question list before conversion, producing a JSONL log of merged duplicates.
 *
 * Deduplication strategy (two-pass):
 * 1. Name-exact: questions with identical `name` fields — keep first, discard rest.
 * 2. Content-hash: questions with identical normalised question text (after stripping
 *    HTML/CLOZE markers) — keep first, discard rest.
 *
 * For each duplicate discarded, a JSONL record is written:
 *   {"type":"duplicate","kept":{"name":"...","file":"..."},"discarded":{"name":"...","file":"..."}}
 *
 * @package qtype_structuredsteps
 */
class cloze_deduplicator {

    /**
     * Deduplicate a list of parsed questions from cloze_parser.
     *
     * @param array[] $questions   Flat list: each element is the output of cloze_parser::parse_question(),
     *                              augmented with a 'source_file' key (basename) by the caller.
     * @param string  $log_path    Path to write dedup JSONL log ('' = no file written).
     * @return array{unique:array,duplicates:array,stats:array}
     */
    public function deduplicate(array $questions, string $log_path = ''): array {
        $seen_names  = [];  // name_lower => first_index
        $seen_hashes = [];  // content_hash => first_index
        $unique      = [];
        $duplicates  = [];

        foreach ($questions as $q) {
            $name    = (string)($q['name'] ?? '');
            $text    = (string)($q['raw_text'] ?? '');
            $file    = (string)($q['source_file'] ?? '');

            $name_key     = strtolower(trim($name));
            $content_hash = $this->content_hash($text);

            $dupe_of = null;
            $reason  = '';

            if ($name_key !== '' && isset($seen_names[$name_key])) {
                $dupe_of = $unique[$seen_names[$name_key]];
                $reason  = 'name_exact';
            } elseif (isset($seen_hashes[$content_hash])) {
                $dupe_of = $unique[$seen_hashes[$content_hash]];
                $reason  = 'content_hash';
            }

            if ($dupe_of !== null) {
                $duplicates[] = [
                    'type'      => 'duplicate',
                    'reason'    => $reason,
                    'kept'      => [
                        'name' => $dupe_of['name'],
                        'file' => $dupe_of['source_file'] ?? '',
                    ],
                    'discarded' => [
                        'name' => $name,
                        'file' => $file,
                    ],
                ];
            } else {
                $idx = count($unique);
                $unique[] = $q;
                if ($name_key !== '') {
                    $seen_names[$name_key] = $idx;
                }
                $seen_hashes[$content_hash] = $idx;
            }
        }

        $stats = [
            'input'      => count($questions),
            'unique'     => count($unique),
            'duplicates' => count($duplicates),
        ];

        // Write JSONL log if path provided.
        if ($log_path !== '' && !empty($duplicates)) {
            $lines = [];
            foreach ($duplicates as $d) {
                $lines[] = json_encode($d, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
            $lines[] = json_encode(['type' => 'summary', 'stats' => $stats]);
            file_put_contents($log_path, implode("\n", $lines) . "\n");
        } elseif ($log_path !== '') {
            // Write empty-run summary even when no duplicates found.
            file_put_contents(
                $log_path,
                json_encode(['type' => 'summary', 'stats' => $stats]) . "\n"
            );
        }

        return [
            'unique'     => $unique,
            'duplicates' => $duplicates,
            'stats'      => $stats,
        ];
    }

    /**
     * Compute a content hash for deduplication.
     *
     * Strips HTML tags, CLOZE syntax, and normalises whitespace before hashing.
     * This makes questions that differ only in formatting/weights/markup match.
     *
     * @param string $raw_text
     * @return string MD5 hex string
     */
    public function content_hash(string $raw_text): string {
        // Remove CLOZE sub-part markers (weights, subtypes, answers).
        $norm = preg_replace('/\{[^}]*\}/', '', $raw_text);
        // Strip HTML tags.
        $norm = preg_replace('/<[^>]+>/', ' ', $norm ?? $raw_text);
        // Decode HTML entities.
        $norm = html_entity_decode($norm ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Normalise whitespace and case.
        $norm = strtolower(trim(preg_replace('/\s+/', ' ', $norm) ?? ''));

        return md5($norm);
    }
}
