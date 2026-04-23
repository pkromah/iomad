<?php
// This file is part of Moodle - http://moodle.org/

/**
 * DDWTOS/CLOZE → StructuredSteps conversion pipeline CLI.
 *
 * Usage:
 *   php convert/cli/convert.php --source=<path> [--source-format=<ddwtos|cloze>] [--subject=<math|la>]
 *       [--topic=<name>] [--tenantid=<N>] [--dry-run] [--min-confidence=75]
 *   php convert/cli/convert.php --rollback=<job_id>
 *   php convert/cli/convert.php --export-review --source=<path> --topic=<name> --output=<path>
 *   php convert/cli/convert.php --validate-schema
 *
 * Options:
 *   --source=<path>              Absolute path to XML file or directory of XML files.
 *   --source-format=<format>     Input format: ddwtos (default) or cloze.
 *   --subject=<subject>          Subject hint for CLOZE path: math or la (used as topic hint if --topic omitted).
 *   --topic=<name>               Topic hint for engine classification (e.g. "computation").
 *   --tenantid=<N>               IOMAD tenant ID for live import (required for non-dry-run).
 *   --dry-run                    Parse, classify, generate, validate — no DB writes.
 *   --min-confidence=<N>         Minimum confidence to include in auto-convert (default: 75).
 *   --report=<path>              Write JSON report to this file (default: stdout).
 *   --rollback=<job_id>          Rollback a previously imported batch by job ID.
 *   --asset-mapping=<path>       Path to asset mapping JSON from upload_images.php (enables image rewriting).
 *   --residual=<path>            Path to write residual.jsonl for questions with unresolved image tokens.
 *   --export-review              Export image-flagged review-queue questions as SME sign-off Markdown.
 *   --output=<path>              Output path for --export-review (default: stdout).
 *   --validate-schema            Validate built-in engine schema fixtures; exits 0 if all pass.
 *   --help                       Show this help.
 *
 * @package qtype_structuredsteps
 */

define('CLI_SCRIPT', true);

// Bootstrap Moodle
$moodle_root = dirname(__DIR__, 5); // plugins/qtype_structuredsteps/convert/cli → iomad root
require_once($moodle_root . '/config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognised] = cli_get_params([
    'source'          => '',
    'source-format'   => 'ddwtos',
    'subject'         => '',
    'topic'           => '',
    'tenantid'        => 0,
    'dry-run'         => false,
    'min-confidence'  => 75,
    'report'          => '',
    'rollback'        => '',
    'categoryid'      => 0,
    'asset-mapping'   => '',
    'residual'        => '',
    'export-review'   => false,
    'output'          => '',
    'validate-schema' => false,
    'approve'         => '',
    'sme-csv'         => '',
    'help'            => false,
], [
    'h' => 'help',
    'n' => 'dry-run',
]);

if ($options['help'] || (!$options['source'] && !$options['rollback'] && !$options['export-review'] && !$options['validate-schema'] && !$options['approve'])) {
    cli_writeln("DDWTOS/CLOZE → StructuredSteps conversion pipeline\n");
    cli_writeln("Usage:");
    cli_writeln("  php convert/cli/convert.php --source=<path> [--source-format=<ddwtos|cloze>]");
    cli_writeln("      [--subject=<math|la>] [--topic=<name>] [--tenantid=<N>] [--dry-run]");
    cli_writeln("  php convert/cli/convert.php --rollback=<job_id>");
    cli_writeln("  php convert/cli/convert.php --export-review --source=<path> --topic=<name> --output=<path>\n");
    cli_writeln("Options:");
    cli_writeln("  --source=<path>              XML file or directory");
    cli_writeln("  --source-format=<format>     Input format: ddwtos (default) or cloze");
    cli_writeln("  --subject=<subject>          Subject hint for CLOZE: math or la");
    cli_writeln("  --topic=<name>               Topic hint for classifier");
    cli_writeln("  --tenantid=<N>               IOMAD tenant ID (required for non-dry-run)");
    cli_writeln("  --dry-run                    Validate only, no DB writes");
    cli_writeln("  --min-confidence=<N>         Minimum confidence threshold (default: 75)");
    cli_writeln("  --report=<path>              Write JSON report to file");
    cli_writeln("  --rollback=<job_id>          Rollback a batch by job ID");
    cli_writeln("  --asset-mapping=<path>       Asset mapping JSON from upload_images.php");
    cli_writeln("  --residual=<path>            Write unresolved image token records to this .jsonl file");
    cli_writeln("  --export-review              Export image review queue as SME sign-off Markdown");
    cli_writeln("  --output=<path>              Output file for --export-review");
    cli_writeln("  --validate-schema            Validate built-in engine schema fixtures; exits 0 if all pass");
    cli_writeln("  --approve=<job_id>           Mark all clog records for job as approved; print summary");
    cli_writeln("  --sme-csv=<path>             SME review CSV; questions with disposition=approve are imported");
    exit(0);
}

// --- Validate-schema mode ---
if ($options['validate-schema']) {
    $fixture_dir = __DIR__ . '/../fixtures';
    $fixtures = [
        'AlgorithmicWorkingEngine' => $fixture_dir . '/schema_AlgorithmicWorkingEngine.json',
        'StepCalculationEngine'    => $fixture_dir . '/schema_StepCalculationEngine.json',
    ];

    $validator  = new \qtype_structuredsteps\local\model_validator();
    $errors     = 0;
    $tested     = 0;

    cli_writeln("Validating engine schema fixtures...");

    foreach ($fixtures as $engine => $fixture_path) {
        $tested++;
        if (!is_readable($fixture_path)) {
            cli_writeln("  [{$engine}] FAIL — fixture not found: {$fixture_path}");
            $errors++;
            continue;
        }

        $json  = file_get_contents($fixture_path);
        $error = $validator->validate($json, $engine);

        if ($error !== null) {
            cli_writeln("  [{$engine}] FAIL — {$error}");
            $errors++;
        } else {
            cli_writeln("  [{$engine}] OK");
        }
    }

    cli_writeln("Schema validation complete: {$tested} fixtures tested, {$errors} errors.");

    if ($errors > 0) {
        exit(1);
    }
    exit(0);
}

// --- Rollback mode ---
if ($options['rollback']) {
    $job_id = (string)$options['rollback'];
    cli_writeln("Rolling back job: {$job_id}");
    $orchestrator = new \qtype_structuredsteps\local\converter\job_orchestrator();
    $result = $orchestrator->rollback($job_id);

    if ($result['success']) {
        cli_writeln("Rollback complete.");
        cli_writeln("  Converted questions deleted: " . ($result['deleted_count'] ?? 0));
        cli_writeln("  Archived questions restored: " . ($result['restored_count'] ?? 0));
        exit(0);
    } else {
        cli_error("Rollback failed: " . ($result['error'] ?? 'unknown error'));
    }
}

// --- Approve mode ---
if ($options['approve']) {
    global $DB;
    $job_id = (string)$options['approve'];

    // Find all clog records for this job.
    $like    = $DB->sql_like('explanation', ':pat');
    $records = $DB->get_records_select(
        'qtype_structuredsteps_clog',
        $like,
        ['pat' => '%job_id=' . $job_id . '%']
    );

    if (empty($records)) {
        cli_error("No clog records found for job_id: {$job_id}");
    }

    $approved = 0;
    $already  = 0;
    foreach ($records as $rec) {
        if ($rec->status === 'approved') {
            $already++;
            continue;
        }
        $DB->set_field('qtype_structuredsteps_clog', 'status', 'approved', ['id' => $rec->id]);
        $approved++;
    }

    $total = count($records);
    cli_writeln("Approval gate — job: {$job_id}");
    cli_writeln("  Records found:    {$total}");
    cli_writeln("  Newly approved:   {$approved}");
    cli_writeln("  Already approved: {$already}");
    cli_writeln("Approval complete. Job status updated to 'approved' in clog.");
    exit(0);
}

// --- Export-review mode ---
if ($options['export-review']) {
    if (!$options['source']) {
        cli_error("--export-review requires --source");
    }

    $source      = (string)$options['source'];
    $topic       = (string)($options['topic'] ?: 'unknown');
    $output_path = (string)$options['output'];

    // Collect XML files (same logic as conversion mode).
    $xml_files = [];
    if (is_file($source)) {
        $xml_files[] = $source;
    } elseif (is_dir($source)) {
        $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source));
        foreach ($iter as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === 'xml') {
                $xml_files[] = $file->getRealPath();
            }
        }
    } else {
        cli_error("Source not found: {$source}");
    }

    $parser     = new \qtype_structuredsteps\local\converter\ddwtos_parser();
    $classifier = new \qtype_structuredsteps\local\converter\ddwtos_classifier();
    $generator  = new \qtype_structuredsteps\local\converter\ddwtos_generator();

    $export_date = date('Y-m-d');
    $lines = [];
    $lines[] = "# SME Review Export — {$topic}";
    $lines[] = "";
    $lines[] = "**Generated:** {$export_date}  ";
    $lines[] = "**Topic:** {$topic}  ";
    $lines[] = "**Source:** {$source}  ";
    $lines[] = "**Status:** PENDING SME SIGN-OFF";
    $lines[] = "";
    $lines[] = "---";
    $lines[] = "";
    $lines[] = "## Instructions";
    $lines[] = "";
    $lines[] = "Review each question below. For each:";
    $lines[] = "1. Confirm the auto-extracted steps correctly reflect the question's solution path.";
    $lines[] = "2. Note any corrections in the **SME Notes** field.";
    $lines[] = "3. Set **SME Decision** to `APPROVE` or `REJECT`.";
    $lines[] = "4. Sign and date the document at the bottom once all questions are reviewed.";
    $lines[] = "";
    $lines[] = "---";
    $lines[] = "";

    $q_count    = 0;
    $img_count  = 0;

    foreach ($xml_files as $xmlfile) {
        $file_topic   = $topic ?: basename($xmlfile);
        $parsed_result = $parser->parse_file($xmlfile);

        foreach ($parsed_result['questions'] as $q_idx => $parsed_q) {
            $qname    = $parsed_q['name'] ?? "Q{$q_idx}";
            $raw_text = (string)($parsed_q['raw_text'] ?? '');
            $q_count++;

            // Find image tokens in question text.
            preg_match_all('/\bimg_[A-Za-z0-9_]+/', $raw_text, $img_matches);
            $img_tokens = array_unique($img_matches[0] ?? []);

            if (empty($img_tokens)) {
                continue; // only export image-flagged questions
            }

            $img_count++;
            $classification = $classifier->classify($parsed_q, $file_topic);
            $confidence = $classification['confidence'];
            $engine     = $classification['engine'];

            // Generate step preview (best-effort; may fail for complex questions).
            $step_preview = '*(generation failed)*';
            $gen_result = $generator->generate($parsed_q, $engine);
            if ($gen_result['validation_error'] === null) {
                $model = json_decode($gen_result['model_json'], true);
                $steps = $model['steps'] ?? [];
                if (!empty($steps)) {
                    $step_lines = [];
                    foreach ($steps as $i => $step) {
                        $label   = $step['label'] ?? "Step " . ($i + 1);
                        $content = strip_tags($step['content'] ?? '');
                        $fields  = count($step['fields'] ?? []);
                        $step_lines[] = "  " . ($i + 1) . ". **{$label}** — {$content}" .
                                        ($fields > 0 ? " *({$fields} field" . ($fields > 1 ? 's' : '') . ")*" : '');
                    }
                    $step_preview = implode("\n", $step_lines);
                }
            }

            $lines[] = "## Q{$img_count}: {$qname}";
            $lines[] = "";
            $lines[] = "**Confidence:** {$confidence}  ";
            $lines[] = "**Engine:** {$engine}  ";
            $lines[] = "**Source file:** " . basename($xmlfile);
            $lines[] = "";
            $lines[] = "### Question Text";
            $lines[] = "";
            // Render as plain text (strip HTML tags for readability).
            $plain_text = strip_tags(html_entity_decode($raw_text, ENT_QUOTES, 'UTF-8'));
            $lines[] = wordwrap(trim($plain_text), 100, "\n", false);
            $lines[] = "";
            $lines[] = "### Detected Images";
            $lines[] = "";
            foreach ($img_tokens as $tok) {
                $lines[] = "- `{$tok}`";
            }
            $lines[] = "";
            $lines[] = "### Auto-extracted Steps";
            $lines[] = "";
            $lines[] = $step_preview;
            $lines[] = "";
            $lines[] = "### SME Review";
            $lines[] = "";
            $lines[] = "**SME Decision:** ☐ APPROVE  ☐ REJECT  ☐ APPROVE WITH CORRECTIONS";
            $lines[] = "";
            $lines[] = "**SME Notes:**";
            $lines[] = "> *(enter notes here)*";
            $lines[] = "";
            $lines[] = "---";
            $lines[] = "";
        }
    }

    // Footer with sign-off block.
    $lines[] = "## Sign-off";
    $lines[] = "";
    $lines[] = "| Field | Value |";
    $lines[] = "|---|---|";
    $lines[] = "| Reviewed by | *(name)* |";
    $lines[] = "| Date | *(YYYY-MM-DD)* |";
    $lines[] = "| Questions reviewed | {$img_count} of {$q_count} image-flagged |";
    $lines[] = "| Approved | *(count)* |";
    $lines[] = "| Rejected | *(count)* |";
    $lines[] = "| Corrections noted | *(count)* |";
    $lines[] = "";
    $lines[] = "> By signing this document, the SME confirms that the auto-extracted steps ";
    $lines[] = "> are accurate for all APPROVED questions and that REJECTED questions ";
    $lines[] = "> require rework before import.";
    $lines[] = "";

    $content = implode("\n", $lines);

    if ($output_path) {
        file_put_contents($output_path, $content);
        cli_writeln("SME review export written to: {$output_path}");
        cli_writeln("Questions reviewed: {$img_count} image-flagged out of {$q_count} total");
    } else {
        echo $content . "\n";
    }

    exit(0);
}

// --- Conversion mode ---
$source        = (string)$options['source'];
$source_format = strtolower((string)($options['source-format'] ?: 'ddwtos'));
$subject       = strtolower((string)$options['subject']);
$topic         = (string)$options['topic'];
$dry_run       = (bool)$options['dry-run'];
$tenantid      = (int)$options['tenantid'];
$min_conf      = max(0, min(100, (int)$options['min-confidence']));
$report_path   = (string)$options['report'];
$residual_path = (string)$options['residual'];

// Validate source-format
if (!in_array($source_format, ['ddwtos', 'cloze'], true)) {
    cli_error("Invalid --source-format '{$source_format}'. Must be 'ddwtos' or 'cloze'.");
}

// Validate subject for CLOZE
if ($source_format === 'cloze' && $subject !== '' && !in_array($subject, ['math', 'la'], true)) {
    cli_error("Invalid --subject '{$subject}'. Must be 'math' or 'la' for CLOZE format.");
}

// tenantid required for live (non-dry-run) imports
if (!$dry_run && $tenantid <= 0) {
    cli_error("--tenantid=<N> is required for live import. Use --dry-run to validate without importing.");
}

// Load SME review CSV (--sme-csv): build set of approved question names.
$sme_approved_names = [];
if ($options['sme-csv']) {
    $sme_csv_path = (string)$options['sme-csv'];
    if (!is_readable($sme_csv_path)) {
        cli_error("Cannot read SME CSV: {$sme_csv_path}");
    }
    $fh = fopen($sme_csv_path, 'r');
    $header = fgetcsv($fh);  // skip header
    if ($header === false || !in_array('question_name', $header, true)) {
        cli_error("SME CSV missing 'question_name' column header: {$sme_csv_path}");
    }
    $col_name = array_search('question_name', $header, true);
    $col_disp = array_search('sme_disposition', $header, true);
    while (($row = fgetcsv($fh)) !== false) {
        if ($col_disp !== false && isset($row[$col_disp]) && strtolower(trim($row[$col_disp])) === 'approve') {
            $sme_approved_names[trim($row[$col_name])] = true;
        }
    }
    fclose($fh);
    cli_writeln("SME CSV loaded: " . count($sme_approved_names) . " approved questions from {$sme_csv_path}");
}

// Load asset mapping JSON if provided.
$asset_mapping = [];
if ($options['asset-mapping']) {
    $mapping_file = (string)$options['asset-mapping'];
    if (!is_readable($mapping_file)) {
        cli_error("Cannot read asset mapping file: {$mapping_file}");
    }
    $mapping_data = json_decode(file_get_contents($mapping_file), true);
    if (!is_array($mapping_data)) {
        cli_error("Invalid JSON in asset mapping file: {$mapping_file}");
    }
    // Support both raw {token: url} maps and the upload_images.php report format.
    $asset_mapping = isset($mapping_data['mapping']) ? $mapping_data['mapping'] : $mapping_data;
    cli_writeln("Asset mapping loaded: " . count($asset_mapping) . " entries from {$mapping_file}");
}

if ($dry_run) {
    cli_writeln("[DRY-RUN] No database writes will occur.");
}

// Collect XML files
$xml_files = [];
if (is_file($source)) {
    $xml_files[] = $source;
} elseif (is_dir($source)) {
    $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source));
    foreach ($iter as $file) {
        if ($file->isFile() && strtolower($file->getExtension()) === 'xml') {
            $xml_files[] = $file->getRealPath();
        }
    }
} else {
    cli_error("Source not found: {$source}");
}

if (empty($xml_files)) {
    cli_error("No XML files found at: {$source}");
}

cli_writeln("Source files found: " . count($xml_files));

if ($source_format === 'cloze') {
    $parser     = new \qtype_structuredsteps\local\converter\cloze_parser();
    $classifier = new \qtype_structuredsteps\local\converter\cloze_classifier();
    // Use subject as topic hint when --topic is not set
    if ($topic === '' && $subject !== '') {
        $topic = $subject;
    }
    $generator = new \qtype_structuredsteps\local\converter\cloze_generator();
    cli_writeln("Source format: CLOZE" . ($subject !== '' ? " (subject: {$subject})" : ''));
} else {
    $parser     = new \qtype_structuredsteps\local\converter\ddwtos_parser();
    $classifier = new \qtype_structuredsteps\local\converter\ddwtos_classifier();
    $generator  = new \qtype_structuredsteps\local\converter\ddwtos_generator();
    cli_writeln("Source format: DDWTOS");
}
$orchestrator = new \qtype_structuredsteps\local\converter\job_orchestrator();

$job_id     = 'job_' . date('Ymd_His') . '_' . substr(md5(uniqid('', true)), 0, 6);
$log_path   = sys_get_temp_dir() . '/' . $job_id . '_errors.jsonl';
$dedup_path = sys_get_temp_dir() . '/' . $job_id . '_dedup.jsonl';
$log_fh     = fopen($log_path, 'w');

$stats = [
    'job_id'             => $job_id,
    'dry_run'            => $dry_run,
    'source_format'      => $source_format,
    'subject'            => $subject,
    'source'             => $source,
    'topic'              => $topic,
    'tenantid'           => $tenantid,
    'min_confidence'     => $min_conf,
    'files_processed'    => 0,
    'questions_parsed'   => 0,
    'duplicates_removed' => 0,
    'auto_convert'       => 0,
    'review_queue'       => 0,
    'skipped'            => 0,
    'validation_errors'  => 0,
    'parse_errors'       => 0,
    'imported'           => 0,
    'error_log'          => $log_path,
    'dedup_log'          => $dedup_path,
];

$review_queue = [];

// ── Parse all files first, then deduplicate across the full batch ─────────────
$all_questions = [];

foreach ($xml_files as $xmlfile) {
    $stats['files_processed']++;
    $basename      = basename($xmlfile);
    $parsed_result = $parser->parse_file($xmlfile);

    foreach ($parsed_result['parse_errors'] as $pe) {
        $stats['parse_errors']++;
        fwrite($log_fh, json_encode(['type' => 'parse_error', 'file' => $xmlfile, 'error' => $pe['error']]) . "\n");
    }

    $file_q = count($parsed_result['questions']);
    $stats['questions_parsed'] += $file_q;
    cli_writeln("[FILE] {$basename} — parsed {$file_q} questions");

    foreach ($parsed_result['questions'] as $q) {
        $q['source_file'] = $basename;
        $all_questions[]  = $q;
    }
}

// ── Deduplication pre-step ────────────────────────────────────────────────────
if ($source_format === 'cloze' && count($all_questions) > 0) {
    $deduplicator  = new \qtype_structuredsteps\local\converter\cloze_deduplicator();
    $dedup_result  = $deduplicator->deduplicate($all_questions, $dedup_path);
    $all_questions = $dedup_result['unique'];
    $stats['duplicates_removed'] = $dedup_result['stats']['duplicates'];
    if ($stats['duplicates_removed'] > 0) {
        cli_writeln("Deduplication: removed {$stats['duplicates_removed']} duplicates → "
            . count($all_questions) . " unique questions. Log: {$dedup_path}");
    }
}

// ── Per-question classification and generation ────────────────────────────────
foreach ($all_questions as $q_idx => $parsed_q) {
    $basename   = $parsed_q['source_file'] ?? '';
    $file_topic = $topic ?: $basename;

    $qname = $parsed_q['name'] ?? "Q{$q_idx}";

    {   // scope block for local variables

        // Classify
        $classification = $classifier->classify($parsed_q, $file_topic);
        $engine     = $classification['engine'];
        $confidence = $classification['confidence'];
        $status     = $classification['status'];

        cli_write("  [{$status}:{$confidence}] {$qname} ");

        if ($status === 'skip' || $confidence < $min_conf && $status !== 'review_queue') {
            $stats['skipped']++;
            cli_writeln("→ skip");
            continue;
        }

        if ($status === 'review_queue') {
            // SME-approved names are promoted past the review queue gate.
            $sme_approved = !empty($sme_approved_names) && isset($sme_approved_names[$qname]);

            // If an asset mapping is provided and this question was routed to review queue
            // solely because of image refs, attempt import with rewriting enabled.
            $is_image_routed = !empty($asset_mapping) && (function () use ($classification): bool {
                foreach ($classification['notes'] ?? [] as $note) {
                    if (stripos($note, 'image refs detected') !== false || stripos($note, 'img_') !== false) {
                        return true;
                    }
                }
                return false;
            })();

            if (!$is_image_routed && !$sme_approved) {
                $stats['review_queue']++;
                $review_queue[] = [
                    'file'          => $basename,
                    'name'          => $qname,
                    'engine'        => $engine,
                    'confidence'    => $confidence,
                    'notes'         => $classification['notes'],
                    'patterns'      => $classification['matched_patterns'],
                ];
                cli_writeln("→ review queue");
                continue;
            }

            if ($sme_approved) {
                cli_write(" [sme-approved] ");
            }

            // Image-routed + mapping available: fall through to generate + import.
            cli_write(" [image-import] ");
        }

        // Generate JSON
        $gen_result = $generator->generate($parsed_q, $engine);

        if ($gen_result['validation_error'] !== null) {
            $stats['validation_errors']++;
            $err_entry = [
                'type'           => 'validation_error',
                'file'           => $basename,
                'question_name'  => $qname,
                'engine'         => $engine,
                'confidence'     => $confidence,
                'error'          => $gen_result['validation_error'],
            ];
            fwrite($log_fh, json_encode($err_entry) . "\n");
            cli_writeln("→ VALIDATION FAIL: " . $gen_result['validation_error']);
            continue;
        }

        cli_writeln("→ valid ({$gen_result['step_count']} steps, {$gen_result['field_count']} fields)");
        $stats['auto_convert']++;

        if (!$dry_run) {
            $import_result = $orchestrator->import_question(
                $gen_result['model_json'],
                $parsed_q,
                $job_id,
                (int)($options['categoryid'] ?? 0),
                $asset_mapping,
                $residual_path
            );
            if ($import_result['status'] === 'imported') {
                $stats['imported']++;
                cli_write(" [qid:{$import_result['newquestionid']}]");
            } elseif ($import_result['status'] === 'skipped_duplicate') {
                $stats['skipped']++;
                cli_writeln("→ skipped (duplicate)");
                continue;
            } elseif ($import_result['status'] === 'review_queue') {
                $stats['review_queue']++;
                $review_queue[] = [
                    'file'       => $basename,
                    'name'       => $qname,
                    'engine'     => $engine,
                    'confidence' => $confidence,
                    'notes'      => [$import_result['error'] ?? 'unresolved image tokens'],
                    'patterns'   => [],
                ];
                cli_writeln("→ review queue (image)");
                continue;
            } else {
                $stats['validation_errors']++;
                fwrite($log_fh, json_encode([
                    'type'          => 'import_error',
                    'file'          => $basename,
                    'question_name' => $qname,
                    'error'         => $import_result['error'],
                ]) . "\n");
                cli_writeln("→ IMPORT FAIL: " . $import_result['error']);
                continue;
            }
        }
    }
}

fclose($log_fh);

// Summary
cli_writeln("\n" . str_repeat('=', 60));
cli_writeln("Job ID:             {$job_id}");
cli_writeln("Files processed:    {$stats['files_processed']}");
cli_writeln("Questions parsed:   {$stats['questions_parsed']}");
if ($stats['duplicates_removed'] > 0) {
    cli_writeln("Duplicates removed: {$stats['duplicates_removed']}");
}
cli_writeln("Auto-convert:       {$stats['auto_convert']}");
cli_writeln("Review queue:       {$stats['review_queue']}");
cli_writeln("Skipped:            {$stats['skipped']}");
cli_writeln("Validation errors:  {$stats['validation_errors']}");
cli_writeln("Parse errors:       {$stats['parse_errors']}");
if (!$dry_run) {
    cli_writeln("Imported:           {$stats['imported']}");
}
cli_writeln("Error log:          {$log_path}");

if (!empty($review_queue)) {
    cli_writeln("\nReview queue (" . count($review_queue) . " questions):");
    foreach ($review_queue as $rq) {
        cli_writeln("  [{$rq['confidence']}] {$rq['name']} ({$rq['engine']}) — " . implode('; ', $rq['notes']));
    }
}

// Write report
$report_data = array_merge($stats, ['review_queue' => $review_queue]);
if ($report_path) {
    file_put_contents($report_path, json_encode($report_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    cli_writeln("\nReport written to: {$report_path}");
} else {
    cli_writeln("\n--- JSON REPORT ---");
    cli_writeln(json_encode($report_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

// Auto-conversion rate check
if ($stats['questions_parsed'] > 0) {
    $rate = $stats['auto_convert'] / $stats['questions_parsed'];
    if ($rate < 0.70 && !$dry_run) {
        cli_writeln("\nWARNING: Auto-conversion rate " . round($rate * 100) . "% is below the 70% target.");
        cli_writeln("         Review confidence thresholds or add more questions to review queue approval.");
    }
}

exit(0);
