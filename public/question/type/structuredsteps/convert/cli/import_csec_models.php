<?php
// This file is part of Moodle - http://moodle.org/

/**
 * CSEC JSON model → Moodle question bank import CLI.
 *
 * Walks a directory of authored JSON model files and imports each one as a
 * qtype_structuredsteps question. Handles three engine types:
 *   - StepCalculationEngine  → imported directly (model_json passed through)
 *   - EvidenceTableEngine    → imported directly (model_json passed through)
 *   - DDWTOS                 → transformed: each group becomes a `choice` field;
 *                              imported as StepCalculationEngine
 *
 * Usage:
 *   php convert/cli/import_csec_models.php \
 *       [--dir=<path>]          Absolute path to models directory (default: auto-detected)
 *       [--categoryid=<id>]     Target question category ID (default: system default)
 *       [--subject=<name>]      Filter to one subject subdirectory: biology|chemistry|physics
 *       [--dry-run]             Parse and transform, no DB writes
 *       [--force]               Re-import even if question name already exists (skip idempotency)
 *       [--report=<path>]       Write JSON summary to this file (default: stdout)
 *       [--help]                Show this help
 *
 * @package qtype_structuredsteps
 */

define('CLI_SCRIPT', true);

// Bootstrap Moodle — go up from convert/cli/ through qtype dir → moodle root.
$moodle_root = dirname(__DIR__, 5);
require_once($moodle_root . '/config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/questionlib.php');

[$options, $unrecognised] = cli_get_params([
    'dir'        => '',
    'categoryid' => 0,
    'subject'    => '',
    'dry-run'    => false,
    'force'      => false,
    'report'     => '',
    'help'       => false,
], [
    'h' => 'help',
    'n' => 'dry-run',
]);

if ($options['help'] || $unrecognised) {
    cli_writeln(
        "CSEC JSON model → Moodle question bank import\n\n" .
        "  --dir=<path>        Models directory (default: docs/specs/pclplus/csec-conversion/models)\n" .
        "  --categoryid=<id>   Target category ID (default: system default)\n" .
        "  --subject=<name>    Limit to: biology | chemistry | physics\n" .
        "  --dry-run           No DB writes\n" .
        "  --force             Re-import duplicates\n" .
        "  --report=<path>     Write JSON report to file\n" .
        "  --help              This message\n"
    );
    exit($unrecognised ? 1 : 0);
}

// Resolve models directory.
// Default: a 'csec_models' sibling of the convert/ directory (copy models there or pass --dir).
// On the host: pass the absolute host path; in Docker: docker cp the models dir first.
$plugin_root = dirname(__DIR__, 2); // plugins/qtype_structuredsteps/
$models_dir  = $options['dir'] ?: ($plugin_root . '/convert/csec_models');
if (!is_dir($models_dir)) {
    cli_error(
        "Models directory not found: {$models_dir}\n" .
        "Pass --dir=<absolute-path> or copy your models into:\n  {$models_dir}"
    );
}

$categoryid = (int)$options['categoryid'];
$dry_run    = (bool)$options['dry-run'];
$force      = (bool)$options['force'];
$subject_filter = strtolower(trim((string)$options['subject']));

// ---- Collect JSON files ----
$subjects = ['biology', 'chemistry', 'physics'];
$files    = [];
foreach ($subjects as $subject) {
    if ($subject_filter && $subject_filter !== $subject) {
        continue;
    }
    $subject_dir = $models_dir . '/' . $subject;
    if (!is_dir($subject_dir)) {
        continue;
    }
    foreach (glob($subject_dir . '/*.json') as $filepath) {
        $files[] = ['path' => $filepath, 'subject' => $subject];
    }
}

if (empty($files)) {
    cli_writeln("No JSON files found in {$models_dir}" . ($subject_filter ? "/{$subject_filter}" : ''));
    exit(0);
}

cli_writeln(sprintf("Found %d JSON files. dry-run=%s force=%s categoryid=%d",
    count($files), $dry_run ? 'yes' : 'no', $force ? 'yes' : 'no', $categoryid));

// ---- Import loop ----
$orchestrator = new \qtype_structuredsteps\local\converter\job_orchestrator();
$job_id = 'csec_import_' . date('Ymd_His');

$results = [];
$counts  = ['imported' => 0, 'skipped_duplicate' => 0, 'failed' => 0, 'dry_run' => 0];

foreach ($files as $fileinfo) {
    $filepath = $fileinfo['path'];
    $filename = basename($filepath);

    $raw = file_get_contents($filepath);
    if ($raw === false) {
        $results[] = ['file' => $filename, 'status' => 'failed', 'error' => 'Cannot read file'];
        $counts['failed']++;
        continue;
    }

    $model = json_decode($raw, true);
    if (!is_array($model)) {
        $results[] = ['file' => $filename, 'status' => 'failed', 'error' => 'Invalid JSON'];
        $counts['failed']++;
        continue;
    }

    $engine = (string)($model['engine'] ?? '');
    $qname  = (string)($model['question']['name'] ?? '');
    $qtext  = (string)($model['question']['text'] ?? '');

    if ($qname === '') {
        $results[] = ['file' => $filename, 'status' => 'failed', 'error' => 'Missing question.name'];
        $counts['failed']++;
        continue;
    }

    // ---- DDWTOS transformation ----
    if ($engine === 'DDWTOS') {
        [$model_json, $qtext, $transform_error] = transform_ddwtos_to_structuredsteps($model);
        if ($transform_error) {
            $results[] = ['file' => $filename, 'status' => 'failed', 'error' => $transform_error];
            $counts['failed']++;
            continue;
        }
    } else {
        // StepCalculationEngine / EvidenceTableEngine — pass through as-is.
        $model_json = $raw;
    }

    $parsed_q = ['name' => $qname, 'raw_text' => $qtext];

    // ---- Pre-import validation (runs in both dry-run and live mode) ----
    $decoded_for_validation = json_decode($model_json, true);
    $validation = validate_model_for_import($decoded_for_validation ?? [], $engine);
    foreach ($validation['errors'] as $err) {
        $results[] = ['file' => $filename, 'status' => 'failed', 'error' => $err];
        $counts['failed']++;
        cli_writeln("[ERROR] {$filename}: {$err}");
        continue 2;
    }
    foreach ($validation['warnings'] as $warn) {
        cli_writeln("[WARN]  {$filename}: {$warn}");
    }

    if ($dry_run) {
        $results[] = [
            'file'    => $filename,
            'status'  => 'dry_run',
            'engine'  => $engine ?: 'passthrough',
            'name'    => $qname,
        ];
        $counts['dry_run']++;
        cli_writeln("[DRY-RUN] {$filename}  →  {$qname}");
        continue;
    }

    if ($force) {
        // Delete any existing question with this name+category before importing.
        delete_existing_question($qname, $categoryid);
    }

    $result = $orchestrator->import_question($model_json, $parsed_q, $job_id, $categoryid);
    $result['file']   = $filename;
    $result['engine'] = $engine ?: 'passthrough';
    $result['name']   = $qname;
    $results[]        = $result;
    $counts[$result['status']] = ($counts[$result['status']] ?? 0) + 1;

    $icon = $result['status'] === 'imported' ? 'OK' : strtoupper($result['status']);
    cli_writeln(sprintf("[%s] %s  →  %s  (id=%d)", $icon, $filename, $qname, $result['newquestionid']));
}

// ---- Summary ----
cli_writeln(sprintf(
    "\nDone. imported=%d  duplicates_skipped=%d  failed=%d  dry_run=%d",
    $counts['imported'], $counts['skipped_duplicate'], $counts['failed'], $counts['dry_run']
));

if ($options['report']) {
    $report = ['job_id' => $job_id, 'counts' => $counts, 'results' => $results];
    file_put_contents($options['report'], json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    cli_writeln("Report written to: {$options['report']}");
} else {
    $report = ['job_id' => $job_id, 'counts' => $counts, 'results' => $results];
    cli_writeln(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

exit($counts['failed'] > 0 ? 1 : 0);

// ============================================================
// Helper: transform DDWTOS model → structuredsteps model JSON
// ============================================================

/**
 * Transform a DDWTOS engine model into a StepCalculationEngine model that
 * structuredsteps can render and grade.
 *
 * Each DDWTOS group becomes one `choice` field. The word bank options for the
 * group are mapped to the `options` dict {value: displayText}. The correct
 * option (correct: true) becomes the single `expected` value.
 *
 * Cloze HTML [[N]] slot markers are replaced with (___) in question.text so
 * the structure is visible without broken inline placeholders.
 *
 * @param array $model   Decoded DDWTOS model
 * @return array{0:string,1:string,2:string|null}  [model_json, question_text, error]
 */
function transform_ddwtos_to_structuredsteps(array $model): array {
    $ddwtos = $model['ddwtos'] ?? null;
    if (!is_array($ddwtos)) {
        return ['', '', 'DDWTOS model missing ddwtos key'];
    }

    $groups   = $ddwtos['groups']   ?? [];
    $wordbank = $ddwtos['word_bank'] ?? [];

    if (empty($groups)) {
        return ['', '', 'DDWTOS model has no groups'];
    }

    // Build word_bank lookup: group_id → [option texts]
    // word_bank entries: {text: "...", group: N} or similar.
    // Groups define their own options[] — use those directly if present.

    $steps  = [];
    $fields = [];

    // Create one step containing all blanks.
    $steps[] = [
        'id'    => 'step_blanks',
        'label' => 'Complete each blank. (' . count($groups) . ' marks)',
        'type'  => 'computational',
        'marks' => array_sum(array_column($groups, 'marks')),
    ];

    foreach ($groups as $gidx => $group) {
        $gid      = (string)($group['id'] ?? ('g' . ($gidx + 1)));
        $gmarks   = (float)($group['marks'] ?? 0);
        $goptions = $group['options'] ?? [];

        if (empty($goptions)) {
            // Fallback: filter word_bank by matching group id.
            foreach ($wordbank as $wb) {
                if ((string)($wb['group'] ?? '') === $gid || (int)($wb['group'] ?? -1) === ($gidx + 1)) {
                    $goptions[] = ['text' => $wb['text'] ?? '', 'correct' => $wb['correct'] ?? false];
                }
            }
        }

        // Collect all correct and all option texts for this group.
        $correct_texts = [];
        $opts_dict = [];
        foreach ($goptions as $opt) {
            $text = trim((string)($opt['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $opts_dict[$text] = $text;
            if (!empty($opt['correct'])) {
                $correct_texts[] = $text;
            }
        }

        // If word bank has extra options not in this group, add them (shared bank).
        foreach ($wordbank as $wb) {
            $text = trim((string)($wb['text'] ?? ''));
            if ($text !== '' && !isset($opts_dict[$text])) {
                $opts_dict[$text] = $text;
            }
        }

        if (empty($correct_texts)) {
            return ['', '', "Group {$gid}: no option marked correct: true"];
        }

        // Primary expected = first correct option.
        // Any additional correct options become expected_alternatives.
        // When alternatives exist, the slot is part of an "any-from-set" group —
        // enable unique_within_step so siblings cannot duplicate the answer.
        $expected = $correct_texts[0];
        $expected_alternatives = array_slice($correct_texts, 1);
        $has_alternatives = !empty($expected_alternatives);

        // Field label: use group label if provided, else "Blank N".
        $field_label = !empty($group['label']) ? (string)$group['label'] : "Blank {$gid}";

        $field = [
            'id'               => 'f_' . $gid,
            'step_id'          => 'step_blanks',
            'type'             => 'choice',
            'label'            => $field_label,
            'expected'         => $expected,
            'options'          => $opts_dict,
            'marks'            => $gmarks,
            'diagnostic_hint'  => (string)($group['diagnostic_hint'] ?? ''),
            'error_codes'      => $group['error_codes'] ?? new \stdClass(),
        ];

        if ($has_alternatives) {
            $field['expected_alternatives'] = $expected_alternatives;
            $field['unique_within_step']    = true;
        }

        $fields[] = $field;
    }

    // Rebuild the question text: replace [[N]] slot markers with (___).
    $qtext_raw = (string)($model['question']['text'] ?? '');
    $qtext     = preg_replace('/\[\[\d+\]\]/', '(_____)', $qtext_raw);

    // Build the transformed structuredsteps model.
    $transformed = [
        'schema_version'  => $model['schema_version'] ?? '1.0',
        'engine'          => 'StepCalculationEngine',
        'engine_version'  => '1.0',
        'metadata'        => array_merge($model['metadata'] ?? [], [
            'original_engine' => 'DDWTOS',
            'imported_as'     => 'StepCalculationEngine',
        ]),
        'question'        => [
            'name'             => $model['question']['name'] ?? '',
            'text'             => $qtext,
            'general_feedback' => $model['question']['general_feedback'] ?? '',
        ],
        'params'  => $model['params'] ?? ['partial_credit_enabled' => true, 'tolerance_mode' => 'absolute'],
        'grading' => ['mode' => 'field_sum'],
        'steps'   => $steps,
        'fields'  => $fields,
    ];

    $model_json = json_encode($transformed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($model_json === false) {
        return ['', '', 'JSON encode failed: ' . json_last_error_msg()];
    }

    return [$model_json, $qtext, null];
}

// ============================================================
// Helper: pre-import model validation
// ============================================================

/**
 * Validate a decoded model array before importing it.
 *
 * Returns structured errors (block import) and warnings (emit but continue).
 *
 * Checks:
 *  - Each choice field: `expected` must be a key in `options`
 *  - Each field: `expected` must not be empty
 *  - Sibling choice fields in the same step sharing an identical `expected` value
 *    (order-dependence signal — likely a multi-correct conversion defect)
 *  - Empty `diagnostic_hint` on any field (warning only)
 *  - Generic field label matching "^Blank \d+" pattern (warning only)
 *
 * @param array  $model   Decoded model array (may be the transformed StepCalculationEngine model).
 * @param string $engine  Original engine string for context in messages.
 * @return array{errors:list<string>,warnings:list<string>}
 */
function validate_model_for_import(array $model, string $engine): array {
    $errors   = [];
    $warnings = [];
    $fields   = $model['fields'] ?? [];

    if (empty($fields) || !is_array($fields)) {
        return ['errors' => $errors, 'warnings' => $warnings];
    }

    // Build a step → [expected values] index for duplicate-expected detection.
    $step_expected_index = []; // step_id => [field_id => expected]

    foreach ($fields as $field) {
        if (!is_array($field) || empty($field['id'])) {
            continue;
        }

        $fid      = (string)$field['id'];
        $ftype    = (string)($field['type'] ?? '');
        $expected = (string)($field['expected'] ?? '');
        $label    = (string)($field['label'] ?? '');
        $hint     = (string)($field['diagnostic_hint'] ?? '');
        $stepid   = (string)($field['step_id'] ?? '');
        $options  = $field['options'] ?? [];

        // Error: expected is empty for a graded field.
        if ($expected === '' && (float)($field['marks'] ?? 0) > 0) {
            $errors[] = "Field {$fid}: expected is empty but marks > 0 — would never score";
        }

        // Error: choice field expected value not present in options dict.
        if ($ftype === 'choice' && $expected !== '' && is_array($options)) {
            if (!array_key_exists($expected, $options)) {
                // Also check alternatives (may have been set intentionally).
                $all_valid = array_merge([$expected], (array)($field['expected_alternatives'] ?? []));
                $missing   = array_filter($all_valid, fn($v) => !array_key_exists($v, $options));
                foreach ($missing as $mv) {
                    $errors[] = "Field {$fid}: value \"{$mv}\" not in options dict — grading would always fail";
                }
            }
        }

        // Warning: empty diagnostic hint.
        if ($hint === '' && $ftype !== 'context') {
            $warnings[] = "Field {$fid}: diagnostic_hint is empty — no self-correction feedback for students";
        }

        // Warning: generic label.
        if (preg_match('/^Blank\s+\d+/i', $label)) {
            $warnings[] = "Field {$fid}: label \"{$label}\" is generic — replace with a context-specific sub-question";
        }

        // Accumulate for duplicate-expected check.
        if ($ftype === 'choice' && $stepid !== '') {
            $step_expected_index[$stepid][$fid] = $expected;
        }
    }

    // Warning: sibling choice fields in the same step share the same expected value.
    // This indicates order-dependence — a student who gives the right answer in the
    // wrong slot will be penalised. Flagged unless unique_within_step + expected_alternatives
    // are both set (which means the multi-correct pattern is intentionally handled).
    foreach ($step_expected_index as $stepid => $fid_expected_map) {
        $expected_counts = array_count_values(array_values($fid_expected_map));
        foreach ($expected_counts as $exp_val => $count) {
            if ($count < 2) {
                continue;
            }
            // Check if all duplicate-expected fields have expected_alternatives set.
            $duplicating_fields = array_keys($fid_expected_map, $exp_val);
            $all_have_alternatives = true;
            foreach ($fields as $field) {
                if (!in_array((string)($field['id'] ?? ''), $duplicating_fields, true)) {
                    continue;
                }
                if (empty($field['expected_alternatives'])) {
                    $all_have_alternatives = false;
                    break;
                }
            }
            if (!$all_have_alternatives) {
                // Suppress warning when ALL sharing fields have distinct, non-generic labels.
                // Positionally-fixed blanks (e.g. "Coefficient of CO₂", "Oxidation number of Na")
                // share an expected value by coincidence, not by interchangeability.
                $all_have_specific_labels = true;
                foreach ($fields as $field) {
                    if (!in_array((string)($field['id'] ?? ''), $duplicating_fields, true)) {
                        continue;
                    }
                    $lbl = (string)($field['label'] ?? '');
                    if ($lbl === '' || preg_match('/^Blank\s+\d+/i', $lbl)) {
                        $all_have_specific_labels = false;
                        break;
                    }
                }
                if (!$all_have_specific_labels) {
                    $fids_list = implode(', ', $duplicating_fields);
                    $warnings[] = "Step {$stepid}: fields [{$fids_list}] share expected=\"{$exp_val}\" "
                        . "— order-dependent grading. Add expected_alternatives or restructure as distinct sub-questions.";
                }
            }
        }
    }

    return ['errors' => $errors, 'warnings' => $warnings];
}

// ============================================================
// Helper: delete existing question by name+category (for --force)
// ============================================================

function delete_existing_question(string $name, int $categoryid): void {
    global $DB;

    if ($categoryid <= 0) {
        return; // Can't safely delete without category scope.
    }

    $sql = "SELECT q.id FROM {question} q
            JOIN {question_versions} qv ON qv.questionid = q.id
            JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
            WHERE q.name = :qname
            AND qbe.questioncategoryid = :catid
            AND q.qtype = 'structuredsteps'";

    $existing = $DB->get_records_sql($sql, ['qname' => mb_substr($name, 0, 255), 'catid' => $categoryid]);
    foreach ($existing as $row) {
        question_delete_question($row->id);
    }
}
