<?php
/**
 * TASK-PSQC-016: Math drill dry-run confidence report.
 *
 * Reads Math CLOZE source XML, runs cloze_parser + cloze_classifier (no Moodle bootstrap),
 * and outputs a confidence report for all NUMERICAL questions.
 *
 * Usage:
 *   php convert/cli/drill_dryrun.php --source=<math_questions.xml> [--output=<report.json>]
 *
 * Acceptance criteria check:
 * - ≥60% of NUMERICAL drill questions score ≥75 (threshold from TASK-PSQC-016).
 * - Review queue count recorded.
 *
 * NOTE: standalone script — no Moodle bootstrap. Uses autoloader shim.
 */

$autoload_candidates = [
    dirname(__DIR__, 4) . '/vendor/autoload.php',  // if composer in plugin
    dirname(__DIR__, 4) . '/config.php',            // fallback — handled below
];

// Standalone autoloader shim for converter classes.
spl_autoload_register(function (string $class): void {
    if (!str_starts_with($class, 'qtype_structuredsteps\\')) {
        return;
    }
    $rel = str_replace(['qtype_structuredsteps\\', '\\'], ['', '/'], $class);
    $candidates = [
        dirname(__DIR__, 2) . '/classes/' . $rel . '.php',
    ];
    foreach ($candidates as $path) {
        if (file_exists($path)) {
            require_once($path);
            return;
        }
    }
});

// Minimal MOODLE_INTERNAL guard bypass for standalone use.
if (!defined('MOODLE_INTERNAL')) {
    define('MOODLE_INTERNAL', true);
}

// Parse CLI args.
$opts = getopt('', ['source:', 'output::', 'min-confidence::']);
$source     = $opts['source'] ?? '';
$output     = $opts['output'] ?? '';
$min_conf   = (int)($opts['min-confidence'] ?? 50);

if (!$source || !file_exists($source)) {
    fwrite(STDERR, "Usage: php drill_dryrun.php --source=<math_questions.xml> [--output=<report.json>]\n");
    exit(1);
}

// Bootstrap converter classes.
$plugin_root = dirname(__DIR__, 2);
foreach (glob($plugin_root . '/classes/local/converter/*.php') as $f) {
    require_once($f);
}
require_once($plugin_root . '/classes/local/model_validator.php');
require_once($plugin_root . '/classes/local/engine_registry.php');

$parser       = new \qtype_structuredsteps\local\converter\cloze_parser();
$classifier   = new \qtype_structuredsteps\local\converter\cloze_classifier();
$deduplicator = new \qtype_structuredsteps\local\converter\cloze_deduplicator();

echo "Parsing: {$source}\n";
$parsed_result = $parser->parse_file($source);

if (!empty($parsed_result['parse_errors'])) {
    echo "Parse errors: " . count($parsed_result['parse_errors']) . "\n";
    foreach ($parsed_result['parse_errors'] as $e) {
        echo "  " . ($e['error'] ?? $e) . "\n";
    }
}

$all_qs = $parsed_result['questions'];
echo "Questions parsed: " . count($all_qs) . "\n";

// Dedup
foreach ($all_qs as &$q) {
    $q['source_file'] = basename($source);
}
unset($q);

$dedup_result = $deduplicator->deduplicate($all_qs);
$unique = $dedup_result['unique'];
echo "After dedup: " . count($unique) . " unique (" . $dedup_result['stats']['duplicates'] . " removed)\n\n";

// Classify
$report_rows = [];
$stats = [
    'total'                  => count($unique),
    'numerical'              => 0,
    'image_review'           => 0,
    'skip_non_numerical'     => 0,
    'auto_convert_eligible'  => 0,
    'review_queue'           => 0,
    'skip_low_conf'          => 0,
];

foreach ($unique as $q) {
    $cl     = $classifier->classify($q, $q['category'] ?: '');
    $status = $cl['status'];

    $row = [
        'name'       => $q['name'],
        'category'   => $q['category'],
        'subtypes'   => implode('+', array_unique($q['subtypes'] ?? ['?'])),
        'all_num'    => $q['all_numerical'] ? 'Y' : 'N',
        'sub_parts'  => count($q['sub_parts'] ?? []),
        'has_image'  => $q['has_image_refs'] ? 'Y' : 'N',
        'engine'     => $cl['engine'],
        'confidence' => $cl['confidence'],
        'status'     => $status,
        'patterns'   => implode(',', $cl['matched_patterns']),
    ];
    $report_rows[] = $row;

    if ($status === 'image_review') {
        $stats['image_review']++;
    } elseif ($status === 'skip' && in_array('non_numerical', $cl['matched_patterns'], true)) {
        $stats['skip_non_numerical']++;
    } elseif ($status === 'auto_convert_eligible') {
        $stats['numerical']++;
        $stats['auto_convert_eligible']++;
    } elseif ($status === 'review_queue') {
        $stats['numerical']++;
        $stats['review_queue']++;
    } else {
        if ($q['all_numerical']) {
            $stats['numerical']++;
        }
        $stats['skip_low_conf']++;
    }
}

// Auto-convert rate (of NUMERICAL eligible questions)
$numerical_total = $stats['numerical'];
$auto_rate = $numerical_total > 0
    ? round(100 * $stats['auto_convert_eligible'] / $numerical_total)
    : 0;
$meets_target = $auto_rate >= 60;

echo str_repeat('=', 70) . "\n";
echo "MATH DRILL DRY-RUN CONFIDENCE REPORT\n";
echo str_repeat('=', 70) . "\n";
printf("Total unique questions:      %d\n", $stats['total']);
printf("NUMERICAL (eligible):        %d\n", $stats['numerical']);
printf("Image-blocked:               %d\n", $stats['image_review']);
printf("Non-NUMERICAL (skip):        %d\n", $stats['skip_non_numerical']);
echo str_repeat('-', 70) . "\n";
printf("Auto-convert eligible (≥75): %d\n", $stats['auto_convert_eligible']);
printf("Review queue (50–74):        %d\n", $stats['review_queue']);
printf("Skip (low conf):             %d\n", $stats['skip_low_conf']);
echo str_repeat('-', 70) . "\n";
printf("Auto-convert rate:           %d%% %s\n", $auto_rate, $meets_target ? '✓ meets ≥60% target' : '✗ BELOW 60% target');
echo str_repeat('=', 70) . "\n\n";

// Per-question detail
printf("%-40s %-20s %3s %3s  %-26s  %s\n",
    'NAME', 'CATEGORY', 'IMG', 'SUB', 'ENGINE', 'CONF/STATUS');
echo str_repeat('-', 110) . "\n";
foreach ($report_rows as $r) {
    printf("%-40s %-20s %3s %3d  %-26s  %3d (%s)\n",
        mb_substr($r['name'], 0, 40),
        mb_substr($r['category'], 0, 20),
        $r['has_image'],
        $r['sub_parts'],
        mb_substr($r['engine'] ?: '—', 0, 26),
        $r['confidence'],
        $r['status']
    );
}
echo "\n";

// Write report JSON
$report_data = [
    'generated_at'  => date('Y-m-d H:i:s'),
    'source'        => $source,
    'stats'         => $stats,
    'auto_rate_pct' => $auto_rate,
    'meets_target'  => $meets_target,
    'questions'     => $report_rows,
];

if ($output) {
    file_put_contents($output, json_encode($report_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    echo "Report written to: {$output}\n";
} else {
    // Write to default location
    $default_out = dirname($source) . '/drill_dryrun_report.json';
    file_put_contents($default_out, json_encode($report_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    echo "Report written to: {$default_out}\n";
}

exit($meets_target ? 0 : 2);
