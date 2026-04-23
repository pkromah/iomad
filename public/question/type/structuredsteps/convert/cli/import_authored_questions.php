<?php
// This file is part of Moodle - http://moodle.org/

/**
 * Import authored CSEC question JSON files into the Moodle question bank.
 *
 * Reads a manifest.json from the given directory and imports each listed
 * question file as a qtype_structuredsteps question.
 *
 * Authored question JSON format:
 *   {
 *     "question_name":  "...",
 *     "question_text":  "<p>...</p>",
 *     "default_mark":   N,
 *     "curriculum_tag": "CSEC/InformationTechnology/2023/IT1.1",
 *     "source":         "CSEC IT May/June 2025 Paper 2 Q1(a)(i)",
 *     "engine":         "evidence_table|step_calculation|...",
 *     "model_json":     { ... engine model object ... }
 *   }
 *
 * Usage:
 *   php convert/cli/import_authored_questions.php \
 *       --dir=<path/to/manifest_directory> \
 *       [--categoryid=<id>]   Target question category ID (default: system default)
 *       [--dry-run]           Parse only, no DB writes
 *       [--force]             Re-import if question name already exists
 *       [--report=<path>]     Write JSON report to file
 *       [--help]
 *
 * @package qtype_structuredsteps
 */

define('CLI_SCRIPT', true);

$moodle_root = dirname(__DIR__, 5);
require_once($moodle_root . '/config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/questionlib.php');

[$options, $unrecognised] = cli_get_params([
    'dir'        => '',
    'categoryid' => 0,
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
        "Import authored CSEC question JSON files\n\n" .
        "  --dir=<path>        Directory containing manifest.json + question JSON files\n" .
        "  --categoryid=<id>   Target question category ID (default: system default)\n" .
        "  --dry-run           Parse and validate, no DB writes\n" .
        "  --force             Re-import even if question name already exists\n" .
        "  --report=<path>     Write JSON report to file\n" .
        "  --help              This message\n"
    );
    exit($unrecognised ? 1 : 0);
}

$dir = rtrim((string)$options['dir'], '/');
if ($dir === '') {
    cli_error("--dir is required. Pass the absolute path to the manifest directory.");
}

$manifest_path = $dir . '/manifest.json';
if (!file_exists($manifest_path)) {
    cli_error("manifest.json not found in: {$dir}");
}

$manifest_raw = file_get_contents($manifest_path);
$manifest     = json_decode($manifest_raw, true);
if (!is_array($manifest) || empty($manifest['questions'])) {
    cli_error("manifest.json is invalid or has no 'questions' list.");
}

$categoryid = (int)$options['categoryid'];
$dry_run    = (bool)$options['dry-run'];
$force      = (bool)$options['force'];

$subject   = (string)($manifest['subject']   ?? 'Unknown');
$framework = (string)($manifest['framework'] ?? 'CSEC');
$edition   = (string)($manifest['edition']   ?? '');
$cat_name  = (string)($manifest['question_category'] ?? "{$framework} {$subject} — Authored");

cli_writeln("Subject  : {$subject}");
cli_writeln("Framework: {$framework} {$edition}");
cli_writeln("Category : {$cat_name}");
cli_writeln("Files    : " . count($manifest['questions']));
cli_writeln("dry-run  : " . ($dry_run ? 'yes' : 'no'));
cli_writeln("");

// Resolve or create the target question category.
if (!$dry_run && $categoryid <= 0) {
    $categoryid = resolve_question_category($cat_name);
    cli_writeln("Category ID: {$categoryid}");
}

$orchestrator = new \qtype_structuredsteps\local\converter\job_orchestrator();
$job_id = 'authored_import_' . date('Ymd_His');

$results = [];
$counts  = ['imported' => 0, 'skipped_duplicate' => 0, 'failed' => 0, 'dry_run' => 0];

foreach ($manifest['questions'] as $filename) {
    $filepath = $dir . '/' . $filename;

    if (!file_exists($filepath)) {
        $results[] = ['file' => $filename, 'status' => 'failed', 'error' => 'File not found'];
        $counts['failed']++;
        cli_writeln("[ERROR] {$filename}: file not found");
        continue;
    }

    $raw = file_get_contents($filepath);
    $def = json_decode($raw, true);
    if (!is_array($def)) {
        $results[] = ['file' => $filename, 'status' => 'failed', 'error' => 'Invalid JSON'];
        $counts['failed']++;
        cli_writeln("[ERROR] {$filename}: invalid JSON");
        continue;
    }

    $qname   = (string)($def['question_name'] ?? '');
    $qtext   = (string)($def['question_text'] ?? '');
    $defmark = isset($def['default_mark']) ? (float)$def['default_mark'] : 1.0;
    $engine  = (string)($def['engine'] ?? '');
    $tag     = (string)($def['curriculum_tag'] ?? '');

    if ($qname === '') {
        $results[] = ['file' => $filename, 'status' => 'failed', 'error' => 'Missing question_name'];
        $counts['failed']++;
        cli_writeln("[ERROR] {$filename}: missing question_name");
        continue;
    }

    if (!isset($def['model_json']) || !is_array($def['model_json'])) {
        $results[] = ['file' => $filename, 'status' => 'failed', 'error' => 'Missing or invalid model_json'];
        $counts['failed']++;
        cli_writeln("[ERROR] {$filename}: missing or invalid model_json");
        continue;
    }

    // Inject the engine key into model_json if absent (should match top-level engine field).
    if (empty($def['model_json']['engine']) && $engine !== '') {
        $def['model_json']['engine'] = $engine;
    }
    $model_json_str = json_encode($def['model_json'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($dry_run) {
        $results[] = [
            'file'    => $filename,
            'status'  => 'dry_run',
            'engine'  => $engine,
            'name'    => $qname,
            'marks'   => $defmark,
            'tag'     => $tag,
        ];
        $counts['dry_run']++;
        cli_writeln("[DRY-RUN] {$filename}  →  {$qname}  ({$defmark} marks, engine={$engine})");
        continue;
    }

    if ($force) {
        delete_existing_structuredsteps_question($qname, $categoryid);
    }

    $result = $orchestrator->import_question(
        $model_json_str,
        ['name' => $qname, 'raw_text' => $qtext],
        $job_id,
        $categoryid
    );

    // Override defaultmark (orchestrator hardcodes 1.0).
    if ($result['status'] === 'imported' && $result['newquestionid'] > 0 && $defmark !== 1.0) {
        global $DB;
        $DB->set_field('question', 'defaultmark', $defmark, ['id' => $result['newquestionid']]);
    }

    $result['file']   = $filename;
    $result['engine'] = $engine;
    $result['name']   = $qname;
    $result['marks']  = $defmark;
    $result['tag']    = $tag;
    $results[]        = $result;

    $status_key = $result['status'];
    $counts[$status_key] = ($counts[$status_key] ?? 0) + 1;

    $icon = $result['status'] === 'imported' ? 'OK'
          : ($result['status'] === 'skipped_duplicate' ? 'SKIP' : 'ERR');
    cli_writeln(sprintf("[%s] %s  →  id=%d  %s  (%g marks)",
        $icon, $filename, $result['newquestionid'] ?? 0, $qname, $defmark));
}

cli_writeln(sprintf(
    "\nDone. imported=%d  skipped=%d  failed=%d  dry_run=%d",
    $counts['imported'], $counts['skipped_duplicate'] ?? 0, $counts['failed'], $counts['dry_run']
));

$report_data = ['job_id' => $job_id, 'subject' => $subject, 'counts' => $counts, 'results' => $results];

if ($options['report']) {
    file_put_contents($options['report'], json_encode($report_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    cli_writeln("Report written to: {$options['report']}");
}

exit($counts['failed'] > 0 ? 1 : 0);

// ============================================================
// Helpers
// ============================================================

function resolve_question_category(string $name): int {
    global $DB;

    $system_ctx = \context_system::instance();

    $existing = $DB->get_record('question_categories', [
        'name'      => $name,
        'contextid' => $system_ctx->id,
    ]);
    if ($existing) {
        return (int)$existing->id;
    }

    // Find or create a parent "CSEC Authored" top-level category.
    $parent = $DB->get_record('question_categories', [
        'name'      => 'CSEC Authored Questions',
        'contextid' => $system_ctx->id,
    ]);
    if (!$parent) {
        $parent_rec = new \stdClass();
        $parent_rec->name        = 'CSEC Authored Questions';
        $parent_rec->contextid   = $system_ctx->id;
        $parent_rec->info        = 'Top-level category for authored CSEC questions';
        $parent_rec->infoformat  = FORMAT_HTML;
        $parent_rec->stamp       = make_unique_id_code();
        $parent_rec->parent      = 0;
        $parent_rec->sortorder   = 999;
        $parent_rec->id = (int)$DB->insert_record('question_categories', $parent_rec);
        $parent = $parent_rec;
    }

    $cat = new \stdClass();
    $cat->name        = $name;
    $cat->contextid   = $system_ctx->id;
    $cat->info        = "Authored CSEC questions: {$name}";
    $cat->infoformat  = FORMAT_HTML;
    $cat->stamp       = make_unique_id_code();
    $cat->parent      = (int)$parent->id;
    $cat->sortorder   = 999;
    return (int)$DB->insert_record('question_categories', $cat);
}

function delete_existing_structuredsteps_question(string $name, int $categoryid): void {
    global $DB;

    if ($categoryid <= 0) {
        return;
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
