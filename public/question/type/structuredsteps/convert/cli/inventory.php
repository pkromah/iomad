#!/usr/bin/env php
<?php
declare(strict_types=1);
/**
 * Primary School CLOZE Source Inventory Scanner — TASK-PSQC-001
 *
 * Standalone script (no Moodle bootstrap). Reads Moodle XML export files,
 * deduplicates questions, classifies CLOZE sub-types, flags image dependencies,
 * and emits source_inventory.json.
 *
 * Usage:
 *   php inventory.php --math=<path> --la=<path> [--output=<path>]
 *   php inventory.php --math=<path> --la=<path> --output=primary_school/source_inventory.json
 *
 * Options:
 *   --math=<path>     Absolute path to math_questions.xml
 *   --la=<path>       Absolute path to language_arts_questions.xml
 *   --output=<path>   Write JSON to file instead of stdout
 *   --help            Show this help
 *
 * Output schema (per question entry):
 *   subject          "math" | "la"
 *   name             Question name string
 *   qtype            "cloze" | "multichoice" | "shortanswer" | etc.
 *   category         Source category name (from <question type="category">)
 *   cloze_subtype    For CLOZE: "NUMERICAL" | "SHORTANSWER" | "MULTICHOICE" | "MIXED" | "UNKNOWN" | ""
 *   sub_part_count   Number of CLOZE sub-parts (0 for non-CLOZE)
 *   has_image        True if question text contains image refs or @@PLUGINFILE@@ tokens
 *   is_duplicate     True if deduplicated (either by name or content hash)
 *   duplicate_of     Name of first occurrence (if is_duplicate)
 *   encoding_ok      True if question text is valid UTF-8 with no unexpected binary chars
 *   content_hash     MD5 of question HTML text (for content-level dedup audit)
 *   suggested_engine Engine name per D-PSQC-005 rules, or "" if not applicable / image-blocked
 *   confidence       Estimated confidence (0–100) from D-PSQC-004 rules
 *   phase1_eligible  True if confidence >= 75 and not image-blocked and all_numerical
 */

// ── Argument parsing ──────────────────────────────────────────────────────────
$opts = getopt('h', ['math:', 'la:', 'output:', 'help']);

if (isset($opts['h']) || isset($opts['help']) || empty($opts['math']) || empty($opts['la'])) {
    fwrite(STDOUT, <<<HELP
Primary School CLOZE Source Inventory Scanner

Usage:
  php inventory.php --math=<path> --la=<path> [--output=<path>]

Options:
  --math=<path>     Path to math_questions.xml
  --la=<path>       Path to language_arts_questions.xml
  --output=<path>   Write JSON to file (default: stdout)
  --help            Show this help

HELP);
    exit(isset($opts['h']) || isset($opts['help']) ? 0 : 1);
}

$math_path   = (string)$opts['math'];
$la_path     = (string)$opts['la'];
$output_path = isset($opts['output']) ? (string)$opts['output'] : '';

// ── CLOZE sub-type / image detection helpers ──────────────────────────────────

/** Moodle CLOZE sub-type shorthand → canonical name map. */
const SUBTYPE_ALIASES = [
    'MC'  => 'MULTICHOICE', 'MCS' => 'MULTICHOICE', 'MCH' => 'MULTICHOICE', 'MCV' => 'MULTICHOICE',
    // MULTICHOICE_H: horizontal-layout display variant — treated as MULTICHOICE (TASK-PSQC-002).
    'MULTICHOICE_H' => 'MULTICHOICE',
    'SAC' => 'SHORTANSWER', 'SA'  => 'SHORTANSWER',
    'NM'  => 'NUMERICAL',
    'MR'  => 'MULTIRESPONSE', 'MRH' => 'MULTIRESPONSE', 'RX' => 'MULTIRESPONSE',
    // RXC: legacy Moodle 3.x case-insensitive-correct MULTIRESPONSE shorthand (TASK-PSQC-002).
    // Pattern: {N:RXC:=answer#Correct~*#Incorrect} — single-correct with wildcard catch-all.
    'RXC' => 'MULTIRESPONSE',
];

/**
 * Parse {weight:SUBTYPE:answers} occurrences in question text.
 * Returns array of ['subtype' => string, 'answers' => string].
 */
function extract_cloze_parts(string $text): array {
    $parts = [];
    if (!preg_match_all('/\{(\d+(?:\.\d+)?):([A-Z_]+):([^}]*)\}/', $text, $m)) {
        return $parts;
    }
    for ($i = 0; $i < count($m[0]); $i++) {
        $raw = strtoupper($m[2][$i]);
        $parts[] = [
            'subtype'  => SUBTYPE_ALIASES[$raw] ?? $raw,
            'answers'  => $m[3][$i],
        ];
    }
    return $parts;
}

/**
 * Detect image references (img src, @@PLUGINFILE@@, img_word tokens).
 */
function has_image(string $text): bool {
    return (bool)(
        preg_match('/<img[^>]+>/', $text)
        || str_contains($text, '@@PLUGINFILE@@')
        || preg_match('/img_\w+/i', $text)
    );
}

/**
 * Determine aggregate CLOZE sub-type label.
 */
function classify_cloze_subtype(array $sub_parts): string {
    if (empty($sub_parts)) {
        return 'UNKNOWN';
    }
    $subtypes = array_unique(array_column($sub_parts, 'subtype'));
    if (count($subtypes) > 1) {
        return 'MIXED';
    }
    return $subtypes[0];
}

/**
 * Engine selection per D-PSQC-005.
 */
function suggest_engine(string $name, string $category, string $text): string {
    $lower = strtolower($name . ' ' . $category . ' ' . strip_tags(html_entity_decode($text)));

    // AlgorithmicWorkingEngine keywords
    $algo_keys = ['time', 'clock', 'hour', 'minute', 'second',
                  'decimal', 'fraction', 'multiply', 'divide', 'addition', 'subtraction',
                  'money', 'dollar', 'cost', 'price', 'profit', 'loss', 'sale', 'percent'];

    // StepCalculationEngine keywords
    $step_keys = ['area', 'perimeter', 'volume', 'length', 'mass', 'weight', 'capacity',
                  'angle', 'triangle', 'circle', 'polygon', 'square', 'rectangle',
                  'percent', 'ratio', 'mean', 'average', 'mode', 'median', 'statistic'];

    $algo_score = 0;
    $step_score = 0;

    foreach ($algo_keys as $kw) {
        if (str_contains($lower, $kw)) {
            $algo_score += 5;
        }
    }
    foreach ($step_keys as $kw) {
        if (str_contains($lower, $kw)) {
            $step_score += 5;
        }
    }

    if ($algo_score === 0 && $step_score === 0) {
        return '';
    }
    if ($algo_score >= $step_score) {
        return 'AlgorithmicWorkingEngine';
    }
    return 'StepCalculationEngine';
}

/**
 * Confidence score per D-PSQC-004 rules.
 * Returns 0 if image-blocked.
 */
function confidence_score(
    array $sub_parts,
    string $name,
    string $category,
    string $text,
    bool $image_blocked
): int {
    if ($image_blocked) {
        return 0;
    }

    $subtypes = array_unique(array_column($sub_parts, 'subtype'));
    $all_numerical = count($subtypes) === 1 && $subtypes[0] === 'NUMERICAL';
    $mixed = count($subtypes) > 1;

    $score = 0;

    // All sub-parts NUMERICAL: +25
    if ($all_numerical) {
        $score += 25;
    }

    // Mixed sub-types: -15
    if ($mixed) {
        $score -= 15;
    }

    // Category keyword match (D-PSQC-004 +15)
    $lower_cat = strtolower($category . ' ' . $name);
    $cat_keys  = ['time', 'decimal', 'money', 'fraction', 'percent', 'area', 'perimeter',
                  'volume', 'measurement', 'statistic', 'algebra', 'consumer'];
    foreach ($cat_keys as $kw) {
        if (str_contains($lower_cat, $kw)) {
            $score += 15;
            break;
        }
    }

    // Algorithmic keyword in text (+10)
    $plain = strtolower(strip_tags(html_entity_decode($text)));
    $text_keys = ['hour', 'minute', 'km', 'kg', 'fraction', 'decimal', 'cost', 'price',
                  'area', 'perimeter', 'angle'];
    foreach ($text_keys as $kw) {
        if (str_contains($plain, $kw)) {
            $score += 10;
            break;
        }
    }

    // Explicit step labels (+20)
    if (preg_match('/Step\s+\d+\s*:/i', $plain)) {
        $score += 20;
    }

    // No category engine hint AND no keyword: -20
    if ($score <= 0) {
        $score -= 20;
    }

    return max(0, min(100, $score));
}

// ── XML parsing helper ────────────────────────────────────────────────────────

/**
 * Parse all questions from an XML file.
 * Returns ['questions' => [...], 'errors' => [...]]
 */
function parse_questions_from_xml(string $filepath, string $subject): array {
    $questions = [];
    $errors    = [];

    if (!is_readable($filepath)) {
        return ['questions' => [], 'errors' => ["Cannot read file: $filepath"]];
    }

    $raw = file_get_contents($filepath);
    if ($raw === false) {
        return ['questions' => [], 'errors' => ["Cannot read file: $filepath"]];
    }

    $normalised = ltrim($raw, "\xEF\xBB\xBF");
    $xml_pos = strpos($normalised, '<?xml');
    if ($xml_pos !== false && $xml_pos > 0) {
        $normalised = substr($normalised, $xml_pos);
    }

    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($normalised);
    if ($xml === false) {
        $errs = libxml_get_errors();
        libxml_clear_errors();
        $msg = !empty($errs) ? trim($errs[0]->message) : 'XML error';
        return ['questions' => [], 'errors' => ["XML parse failure in $filepath: $msg"]];
    }
    libxml_clear_errors();

    $category = '';

    foreach ($xml->question as $qnode) {
        $type = (string)($qnode['type'] ?? '');

        if ($type === 'category') {
            $raw_cat  = (string)($qnode->category->text ?? '');
            $category = (string)preg_replace('#^\$[^$]+\$/+#', '', $raw_cat);
            continue;
        }

        $name = trim((string)($qnode->name->text ?? ''));
        if ($name === '') {
            continue;
        }

        // Extract question text (CDATA-wrapped HTML).
        $text_node = $qnode->questiontext->text ?? null;
        $rawtext   = $text_node !== null ? (string)$text_node : '';
        $rawtext   = (string)preg_replace('/^<!\[CDATA\[|\]\]>$/', '', trim($rawtext));

        // Encoding check: valid UTF-8 without control chars (except \t, \n, \r).
        $encoding_ok = (preg_match('//u', $rawtext) === 1)
            && !preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $rawtext);

        $content_hash = md5($rawtext);
        $image = has_image($rawtext);

        if ($type === 'cloze') {
            $sub_parts  = extract_cloze_parts($rawtext);
            $cloze_sub  = classify_cloze_subtype($sub_parts);
            $engine     = $image ? '' : suggest_engine($name, $category, $rawtext);
            $confidence = confidence_score($sub_parts, $name, $category, $rawtext, $image);
        } else {
            $sub_parts  = [];
            $cloze_sub  = '';
            $engine     = '';
            $confidence = 0;
        }

        $questions[] = [
            'subject'         => $subject,
            'name'            => $name,
            'qtype'           => $type,
            'category'        => $category,
            'cloze_subtype'   => $cloze_sub,
            'sub_part_count'  => count($sub_parts),
            'has_image'       => $image,
            'is_duplicate'    => false,   // filled in dedup pass
            'duplicate_of'    => null,
            'encoding_ok'     => $encoding_ok,
            'content_hash'    => $content_hash,
            'suggested_engine'=> $engine,
            'confidence'      => $confidence,
            'phase1_eligible'      => !$image && $confidence >= 75 && $type === 'cloze'
                                       && ($cloze_sub === 'NUMERICAL'),
            'review_queue_eligible'=> !$image && $confidence >= 35 && $type === 'cloze'
                                       && ($cloze_sub === 'NUMERICAL'),
        ];
    }

    return ['questions' => $questions, 'errors' => $errors];
}

// ── Deduplication ─────────────────────────────────────────────────────────────

/**
 * Deduplicate questions within a subject group.
 * Pass 1: by name (case-sensitive). Pass 2: by content_hash.
 */
function deduplicate(array $questions): array {
    $seen_names  = [];
    $seen_hashes = [];

    foreach ($questions as &$q) {
        $name = $q['name'];
        $hash = $q['content_hash'];

        if (isset($seen_names[$name])) {
            $q['is_duplicate'] = true;
            $q['duplicate_of'] = $seen_names[$name];
            continue;
        }

        if (isset($seen_hashes[$hash])) {
            $q['is_duplicate'] = true;
            $q['duplicate_of'] = $seen_hashes[$hash];
            continue;
        }

        $seen_names[$name]  = $name;
        $seen_hashes[$hash] = $name;
    }
    unset($q);

    return $questions;
}

// ── Main ──────────────────────────────────────────────────────────────────────

$all_errors = [];

$math_result = parse_questions_from_xml($math_path, 'math');
$la_result   = parse_questions_from_xml($la_path, 'la');

$all_errors = array_merge($all_errors, $math_result['errors'], $la_result['errors']);

$math_questions = deduplicate($math_result['questions']);
$la_questions   = deduplicate($la_result['questions']);

$all_questions = array_merge($math_questions, $la_questions);

// ── Aggregate stats ───────────────────────────────────────────────────────────

function aggregate(array $questions, string $subject): array {
    $filtered = array_filter($questions, fn ($q) => $q['subject'] === $subject);
    $unique   = array_filter($filtered, fn ($q) => !$q['is_duplicate']);

    $by_type  = [];
    $by_subtype = [];
    $image_count = 0;
    $phase1_eligible = 0;
    $review_queue_eligible = 0;
    $auto_tier = 0;
    $review_tier = 0;

    foreach ($unique as $q) {
        $by_type[$q['qtype']] = ($by_type[$q['qtype']] ?? 0) + 1;

        if ($q['qtype'] === 'cloze') {
            $st = $q['cloze_subtype'] ?: 'UNKNOWN';
            $by_subtype[$st] = ($by_subtype[$st] ?? 0) + 1;
        }

        if ($q['has_image']) {
            $image_count++;
        }

        if ($q['phase1_eligible'] ?? false) {
            $phase1_eligible++;
            if ($q['confidence'] >= 75) {
                $auto_tier++;
            } elseif ($q['confidence'] >= 50) {
                $review_tier++;
            }
        }

        if ($q['review_queue_eligible'] ?? false) {
            $review_queue_eligible++;
        }
    }

    return [
        'total_raw'             => count($filtered),
        'total_unique'          => count($unique),
        'duplicates'            => count($filtered) - count($unique),
        'by_type'               => $by_type,
        'cloze_by_subtype'      => $by_subtype,
        'image_dependent'       => $image_count,
        'phase1_eligible'       => $phase1_eligible,
        'review_queue_eligible' => $review_queue_eligible,
        'auto_tier_75'          => $auto_tier,
        'review_tier_50_74'     => $review_tier,
        'calibration_note'      => 'Max confidence observed is 50 for single-step drill questions. '
                                 . 'Threshold calibration (TASK-PSQC-017) required before bulk run.',
    ];
}

$output = [
    'generated_at' => date('Y-m-d\TH:i:sP'),
    'source_files' => [
        'math' => $math_path,
        'la'   => $la_path,
    ],
    'math_summary' => aggregate($all_questions, 'math'),
    'la_summary'   => aggregate($all_questions, 'la'),
    'errors'       => $all_errors,
    'questions'    => $all_questions,
];

$json = json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

if ($output_path) {
    $dir = dirname($output_path);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    file_put_contents($output_path, $json);
    fwrite(STDERR, "Inventory written to: {$output_path}\n");
    fwrite(STDERR, "Math: {$output['math_summary']['total_raw']} raw / {$output['math_summary']['total_unique']} unique\n");
    fwrite(STDERR, "LA:   {$output['la_summary']['total_raw']} raw / {$output['la_summary']['total_unique']} unique\n");
    fwrite(STDERR, "Math phase1_eligible (NUMERICAL, no image, conf>=75): {$output['math_summary']['phase1_eligible']}\n");
} else {
    echo $json . "\n";
}

exit(empty($all_errors) ? 0 : 1);
