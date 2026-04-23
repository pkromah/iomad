#!/usr/bin/env php
<?php
declare(strict_types=1);
/**
 * Language Arts CLOZE Feedback-Gap CSV Report — TASK-PSQC-014
 *
 * Standalone script (no Moodle bootstrap). Reads language_arts_questions.xml,
 * parses CLOZE and DDWTOS questions, deduplicates by name+hash, and emits a CSV
 * report recording feedback-gap indicators for SME remediation planning.
 *
 * Usage:
 *   php la_enhance.php --la=<path> [--output=<path>]
 *   php la_enhance.php --la=primary_school/language_arts_questions.xml \
 *       --output=docs/specs/.../data/la_feedback_gap_report.csv
 *
 * Options:
 *   --la=<path>      Path to language_arts_questions.xml
 *   --output=<path>  Write CSV to file instead of stdout
 *   --help           Show this help
 *
 * CSV columns:
 *   question_name             Name string from <name><text>
 *   category                  Source category (last <question type="category"> before this Q)
 *   qtype                     "cloze" | "ddwtos"
 *   cloze_subtype             SHORTANSWER / MULTICHOICE / MIXED / UNKNOWN / DDWTOS
 *   topic                     Derived topic label (Synonyms / Antonyms / Verbs / etc.)
 *   bloom_level_hint          SEA-aligned Bloom's taxonomy level
 *   sub_part_count            Number of CLOZE {N:TYPE:…} sub-parts (0 for DDWTOS)
 *   feedback_gap_count        Sub-parts with generic/empty per-answer feedback
 *   has_generic_feedback_only 1 if ALL feedback is generic or empty; 0 otherwise
 *   generalfeedback_empty     1 if <generalfeedback> has no authored text
 *   is_duplicate              1 if this row is a deduplicated copy
 *   remediation_priority      high / medium / low — SME action signal
 */

// ── Argument parsing ──────────────────────────────────────────────────────────
$opts = getopt('h', ['la:', 'output:', 'help']);

if (isset($opts['h']) || isset($opts['help']) || empty($opts['la'])) {
    fwrite(STDOUT, <<<HELP
Language Arts CLOZE Feedback-Gap CSV Report (TASK-PSQC-014)

Usage:
  php la_enhance.php --la=<path> [--output=<path>]

Options:
  --la=<path>      Path to language_arts_questions.xml
  --output=<path>  Write CSV to file (default: stdout)
  --help           Show this help

HELP);
    exit(isset($opts['h']) || isset($opts['help']) ? 0 : 1);
}

$la_path     = (string)$opts['la'];
$output_path = isset($opts['output']) ? (string)$opts['output'] : '';

// ── Constants ─────────────────────────────────────────────────────────────────

/** CLOZE sub-type aliases → canonical. */
const SUBTYPE_ALIASES = [
    'MC'  => 'MULTICHOICE', 'MCS' => 'MULTICHOICE', 'MCH' => 'MULTICHOICE', 'MCV' => 'MULTICHOICE',
    'SAC' => 'SHORTANSWER', 'SA'  => 'SHORTANSWER',
    'NM'  => 'NUMERICAL',
    'MR'  => 'MULTIRESPONSE', 'MRH' => 'MULTIRESPONSE', 'RX' => 'MULTIRESPONSE',
];

/** Generic feedback phrases that carry no explanatory value. */
const GENERIC_FEEDBACK_PHRASES = [
    'correct', 'incorrect', 'excellent', 'well done', 'try again',
    'good', 'wrong', 'right', 'no', 'yes', 'ok', '',
];

/**
 * Topic → Bloom's level map for SEA Language Arts.
 * Mapped to the predominant cognitive demand at SEA level.
 */
const TOPIC_BLOOM_MAP = [
    'Synonyms'               => 'Remember',
    'Antonyms'               => 'Remember',
    'Vocabulary'             => 'Understand',
    'Nouns'                  => 'Remember',
    'Pronouns'               => 'Remember',
    'Adjectives'             => 'Apply',
    'Adverbs'                => 'Apply',
    'Verbs'                  => 'Apply',
    'Verb Tenses'            => 'Apply',
    'Conjunctions'           => 'Apply',
    'Prepositions'           => 'Apply',
    'Parts of Speech'        => 'Apply',
    'Subject-Verb Agreement' => 'Apply',
    'Punctuation'            => 'Apply',
    'Spelling'               => 'Apply',
    'Active/Passive Voice'   => 'Analyse',
    'Direct/Indirect Speech' => 'Analyse',
    'Sentence Analysis'      => 'Analyse',
    'Comprehension'          => 'Evaluate',
    'Practice Tests'         => 'Apply',
    'Miscellaneous'          => 'Apply',
    'General'                => 'Apply',
];

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Derive a topic label from a category string or question name.
 */
function derive_topic(string $category, string $name): string {
    $haystack = strtolower($category . ' ' . $name);

    $keyword_map = [
        'synonym'               => 'Synonyms',
        'antonym'               => 'Antonyms',
        'vocab'                 => 'Vocabulary',
        'noun'                  => 'Nouns',
        'pronoun'               => 'Pronouns',
        'adjective'             => 'Adjectives',
        'adverb'                => 'Adverbs',
        'verb tense'            => 'Verb Tenses',
        'tense'                 => 'Verb Tenses',
        'verb'                  => 'Verbs',
        'conjunction'           => 'Conjunctions',
        'preposition'           => 'Prepositions',
        'parts of speech'       => 'Parts of Speech',
        'subject'               => 'Subject-Verb Agreement',
        'punctuation'           => 'Punctuation',
        'spelling'              => 'Spelling',
        'active'                => 'Active/Passive Voice',
        'passive'               => 'Active/Passive Voice',
        'direct'                => 'Direct/Indirect Speech',
        'indirect'              => 'Direct/Indirect Speech',
        'sentence'              => 'Sentence Analysis',
        'comprehension'         => 'Comprehension',
        'practice test'         => 'Practice Tests',
        'test'                  => 'Practice Tests',
        'miscellaneous'         => 'Miscellaneous',
        'misc'                  => 'Miscellaneous',
    ];

    foreach ($keyword_map as $needle => $topic) {
        if (str_contains($haystack, $needle)) {
            return $topic;
        }
    }

    return 'General';
}

/**
 * Parse {N:SUBTYPE:answers} sub-parts from CLOZE question text.
 * Returns array of ['subtype' => string, 'answers' => array of ['answer'=>string,'feedback'=>string]].
 */
function extract_cloze_parts(string $text): array {
    $parts = [];
    if (!preg_match_all('/\{(\d+(?:\.\d+)?):([A-Z_]+):([^}]*)\}/', $text, $m)) {
        return $parts;
    }
    for ($i = 0; $i < count($m[0]); $i++) {
        $raw_type = strtoupper($m[2][$i]);
        $subtype  = SUBTYPE_ALIASES[$raw_type] ?? $raw_type;

        // Extract per-answer pairs: =answer#feedback  or  ~answer#feedback
        $answer_pairs = [];
        preg_match_all('/[=~]([^#~}]*)(?:#([^~}]*))?/', $m[3][$i], $am);
        for ($j = 0; $j < count($am[0]); $j++) {
            $answer_pairs[] = [
                'answer'   => trim($am[1][$j]),
                'feedback' => trim($am[2][$j] ?? ''),
            ];
        }

        $parts[] = [
            'subtype' => $subtype,
            'answers' => $answer_pairs,
        ];
    }
    return $parts;
}

/**
 * Count sub-parts where feedback is missing or generic.
 */
function count_feedback_gaps(array $sub_parts): int {
    $gaps = 0;
    foreach ($sub_parts as $part) {
        $has_authored = false;
        foreach ($part['answers'] as $ap) {
            $fb_lower = strtolower($ap['feedback']);
            if ($fb_lower !== '' && !in_array($fb_lower, GENERIC_FEEDBACK_PHRASES, true)) {
                $has_authored = true;
                break;
            }
        }
        if (!$has_authored) {
            $gaps++;
        }
    }
    return $gaps;
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
 * True if generalfeedback has no meaningful authored text.
 */
function is_generalfeedback_empty(string $raw): bool {
    $stripped = strip_tags(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    return trim($stripped) === '';
}

/**
 * Determine remediation priority for SME.
 *
 * high   — ≥2 feedback gaps AND generalfeedback empty  (no feedback at all)
 * medium — 1+ feedback gaps OR generalfeedback empty
 * low    — per-answer feedback present; only generalfeedback missing
 */
function remediation_priority(int $feedback_gap_count, bool $gf_empty, int $sub_part_count): string {
    if ($feedback_gap_count >= 2 && $gf_empty) {
        return 'high';
    }
    if ($feedback_gap_count >= 1 || $gf_empty) {
        return 'medium';
    }
    return 'low';
}

// ── XML parsing ───────────────────────────────────────────────────────────────

/**
 * Parse CLOZE and DDWTOS questions from the LA XML file.
 * Returns ['questions' => [...], 'errors' => [...]]
 */
function parse_la_questions(string $filepath): array {
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
    $xml_pos    = strpos($normalised, '<?xml');
    if ($xml_pos !== false && $xml_pos > 0) {
        $normalised = substr($normalised, $xml_pos);
    }

    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($normalised);
    if ($xml === false) {
        $errs = libxml_get_errors();
        libxml_clear_errors();
        $msg = !empty($errs) ? trim($errs[0]->message) : 'XML error';
        return ['questions' => [], 'errors' => ["XML parse failure: $msg"]];
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

        // Only process CLOZE and DDWTOS.
        if ($type !== 'cloze' && $type !== 'ddwtos') {
            continue;
        }

        $name = trim((string)($qnode->name->text ?? ''));
        if ($name === '') {
            continue;
        }

        $text_node = $qnode->questiontext->text ?? null;
        $rawtext   = $text_node !== null ? (string)$text_node : '';
        $rawtext   = (string)preg_replace('/^<!\[CDATA\[|\]\]>$/', '', trim($rawtext));

        $gf_node  = $qnode->generalfeedback->text ?? null;
        $raw_gf   = $gf_node !== null ? (string)$gf_node : '';
        $raw_gf   = (string)preg_replace('/^<!\[CDATA\[|\]\]>$/', '', trim($raw_gf));

        $content_hash = md5($rawtext);
        $gf_empty     = is_generalfeedback_empty($raw_gf);

        if ($type === 'cloze') {
            $sub_parts         = extract_cloze_parts($rawtext);
            $cloze_subtype     = classify_cloze_subtype($sub_parts);
            $feedback_gap_count = count_feedback_gaps($sub_parts);
            $sub_part_count    = count($sub_parts);

            // has_generic_feedback_only: true when every sub-part has no authored feedback
            // AND generalfeedback is empty.
            $has_generic_only = ($feedback_gap_count === $sub_part_count) && $gf_empty;

        } else {
            // DDWTOS: no sub-parts; check correctfeedback / partiallycorrect / incorrectfeedback
            $sub_parts         = [];
            $sub_part_count    = 0;
            $cloze_subtype     = 'DDWTOS';

            $correctfb  = trim(strip_tags(html_entity_decode((string)($qnode->correctfeedback->text ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            $partialfb  = trim(strip_tags(html_entity_decode((string)($qnode->partiallycorrectfeedback->text ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            $incorrectfb = trim(strip_tags(html_entity_decode((string)($qnode->incorrectfeedback->text ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8')));

            $ddwtos_generic_phrases = [
                'your answer is correct.', 'your answer is partially correct.',
                'your answer is incorrect.', '',
            ];

            $all_generic = in_array(strtolower($correctfb), $ddwtos_generic_phrases, true)
                        && in_array(strtolower($partialfb), $ddwtos_generic_phrases, true)
                        && in_array(strtolower($incorrectfb), $ddwtos_generic_phrases, true);

            // DDWTOS feedback gap = 1 if feedback is all-generic (combined feedback obligation per Dim C).
            $feedback_gap_count = $all_generic ? 1 : 0;
            $has_generic_only   = $all_generic && $gf_empty;
        }

        $topic      = derive_topic($category, $name);
        $bloom      = TOPIC_BLOOM_MAP[$topic] ?? 'Apply';
        $priority   = remediation_priority($feedback_gap_count, $gf_empty, max(1, $sub_part_count));

        $questions[] = [
            'question_name'             => $name,
            'category'                  => $category,
            'qtype'                     => $type,
            'cloze_subtype'             => $cloze_subtype,
            'topic'                     => $topic,
            'bloom_level_hint'          => $bloom,
            'sub_part_count'            => $sub_part_count,
            'feedback_gap_count'        => $feedback_gap_count,
            'has_generic_feedback_only' => (int)$has_generic_only,
            'generalfeedback_empty'     => (int)$gf_empty,
            'is_duplicate'              => false,   // filled in dedup pass
            'content_hash'              => $content_hash,
            'remediation_priority'      => $priority,
        ];
    }

    return ['questions' => $questions, 'errors' => $errors];
}

// ── Deduplication ─────────────────────────────────────────────────────────────

/**
 * Mark duplicate questions by name (case-sensitive), then by content hash.
 */
function deduplicate(array $questions): array {
    $seen_names  = [];
    $seen_hashes = [];

    foreach ($questions as &$q) {
        $name = $q['question_name'];
        $hash = $q['content_hash'];

        if (isset($seen_names[$name])) {
            $q['is_duplicate'] = true;
            continue;
        }

        if (isset($seen_hashes[$hash])) {
            $q['is_duplicate'] = true;
            continue;
        }

        $seen_names[$name]  = true;
        $seen_hashes[$hash] = true;
    }
    unset($q);

    return $questions;
}

// ── CSV output ────────────────────────────────────────────────────────────────

/**
 * Write questions array as CSV rows to a file handle.
 * Only unique questions are written (is_duplicate=false).
 */
function write_csv($fh, array $questions): int {
    $cols = [
        'question_name', 'category', 'qtype', 'cloze_subtype', 'topic',
        'bloom_level_hint', 'sub_part_count', 'feedback_gap_count',
        'has_generic_feedback_only', 'generalfeedback_empty',
        'is_duplicate', 'remediation_priority',
    ];

    fputcsv($fh, $cols);

    $written = 0;
    foreach ($questions as $q) {
        $row = [];
        foreach ($cols as $col) {
            $row[] = $q[$col] ?? '';
        }
        fputcsv($fh, $row);
        $written++;
    }

    return $written;
}

// ── Main ──────────────────────────────────────────────────────────────────────

$result    = parse_la_questions($la_path);
$errors    = $result['errors'];
$questions = deduplicate($result['questions']);

$unique    = array_filter($questions, fn ($q) => !$q['is_duplicate']);
$dupes     = count($questions) - count($unique);

$total_cloze  = count(array_filter($unique, fn ($q) => $q['qtype'] === 'cloze'));
$total_ddwtos = count(array_filter($unique, fn ($q) => $q['qtype'] === 'ddwtos'));
$high_pri     = count(array_filter($unique, fn ($q) => $q['remediation_priority'] === 'high'));
$med_pri      = count(array_filter($unique, fn ($q) => $q['remediation_priority'] === 'medium'));
$low_pri      = count(array_filter($unique, fn ($q) => $q['remediation_priority'] === 'low'));
$gf_empty_cnt = count(array_filter($unique, fn ($q) => $q['generalfeedback_empty']));
$all_generic  = count(array_filter($unique, fn ($q) => $q['has_generic_feedback_only']));

fwrite(STDERR, "LA Feedback-Gap Report — la_enhance.php\n");
fwrite(STDERR, "Source:    {$la_path}\n");
fwrite(STDERR, "Parsed:    " . count($questions) . " questions (" . count($unique) . " unique, {$dupes} duplicates)\n");
fwrite(STDERR, "  CLOZE:   {$total_cloze} unique\n");
fwrite(STDERR, "  DDWTOS:  {$total_ddwtos} unique\n");
fwrite(STDERR, "Feedback gap summary (unique questions):\n");
fwrite(STDERR, "  generalfeedback empty:    {$gf_empty_cnt}\n");
fwrite(STDERR, "  all feedback generic:     {$all_generic}\n");
fwrite(STDERR, "Remediation priority:\n");
fwrite(STDERR, "  high:   {$high_pri}\n");
fwrite(STDERR, "  medium: {$med_pri}\n");
fwrite(STDERR, "  low:    {$low_pri}\n");

if (!empty($errors)) {
    fwrite(STDERR, "ERRORS:\n");
    foreach ($errors as $e) {
        fwrite(STDERR, "  - $e\n");
    }
}

if ($output_path) {
    $dir = dirname($output_path);
    if ($dir !== '.' && !is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $fh = fopen($output_path, 'w');
    if ($fh === false) {
        fwrite(STDERR, "ERROR: Cannot open output file: {$output_path}\n");
        exit(1);
    }
    $written = write_csv($fh, $questions);
    fclose($fh);
    fwrite(STDERR, "CSV written to: {$output_path} ({$written} rows)\n");
} else {
    $written = write_csv(STDOUT, $questions);
}

exit(empty($errors) ? 0 : 1);
