<?php
// This file is part of Moodle - http://moodle.org/

/**
 * Image upload helper for qtype_structuredsteps question images.
 *
 * Uploads image files from a source directory into Moodle's question file storage,
 * creating mdl_files records so that imported questions can reference them via
 * @@PLUGINFILE@@ URLs.
 *
 * Usage:
 *   php convert/cli/upload_images.php --inventory=<csv> --topic=<name> [--dry-run]
 *   php convert/cli/upload_images.php --source-dir=<dir> --topic=<name> --categoryid=<n> [--dry-run]
 *
 * Options:
 *   --inventory=<csv>     Path to image_inventory.csv (output of TASK-IMG-001).
 *                         If supplied, uploads all 'found=yes' rows matching --topic.
 *   --source-dir=<dir>    Alternative: upload all image files in this directory.
 *   --topic=<name>        Topic name to filter inventory rows (or label output).
 *   --categoryid=<n>      Target question category ID. Used as contextid lookup.
 *                         (Default: 0 = system context)
 *   --output=<path>       Write asset mapping JSON to this file (default: stdout).
 *   --dry-run             Validate files exist; do not create mdl_files records.
 *   --help                Show this help.
 *
 * Output (JSON):
 *   {
 *     "topic": "...",
 *     "uploaded": N,
 *     "skipped_existing": N,
 *     "failed": N,
 *     "mapping": {
 *       "img_Token_Name_01": "@@PLUGINFILE@@/img_Token_Name_01.jpg",
 *       ...
 *     },
 *     "residual": [
 *       {"token": "img_...", "reason": "file_not_found", "question": "..."},
 *       ...
 *     ]
 *   }
 *
 * The mapping key is the img_* token as it appears in the source XML.
 * The mapping value is the @@PLUGINFILE@@ relative URL for use in import_question().
 *
 * @package qtype_structuredsteps
 */

define('CLI_SCRIPT', true);

$moodle_root = dirname(__DIR__, 5);
require_once($moodle_root . '/config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/filelib.php');

[$options, $unrecognised] = cli_get_params([
    'inventory'   => '',
    'source-dir'  => '',
    'topic'       => '',
    'categoryid'  => 0,
    'output'      => '',
    'dry-run'     => false,
    'help'        => false,
], [
    'h' => 'help',
    'n' => 'dry-run',
]);

if ($options['help'] || (!$options['inventory'] && !$options['source-dir'])) {
    cli_writeln("qtype_structuredsteps image upload helper\n");
    cli_writeln("Usage:");
    cli_writeln("  php convert/cli/upload_images.php --inventory=<csv> --topic=<name> [--dry-run]");
    cli_writeln("  php convert/cli/upload_images.php --source-dir=<dir> --topic=<name> --categoryid=<n> [--dry-run]\n");
    cli_writeln("Options:");
    cli_writeln("  --inventory=<csv>     Path to image_inventory.csv");
    cli_writeln("  --source-dir=<dir>    Upload all images in this directory");
    cli_writeln("  --topic=<name>        Topic filter / label");
    cli_writeln("  --categoryid=<n>      Target question category ID");
    cli_writeln("  --output=<path>       Write JSON mapping to file");
    cli_writeln("  --dry-run             Validate only, no DB writes");
    exit(0);
}

// -----------------------------------------------------------------
// Resolve context for file storage.
// Question images are stored under the question category context.
// For system-context categories (contextid=1), use system context.
// -----------------------------------------------------------------
global $DB;

$categoryid = (int)$options['categoryid'];
if ($categoryid > 0) {
    $cat = $DB->get_record('question_categories', ['id' => $categoryid], 'contextid', IGNORE_MISSING);
    $contextid = $cat ? (int)$cat->contextid : SYSCONTEXTID;
} else {
    $contextid = SYSCONTEXTID;
}

// -----------------------------------------------------------------
// Build file list to upload.
// -----------------------------------------------------------------

/** @var array<array{token:string,file_path:string,question_name:string}> */
$to_upload = [];

if ($options['inventory']) {
    $inv_path = $options['inventory'];
    if (!is_readable($inv_path)) {
        cli_error("Cannot read inventory file: $inv_path");
    }
    $fh = fopen($inv_path, 'r');
    $header = fgetcsv($fh);
    $col = array_flip($header);
    while (($row = fgetcsv($fh)) !== false) {
        if (($options['topic'] && strtolower($row[$col['topic']]) !== strtolower($options['topic']))) {
            continue;
        }
        if (($row[$col['found']] ?? '') !== 'yes') {
            continue; // skip unresolved — will appear in residual
        }
        $to_upload[] = [
            'token'         => $row[$col['img_token']],
            'file_path'     => $row[$col['file_path']],
            'question_name' => $row[$col['question_name']],
        ];
    }
    fclose($fh);
} else {
    $source_dir = rtrim($options['source-dir'], '/');
    if (!is_dir($source_dir)) {
        cli_error("Source directory not found: $source_dir");
    }
    $files = array_merge(
        glob($source_dir . '/*.png') ?: [],
        glob($source_dir . '/*.jpg') ?: [],
        glob($source_dir . '/*.jpeg') ?: [],
        glob($source_dir . '/*.gif') ?: []
    );
    foreach ($files as $fpath) {
        $base = pathinfo($fpath, PATHINFO_FILENAME);
        $to_upload[] = [
            'token'         => 'img_' . $base,
            'file_path'     => $fpath,
            'question_name' => '',
        ];
    }
}

// Deduplicate by token (keep first occurrence).
$deduped = [];
foreach ($to_upload as $item) {
    if (!isset($deduped[$item['token']])) {
        $deduped[$item['token']] = $item;
    }
}
$to_upload = array_values($deduped);

// -----------------------------------------------------------------
// Upload loop.
// -----------------------------------------------------------------

$fs = get_file_storage();
$uploaded       = 0;
$skipped        = 0;
$failed         = 0;
$mapping        = [];
$residual       = [];

$topic_label = $options['topic'] ?: 'unknown';
$dry_run     = !empty($options['dry-run']);

foreach ($to_upload as $item) {
    $token     = $item['token'];
    $file_path = $item['file_path'];

    if (!is_readable($file_path)) {
        $failed++;
        $residual[] = [
            'token'    => $token,
            'reason'   => 'file_not_found',
            'question' => $item['question_name'],
            'path'     => $file_path,
        ];
        continue;
    }

    $ext      = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
    $filename = $token . '.' . $ext;
    $mimetype = mimeinfo('type', $filename);

    // @@PLUGINFILE@@ relative path for this image.
    $pluginfile_url = '@@PLUGINFILE@@/' . $filename;
    $mapping[$token] = $pluginfile_url;

    if ($dry_run) {
        $uploaded++;
        continue;
    }

    // Check for existing file record (idempotency).
    $existing = $fs->get_file(
        $contextid,
        'question',
        'questiontext',
        0,     // itemid = 0 (pre-staged; will be linked at import time)
        '/',
        $filename
    );

    if ($existing) {
        $skipped++;
        continue;
    }

    // Create new file record from local path.
    $file_record = [
        'contextid' => $contextid,
        'component' => 'question',
        'filearea'  => 'questiontext',
        'itemid'    => 0,
        'filepath'  => '/',
        'filename'  => $filename,
        'mimetype'  => $mimetype,
        'userid'    => 0,
        'timecreated'  => time(),
        'timemodified' => time(),
        'source'    => 'qtype_structuredsteps_import',
    ];

    try {
        $fs->create_file_from_pathname($file_record, $file_path);
        $uploaded++;
    } catch (\Throwable $e) {
        $failed++;
        $residual[] = [
            'token'    => $token,
            'reason'   => 'upload_failed: ' . $e->getMessage(),
            'question' => $item['question_name'],
            'path'     => $file_path,
        ];
        unset($mapping[$token]);
    }
}

// -----------------------------------------------------------------
// Output JSON report.
// -----------------------------------------------------------------

$report = [
    'topic'            => $topic_label,
    'contextid'        => $contextid,
    'categoryid'       => $categoryid,
    'dry_run'          => $dry_run,
    'uploaded'         => $uploaded,
    'skipped_existing' => $skipped,
    'failed'           => $failed,
    'total_processed'  => count($to_upload),
    'mapping'          => $mapping,
    'residual'         => $residual,
];

$json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

if ($options['output']) {
    file_put_contents($options['output'], $json);
    cli_writeln("Mapping written to: " . $options['output']);
    cli_writeln(sprintf(
        "Summary: %d uploaded, %d skipped (existing), %d failed, %d total",
        $uploaded, $skipped, $failed, count($to_upload)
    ));
} else {
    echo $json . "\n";
}

exit($failed > 0 ? 1 : 0);
