<?php
// This file is part of Moodle - http://moodle.org/

namespace qtype_structuredsteps\local\converter;

defined('MOODLE_INTERNAL') || die();

/**
 * Parser for Moodle 3.x CLOZE (multianswer) question XML exports.
 *
 * CLOZE sub-part format: {weight:SUBTYPE:answers}
 * - weight:  integer or decimal (usually 1)
 * - SUBTYPE: NUMERICAL, SHORTANSWER, SAC, MULTICHOICE, MULTIRESPONSE, NUMERICAL_UNITS
 * - answers: first_answer[~next_answer...] where each answer is:
 *            [=]value[:tolerance][#feedback]
 *            Leading = means correct/valid answer (SHORTANSWER allows multiple = for alternates).
 *            For MULTICHOICE: first = is correct, remaining ~ without = are wrong.
 *            For NUMERICAL: value may have :tolerance suffix (e.g. =15:0 means exact).
 *            Wildcard catch-alls (*) are excluded from parsed output.
 *            Generic feedback strings are discarded (see GENERIC_FEEDBACK constant).
 *
 * Output from parse_question() is structurally compatible with ddwtos_parser::parse_question()
 * (same 'steps', 'slot_answers', 'has_image_refs' keys) so cloze_generator can extend
 * ddwtos_generator without duplicating model-building logic.
 *
 * SEA marking addendum (D-PSQC-009): partial_credit_enabled=false and
 * method_marks_enabled=false must be applied by cloze_generator, not here.
 *
 * @package qtype_structuredsteps
 */
class cloze_parser {

    /** @var string[] Feedback strings treated as non-substantive and discarded on parse. */
    const GENERIC_FEEDBACK = [
        'Excellent', 'excellent',
        'Correct', 'correct',
        'Incorrect', 'incorrect',
        'Right', 'right',
        'Wrong', 'wrong',
        'Good', 'good',
    ];

    /** @var int Maximum step label length (characters) before truncation. */
    const MAX_LABEL_LEN = 120;

    // ---- Public API ----

    /**
     * Parse all CLOZE questions from a Moodle XML export file.
     *
     * @param string $filepath Absolute path to the XML file.
     * @return array{questions:array,parse_errors:array,file_stats:array}
     */
    public function parse_file(string $filepath): array {
        $questions = [];
        $errors    = [];

        if (!file_exists($filepath) || !is_readable($filepath)) {
            return [
                'questions'    => [],
                'parse_errors' => [['file' => $filepath, 'error' => 'File not found or not readable']],
                'file_stats'   => ['total' => 0, 'parsed' => 0, 'failed' => 0],
            ];
        }

        $raw = file_get_contents($filepath);
        if ($raw === false) {
            return [
                'questions'    => [],
                'parse_errors' => [['file' => $filepath, 'error' => 'Could not read file']],
                'file_stats'   => ['total' => 0, 'parsed' => 0, 'failed' => 0],
            ];
        }

        // Strip UTF-8 BOM (\xEF\xBB\xBF) and any pre-declaration content.
        $normalised = ltrim($raw, "\xEF\xBB\xBF");
        $xml_pos = strpos($normalised, '<?xml');
        if ($xml_pos !== false && $xml_pos > 0) {
            $normalised = substr($normalised, $xml_pos);
        }

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($normalised);

        if ($xml === false) {
            $xmlerrs = libxml_get_errors();
            libxml_clear_errors();
            $msg = !empty($xmlerrs) ? $xmlerrs[0]->message : 'Unknown XML error';
            return [
                'questions'    => [],
                'parse_errors' => [['file' => $filepath, 'error' => 'XML parse failure: ' . trim($msg)]],
                'file_stats'   => ['total' => 0, 'parsed' => 0, 'failed' => 1],
            ];
        }

        libxml_clear_errors();

        $total    = 0;
        $failed   = 0;
        $category = '';   // tracks current category from <question type="category"> nodes

        foreach ($xml->question as $qnode) {
            $type = (string)($qnode['type'] ?? '');

            if ($type === 'category') {
                // Strip the Moodle "$course$/category" prefix convention.
                $raw_cat  = (string)($qnode->category->text ?? '');
                $category = (string)preg_replace('#^\$[^$]+\$/+#', '', $raw_cat);
                continue;
            }

            if ($type !== 'cloze') {
                continue;
            }

            $total++;
            try {
                $questions[] = $this->parse_question($qnode, $category);
            } catch (\Throwable $e) {
                $failed++;
                $name = (string)($qnode->name->text ?? 'unknown');
                $errors[] = [
                    'file'          => $filepath,
                    'question_name' => $name,
                    'error'         => $e->getMessage(),
                ];
            }
        }

        return [
            'questions'    => $questions,
            'parse_errors' => $errors,
            'file_stats'   => [
                'total'  => $total,
                'parsed' => count($questions),
                'failed' => $failed,
            ],
        ];
    }

    /**
     * Parse a single <question type="cloze"> SimpleXMLElement node.
     *
     * Returned array keys are a superset of ddwtos_parser::parse_question() keys:
     *
     *   name            string  Question name.
     *   raw_text        string  HTML question text with {N:SUBTYPE:...} patterns intact.
     *   category        string  Category name (inherited from parent context node).
     *   placeholders    array   [{slot_index, group_number, text_offset}] — always group 1.
     *   steps           array   [{id, label, slot_indices, type}]  compatible with ddwtos_generator.
     *   slot_answers    array   [{slot_index, group_number, correct, distractors}]  compatible.
     *   image_refs      array   Detected image src / @@PLUGINFILE@@ / img_* values.
     *   has_image_refs  bool
     *   step_count      int
     *   slot_count      int
     *   sub_parts       array   Raw parsed sub-parts (position, subtype, answers, ...).
     *   subtypes        array   Unique sub-types present (e.g. ['NUMERICAL']).
     *   all_numerical   bool    True when every sub-part is NUMERICAL or NUMERICAL_UNITS.
     *   has_mixed_types bool    True when more than one distinct sub-type is present.
     *
     * @param \SimpleXMLElement $qnode
     * @param string            $category
     * @return array<string,mixed>
     */
    public function parse_question(\SimpleXMLElement $qnode, string $category = ''): array {
        $name = trim((string)($qnode->name->text ?? ''));

        // Extract question HTML (CDATA-wrapped).
        $text_node = $qnode->questiontext->text ?? null;
        $rawtext   = $text_node !== null ? (string)$text_node : '';
        // Strip any literal CDATA markers that SimpleXML left in.
        $rawtext = preg_replace('/^<!\[CDATA\[|\]\]>$/', '', trim($rawtext)) ?? $rawtext;

        $sub_parts   = $this->extract_sub_parts($rawtext);
        $slot_answers = $this->build_slot_answers($sub_parts);
        $steps        = $this->build_steps($rawtext, $sub_parts);
        $image_refs   = $this->detect_image_refs($rawtext);

        $subtypes = array_values(array_unique(array_column($sub_parts, 'subtype')));

        return [
            'name'           => $name,
            'raw_text'       => $rawtext,
            'category'       => $category,
            // ddwtos_generator-compatible fields:
            'placeholders'   => array_map(
                fn ($i) => ['slot_index' => $i, 'group_number' => 1, 'text_offset' => 0],
                range(0, max(0, count($sub_parts) - 1))
            ),
            'steps'          => $steps,
            'slot_answers'   => $slot_answers,
            'image_refs'     => $image_refs,
            'has_image_refs' => !empty($image_refs),
            'step_count'     => count($steps),
            'slot_count'     => count($sub_parts),
            // CLOZE-specific:
            'sub_parts'      => $sub_parts,
            'subtypes'       => $subtypes,
            'all_numerical'  => count($subtypes) === 1
                                && in_array($subtypes[0], ['NUMERICAL', 'NUMERICAL_UNITS'], true),
            'has_mixed_types'=> count($subtypes) > 1,
        ];
    }

    // ---- Sub-part extraction ----

    /**
     * Moodle CLOZE sub-type shorthands → canonical names.
     *
     * @see https://docs.moodle.org/en/Embedded_Answers_(Cloze)_question_type
     */
    const SUBTYPE_ALIASES = [
        'MC'   => 'MULTICHOICE',
        'MCS'  => 'MULTICHOICE',
        'MCH'  => 'MULTICHOICE',
        'MCV'  => 'MULTICHOICE',
        'SAC'  => 'SHORTANSWER',     // case-sensitive shortanswer
        'SA'   => 'SHORTANSWER',
        'NM'   => 'NUMERICAL',
        'MR'   => 'MULTIRESPONSE',
        'MRH'  => 'MULTIRESPONSE',
        'RX'   => 'MULTIRESPONSE',
    ];

    /**
     * Extract all {weight:SUBTYPE:answers} occurrences from question HTML.
     *
     * Sub-parts cannot contain nested braces (confirmed from source inventory),
     * so [^}]* matching is safe for the answers portion.
     *
     * Sub-type shorthands (MC, RX, SAC, etc.) are normalised to canonical names.
     *
     * @param string $text Raw question HTML with embedded CLOZE patterns.
     * @return array<int,array{position:int,text_offset:int,full_match:string,
     *                         weight:float,subtype:string,answers:array}>
     */
    public function extract_sub_parts(string $text): array {
        if (!preg_match_all(
            '/\{(\d+(?:\.\d+)?):([A-Z_]+):([^}]*)\}/',
            $text,
            $matches,
            PREG_OFFSET_CAPTURE
        )) {
            return [];
        }

        $sub_parts = [];
        $count = count($matches[0]);

        for ($i = 0; $i < $count; $i++) {
            $raw_subtype = strtoupper($matches[2][$i][0]);
            $subtype = self::SUBTYPE_ALIASES[$raw_subtype] ?? $raw_subtype;
            $sub_parts[] = [
                'position'    => $i,
                'text_offset' => (int)$matches[0][$i][1],
                'full_match'  => $matches[0][$i][0],
                'weight'      => (float)$matches[1][$i][0],
                'subtype'     => $subtype,
                'answers'     => $this->parse_answers($matches[3][$i][0], $subtype),
            ];
        }

        return $sub_parts;
    }

    /**
     * Parse the answers portion of a CLOZE sub-part string.
     *
     * Leading ~ in MULTICHOICE format (e.g. "~=correct~wrong") is stripped before splitting.
     * Wildcards (*) and generic feedback strings are discarded.
     *
     * @param string $answers_str Raw answers string from inside {weight:SUBTYPE:↑this↑}.
     * @param string $subtype     CLOZE sub-type (NUMERICAL, SHORTANSWER, MULTICHOICE, …).
     * @return array<int,array{correct:bool,value:string,tolerance:?string,feedback:string}>
     */
    public function parse_answers(string $answers_str, string $subtype): array {
        $parsed = [];

        // MULTICHOICE format may start with ~= — normalise by stripping the leading ~.
        $str = ltrim($answers_str, '~');

        // Split on ~ (answer separator).
        $raw_answers = preg_split('/~/', $str, -1, PREG_SPLIT_NO_EMPTY);
        if (empty($raw_answers)) {
            return [];
        }

        foreach ($raw_answers as $raw) {
            $raw       = trim($raw);
            $is_correct = false;
            $feedback  = '';
            $tolerance = null;

            // Leading = → valid/correct answer.
            if (str_starts_with($raw, '=')) {
                $is_correct = true;
                $raw = substr($raw, 1);
            }

            // Feedback: text after the first # (CLOZE feedback does not itself contain #).
            $hash_pos = strpos($raw, '#');
            if ($hash_pos !== false) {
                $feedback = substr($raw, $hash_pos + 1);
                $raw      = substr($raw, 0, $hash_pos);
            }

            // NUMERICAL tolerance: last :value suffix after the numeric answer.
            if ($subtype === 'NUMERICAL' || $subtype === 'NUMERICAL_UNITS') {
                $colon_pos = strrpos($raw, ':');
                if ($colon_pos !== false) {
                    $tol_candidate = substr($raw, $colon_pos + 1);
                    if ($tol_candidate === '' || is_numeric($tol_candidate)) {
                        $tolerance = $tol_candidate;
                        $raw       = substr($raw, 0, $colon_pos);
                    }
                }
            }

            $value = trim($raw);

            // Discard wildcard catch-alls.
            if ($value === '*') {
                continue;
            }

            // Discard generic (non-substantive) feedback.
            $fb = trim($feedback);
            if (in_array($fb, self::GENERIC_FEEDBACK, true)) {
                $fb = '';
            }

            $parsed[] = [
                'correct'   => $is_correct,
                'value'     => $value,
                'tolerance' => $tolerance,
                'feedback'  => $fb,
            ];
        }

        return $parsed;
    }

    // ---- Slot-answers builder (ddwtos_generator compatible) ----

    /**
     * Build slot_answers in the format expected by ddwtos_generator::build_model().
     *
     * For SHORTANSWER, additional correct alternates are included in 'distractors' so
     * they reach the generator's collect_options() path (which includes all options in
     * the field's 'options' list when field_type === 'choice').
     *
     * @param array $sub_parts From extract_sub_parts().
     * @return array<int,array{slot_index:int,group_number:int,correct:string,distractors:array}>
     */
    public function build_slot_answers(array $sub_parts): array {
        $slot_answers = [];

        foreach ($sub_parts as $i => $sp) {
            $correct     = '';
            $distractors = [];

            foreach ($sp['answers'] as $ans) {
                if ($ans['correct'] && $correct === '') {
                    $correct = $ans['value'];
                } else {
                    // Wrong answers (MULTICHOICE) and extra correct alternates (SHORTANSWER)
                    // both surface as distractors so they are included in the options list.
                    if ($ans['value'] !== '') {
                        $distractors[] = $ans['value'];
                    }
                }
            }

            $slot_answers[] = [
                'slot_index'   => $i,
                'group_number' => 1,
                'correct'      => $correct,
                'distractors'  => array_values(array_filter($distractors, fn ($v) => $v !== '')),
            ];
        }

        return $slot_answers;
    }

    // ---- Step builder ----

    /**
     * Build steps in ddwtos_parser-compatible format.
     *
     * Step extraction strategy (applied in priority order):
     *  1. "Step N:" explicit labels in plain text → use ddwtos-style split.
     *  2. List markers a), b), (a), (b), etc. → each lettered item = one step.
     *  3. Fallback → one step per sub-part; label = text immediately before that {N:...}.
     *
     * @param string $text      Raw question HTML.
     * @param array  $sub_parts From extract_sub_parts().
     * @return array<int,array{id:string,label:string,slot_indices:array,type:string}>
     */
    public function build_steps(string $text, array $sub_parts): array {
        if (empty($sub_parts)) {
            return [];
        }

        $plain = $this->plain_for_structure($text);

        if (preg_match('/Step\s+\d+\s*:/i', $plain)) {
            $steps = $this->build_labelled_steps($plain, $sub_parts);
            if (!empty($steps)) {
                return $steps;
            }
        }

        if (preg_match('/\b[a-e]\)\s/i', $plain) || preg_match('/\([a-e]\)\s/i', $plain)) {
            $steps = $this->build_list_steps($text, $sub_parts);
            if (!empty($steps)) {
                return $steps;
            }
        }

        return $this->build_individual_steps($text, $sub_parts);
    }

    // ---- Image detection ----

    /**
     * Detect image references in question text (mirrors ddwtos_parser logic).
     *
     * @param string $text
     * @return string[]
     */
    public function detect_image_refs(string $text): array {
        $refs = [];

        if (preg_match_all('/<img[^>]+src=["\']([^"\']+)["\']/', $text, $m)) {
            foreach ($m[1] as $src) {
                $refs[] = $src;
            }
        }

        if (preg_match_all('/@@PLUGINFILE@@\/([^\s"\'<>]+)/', $text, $m)) {
            foreach ($m[1] as $src) {
                $refs[] = $src;
            }
        }

        if (preg_match_all('/img_\w+/i', $text, $m)) {
            foreach ($m[0] as $ref) {
                $refs[] = $ref;
            }
        }

        return array_values(array_unique($refs));
    }

    // ---- Private helpers ----

    /**
     * Convert HTML question text to plain text for structure detection,
     * replacing {N:SUBTYPE:...} patterns with a _SLOT_ placeholder.
     *
     * @param string $html
     * @return string
     */
    private function plain_for_structure(string $html): string {
        $plain = preg_replace('/\{[^}]*\}/', ' _SLOT_ ', $html);
        $plain = preg_replace('/<[^>]+>/', ' ', $plain ?? $html);
        $plain = html_entity_decode($plain ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/\s+/', ' ', $plain) ?? '');
    }

    /**
     * Build steps using "Step N:" label boundaries (same logic as ddwtos_parser).
     *
     * @param string $plain  Plain text with _SLOT_ markers.
     * @param array  $sub_parts
     * @return array
     */
    private function build_labelled_steps(string $plain, array $sub_parts): array {
        $parts = preg_split('/(?=Step\s+\d+\s*:)/i', $plain, -1, PREG_SPLIT_NO_EMPTY);
        $steps       = [];
        $slot_cursor = 0;

        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            $slot_count = substr_count($part, '_SLOT_');
            if ($slot_count === 0) {
                continue;
            }

            $label_raw = (string)preg_replace('/_SLOT_.*$/s', '', $part);
            $label     = $this->truncate_label(trim(preg_replace('/\s+/', ' ', $label_raw) ?? ''));

            $slot_indices = [];
            for ($i = 0; $i < $slot_count && $slot_cursor < count($sub_parts); $i++) {
                $slot_indices[] = $slot_cursor++;
            }

            if (empty($slot_indices)) {
                continue;
            }

            $first = $slot_indices[0];
            $steps[] = [
                'id'           => 'step' . ($first + 1),
                'label'        => $label !== '' ? $label : ('Step ' . ($first + 1)),
                'slot_indices' => $slot_indices,
                'type'         => 'computational',
            ];
        }

        return $steps;
    }

    /**
     * Build steps using lettered-list (a), b), etc.) boundaries.
     *
     * Replaces {N:SUBTYPE:...} with null-byte markers (\x00index\x00) before stripping
     * HTML so slot positions survive the tag-removal pass.
     *
     * @param string $html
     * @param array  $sub_parts
     * @return array
     */
    private function build_list_steps(string $html, array $sub_parts): array {
        $slot_index = 0;
        $tagged = preg_replace_callback('/\{[^}]+\}/', function ($m) use (&$slot_index) {
            return "\x00" . ($slot_index++) . "\x00";
        }, $html) ?? $html;

        $plain = preg_replace('/<[^>]+>/', ' ', $tagged);
        $plain = html_entity_decode($plain ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $plain = preg_replace('/\s+/', ' ', $plain ?? '');

        // Split just before each list-item marker.
        $segments = preg_split(
            '/(?=\s*\b[a-e]\)\s|\s*\([a-e]\)\s)/i',
            (string)$plain,
            -1,
            PREG_SPLIT_NO_EMPTY
        );

        $steps = [];

        foreach ($segments as $seg) {
            $seg = trim($seg);
            if ($seg === '') {
                continue;
            }

            preg_match_all('/\x00(\d+)\x00/', $seg, $sm);
            if (empty($sm[1])) {
                continue;
            }

            $slot_indices = array_map('intval', $sm[1]);

            // Label: text before the first slot marker.
            $label_raw = (string)preg_replace('/\x00.*$/s', '', $seg);
            $label     = $this->truncate_label(trim(preg_replace('/\s+/', ' ', $label_raw) ?? ''));

            $first = $slot_indices[0];
            $steps[] = [
                'id'           => 'step' . ($first + 1),
                'label'        => $label !== '' ? $label : ('Step ' . ($first + 1)),
                'slot_indices' => $slot_indices,
                'type'         => 'computational',
            ];
        }

        return $steps;
    }

    /**
     * Build one step per sub-part; the step label is extracted from the text
     * immediately preceding the {N:SUBTYPE:...} pattern.
     *
     * @param string $html
     * @param array  $sub_parts
     * @return array
     */
    private function build_individual_steps(string $html, array $sub_parts): array {
        // Tag each {N:...} with its index so we can recover position after tag stripping.
        $slot_index = 0;
        $tagged = preg_replace_callback('/\{[^}]+\}/', function ($m) use (&$slot_index) {
            return "\x00" . ($slot_index++) . "\x00";
        }, $html) ?? '';

        $plain = preg_replace('/<[^>]+>/', ' ', $tagged);
        $plain = html_entity_decode($plain ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $plain = preg_replace('/\s+/', ' ', $plain ?? '');

        $steps = [];

        foreach ($sub_parts as $i => $sp) {
            $label   = '';
            $marker  = "\x00{$i}\x00";
            $mpos    = strpos((string)$plain, $marker);

            if ($mpos !== false && $mpos > 0) {
                // Take up to 200 chars of text before this marker.
                $before = substr((string)$plain, max(0, $mpos - 200), min(200, $mpos));
                // Strip earlier slot markers.
                $before = preg_replace('/\x00\d+\x00/', '', $before) ?? $before;
                // Use the last sentence/clause (split on sentence-end or dash separator).
                $clauses = preg_split('/(?<=[.!?])\s+|(?:\s*[-–]\s*)/', trim($before), -1, PREG_SPLIT_NO_EMPTY);
                $label   = !empty($clauses) ? (string)end($clauses) : $before;
                $label   = $this->truncate_label(trim($label));
            }

            $steps[] = [
                'id'           => 'step' . ($i + 1),
                'label'        => $label !== '' ? $label : ('Step ' . ($i + 1)),
                'slot_indices' => [$i],
                'type'         => 'computational',
            ];
        }

        return $steps;
    }

    /**
     * Truncate a step label to MAX_LABEL_LEN characters.
     *
     * @param string $label
     * @return string
     */
    private function truncate_label(string $label): string {
        if (mb_strlen($label) > self::MAX_LABEL_LEN) {
            return mb_substr($label, 0, self::MAX_LABEL_LEN - 3) . '...';
        }
        return $label;
    }
}
