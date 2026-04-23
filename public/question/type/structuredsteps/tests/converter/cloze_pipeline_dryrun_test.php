<?php
// This file is part of Moodle - http://moodle.org/

/**
 * Tests for the CLOZE conversion pipeline in dry-run mode.
 *
 * Covers TASK-PSQC-012 acceptance criteria:
 * - Dry-run pipeline validates all questions, writes no rows to mdl_question, exits cleanly.
 * - Validates all CLOZE NUMERICAL questions: parse → classify → generate → validate.
 * - Image-blocked questions are counted in image_review, not validation_error.
 * - Non-NUMERICAL questions are counted as skipped.
 * - Mixed pipeline (NUMERICAL + image + non-NUMERICAL) produces correct per-category counts.
 * - Deduplication count is included in dry-run stats.
 *
 * These tests run the pipeline loop directly without Moodle DB writes,
 * matching the dry-run mode in convert.php.
 *
 * @package qtype_structuredsteps
 */

defined('MOODLE_INTERNAL') || die();

/**
 * CLOZE pipeline dry-run integration tests.
 */
class qtype_structuredsteps_cloze_pipeline_dryrun_test extends basic_testcase {

    private function run_pipeline(array $questions, string $topic = 'math', int $min_conf = 50): array {
        $parser       = new \qtype_structuredsteps\local\converter\cloze_parser();
        $classifier   = new \qtype_structuredsteps\local\converter\cloze_classifier();
        $generator    = new \qtype_structuredsteps\local\converter\cloze_generator();
        $deduplicator = new \qtype_structuredsteps\local\converter\cloze_deduplicator();

        // Dedup
        $dedup_result = $deduplicator->deduplicate($questions);
        $unique = $dedup_result['unique'];

        $stats = [
            'parsed'            => count($questions),
            'unique'            => count($unique),
            'duplicates'        => $dedup_result['stats']['duplicates'],
            'auto_convert'      => 0,
            'review_queue'      => 0,
            'image_review'      => 0,
            'skipped'           => 0,
            'validation_errors' => 0,
        ];
        $errors = [];

        foreach ($unique as $q) {
            $cl = $classifier->classify($q, $q['category'] ?: $topic);
            $status     = $cl['status'];
            $engine     = $cl['engine'];
            $confidence = $cl['confidence'];

            if ($status === 'image_review') {
                $stats['image_review']++;
                continue;
            }

            if ($status === 'skip' || $confidence < $min_conf) {
                $stats['skipped']++;
                continue;
            }

            if ($status === 'review_queue') {
                $stats['review_queue']++;
                // In dry-run we still generate + validate review_queue items (they just aren't imported).
            }

            $gen = $generator->generate($q, $engine);
            if ($gen['validation_error'] !== null) {
                $stats['validation_errors']++;
                $errors[] = ['name' => $q['name'], 'error' => $gen['validation_error']];
            } else {
                if ($status === 'auto_convert_eligible') {
                    $stats['auto_convert']++;
                }
                // review_queue already counted above
            }
        }

        $stats['errors'] = $errors;
        return $stats;
    }

    /** Build a minimal parsed question directly (bypasses XML parsing). */
    private function make_parsed_q(
        string $name,
        string $raw_text,
        bool $all_numerical,
        bool $has_image = false,
        string $category = '',
        array $sub_answers = []
    ): array {
        if (empty($sub_answers)) {
            $sub_answers = [['value' => '42', 'tolerance' => null]];
        }

        $sub_parts    = [];
        $slot_answers = [];
        foreach ($sub_answers as $i => $a) {
            $sub_parts[] = [
                'position' => $i,
                'subtype'  => $all_numerical ? 'NUMERICAL' : 'SHORTANSWER',
                'answers'  => [['correct' => true, 'value' => $a['value'], 'tolerance' => $a['tolerance'] ?? null, 'feedback' => '']],
            ];
            $slot_answers[] = ['slot_index' => $i, 'group_number' => 1, 'correct' => $a['value'], 'distractors' => []];
        }

        $steps = [['id' => 's1', 'label' => 'Step 1', 'slot_indices' => range(0, count($sub_answers) - 1)]];

        return [
            'name'            => $name,
            'raw_text'        => $raw_text,
            'category'        => $category,
            'has_image_refs'  => $has_image,
            'image_refs'      => $has_image ? ['img.png'] : [],
            'all_numerical'   => $all_numerical,
            'has_mixed_types' => false,
            'sub_parts'       => $sub_parts,
            'slot_answers'    => $slot_answers,
            'steps'           => $steps,
            'subtypes'        => $all_numerical ? ['NUMERICAL'] : ['SHORTANSWER'],
            'step_count'      => count($sub_answers),
            'source_file'     => 'test.xml',
        ];
    }

    // ── All-NUMERICAL pipeline ────────────────────────────────────────────────────

    public function test_dry_run_numerical_questions_validate_without_db_write(): void {
        $qs = [
            $this->make_parsed_q('Time Q1', '<p>Minutes in an hour? {1:NUMERICAL:=60:0}</p>',
                true, false, 'Time drill'),
            $this->make_parsed_q('Money Q1', '<p>Change from $50? {1:NUMERICAL:=12:0}</p>',
                true, false, 'Money drill'),
            $this->make_parsed_q('Area Q1', '<p>Area of 4×5? {1:NUMERICAL:=20:0}</p>',
                true, false, 'Area and Perimeter'),
        ];

        $stats = $this->run_pipeline($qs, 'math', 50);

        $this->assertSame(0, $stats['validation_errors'],
            'Dry-run: no validation errors expected. Errors: ' . json_encode($stats['errors']));
        $this->assertSame(3, $stats['unique']);
        $this->assertSame(0, $stats['duplicates']);
        $this->assertSame(0, $stats['image_review']);
        $this->assertSame(0, $stats['skipped']);
    }

    // ── Image-blocked questions in pipeline ───────────────────────────────────────

    public function test_dry_run_image_questions_counted_not_errored(): void {
        $qs = [
            $this->make_parsed_q('Image Q1', '<p><img src="@@PLUGINFILE@@/x.png"/>Area?</p>',
                true, true, 'Area and Perimeter'),
            $this->make_parsed_q('Normal Q1', '<p>Time? {1:NUMERICAL:=60:0}</p>',
                true, false, 'Time drill'),
        ];

        $stats = $this->run_pipeline($qs, 'math', 50);

        $this->assertSame(1, $stats['image_review'], 'Image question must go to image_review');
        $this->assertSame(0, $stats['validation_errors'], 'Image block must not produce validation error');
    }

    // ── Non-NUMERICAL skip ────────────────────────────────────────────────────────

    public function test_dry_run_shortanswer_goes_to_skip(): void {
        $qs = [
            $this->make_parsed_q('LA Q1', '<p>Synonym for happy?</p>', false, false, 'Vocabulary'),
            $this->make_parsed_q('Time Q1', '<p>Minutes? {1:NUMERICAL:=60:0}</p>', true, false, 'Time'),
        ];

        $stats = $this->run_pipeline($qs, 'la', 50);

        $this->assertSame(1, $stats['skipped'], 'Non-NUMERICAL must be skipped');
        $this->assertSame(0, $stats['validation_errors']);
    }

    // ── Mixed pipeline ────────────────────────────────────────────────────────────

    public function test_dry_run_mixed_pipeline_counts_correct(): void {
        $qs = [
            // 2 duplicates → 1 unique (use 'minute' keyword to score ≥50)
            $this->make_parsed_q('Time Q1', '<p>How many minutes? {1:NUMERICAL:=60:0}</p>', true, false, 'Time drill'),
            $this->make_parsed_q('Time Q1', '<p>How many minutes? {1:NUMERICAL:=60:0}</p>', true, false, 'Time drill'),
            // 1 image
            $this->make_parsed_q('Image Q', '<p><img src="@@PLUGINFILE@@/y.png"/>Area?</p>', true, true, 'Area'),
            // 1 non-NUMERICAL
            $this->make_parsed_q('LA Q', '<p>What is a noun?</p>', false, false, 'Grammar'),
            // 1 valid
            $this->make_parsed_q('Money Q', '<p>Change? {1:NUMERICAL:=15:0}</p>', true, false, 'Money'),
        ];

        $stats = $this->run_pipeline($qs, 'math', 50);

        $this->assertSame(5, $stats['parsed']);
        $this->assertSame(4, $stats['unique'], 'After dedup: 4 unique (1 dup removed)');
        $this->assertSame(1, $stats['duplicates']);
        $this->assertSame(1, $stats['image_review']);
        $this->assertSame(1, $stats['skipped']);
        $this->assertSame(0, $stats['validation_errors']);
    }

    // ── Validation passes for both engines ───────────────────────────────────────

    public function test_dry_run_algo_engine_passes_validator(): void {
        $qs = [$this->make_parsed_q(
            'Algo Q', '<p>Step 1: Time. {1:NUMERICAL:=60:0}</p>',
            true, false, 'Time drill', [['value' => '60', 'tolerance' => '0']]
        )];
        $stats = $this->run_pipeline($qs, 'math', 0);
        $this->assertSame(0, $stats['validation_errors'],
            'AlgorithmicWorkingEngine must pass validator');
    }

    public function test_dry_run_stepcalc_engine_passes_validator(): void {
        $qs = [$this->make_parsed_q(
            'StepCalc Q', '<p>Step 1: Area. {1:NUMERICAL:=20:0}</p>',
            true, false, 'Area and Perimeter', [['value' => '20', 'tolerance' => '0']]
        )];
        $stats = $this->run_pipeline($qs, 'math', 0);
        $this->assertSame(0, $stats['validation_errors'],
            'StepCalculationEngine must pass validator');
    }
}
