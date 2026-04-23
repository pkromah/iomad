<?php
// This file is part of Moodle - http://moodle.org/

namespace qtype_structuredsteps\local\converter;

defined('MOODLE_INTERNAL') || die();

/**
 * Parser for Moodle 3.x DDWTOS question XML exports.
 *
 * Format assumptions (confirmed from source inventory TASK-SSQC-001):
 * - All source questions use group=1 (single shared pool).
 * - Correct answer for slot [[N]] = dragbox at 0-indexed position (N-1) in the pool.
 * - Remaining dragboxes are distractors shared across all slots.
 * - Encoding variant ((N]] appears in Computation Long Paper — normalised to [[N]].
 * - Image references flagged for manual attachment (never block pipeline).
 */
class ddwtos_parser {

    /**
     * Parse all DDWTOS questions from an XML file.
     *
     * @param string $filepath Absolute path to Moodle XML export file.
     * @return array{questions: array, parse_errors: array, file_stats: array}
     */
    public function parse_file(string $filepath): array {
        $questions = [];
        $errors = [];

        if (!file_exists($filepath) || !is_readable($filepath)) {
            return [
                'questions' => [],
                'parse_errors' => [['file' => $filepath, 'error' => 'File not found or not readable']],
                'file_stats' => ['total' => 0, 'parsed' => 0, 'failed' => 0],
            ];
        }

        $raw = file_get_contents($filepath);
        if ($raw === false) {
            return [
                'questions' => [],
                'parse_errors' => [['file' => $filepath, 'error' => 'Could not read file']],
                'file_stats' => ['total' => 0, 'parsed' => 0, 'failed' => 0],
            ];
        }

        // Strip UTF-8 BOM if present (\xEF\xBB\xBF)
        $normalised = ltrim($raw, "\xEF\xBB\xBF");

        // Normalise encoding variant: ((N]] → [[N]]
        $normalised = preg_replace('/\(\((\d+)\]\]/', '[[$1]]', $normalised);

        // Normalise non-standard CDATA opening used in some Computation exports: <!(CDATA( → <![CDATA[
        $normalised = str_replace('<!(CDATA(', '<![CDATA[', $normalised);

        // Strip all content before the XML declaration (some files have text/markup before <?xml).
        // Using strpos is more robust than a regex anchored at ^ when the preamble contains '<'.
        $xml_decl_pos = strpos($normalised, '<?xml');
        if ($xml_decl_pos !== false && $xml_decl_pos > 0) {
            $normalised = substr($normalised, $xml_decl_pos);
        }

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($normalised);

        if ($xml === false) {
            $xmlerrors = libxml_get_errors();
            libxml_clear_errors();
            $msg = !empty($xmlerrors) ? $xmlerrors[0]->message : 'Unknown XML error';
            return [
                'questions' => [],
                'parse_errors' => [['file' => $filepath, 'error' => 'XML parse failure: ' . trim($msg)]],
                'file_stats' => ['total' => 0, 'parsed' => 0, 'failed' => 1],
            ];
        }

        libxml_clear_errors();

        $total = 0;
        $failed = 0;

        foreach ($xml->question as $qnode) {
            $type = (string)($qnode['type'] ?? '');
            if ($type !== 'ddwtos') {
                continue;
            }

            $total++;
            try {
                $parsed = $this->parse_question($qnode);
                $questions[] = $parsed;
            } catch (\Throwable $e) {
                $failed++;
                $name = (string)($qnode->name->text ?? 'unknown');
                $errors[] = [
                    'file' => $filepath,
                    'question_name' => $name,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return [
            'questions' => $questions,
            'parse_errors' => $errors,
            'file_stats' => [
                'total' => $total,
                'parsed' => count($questions),
                'failed' => $failed,
            ],
        ];
    }

    /**
     * Parse a single <question type="ddwtos"> SimpleXMLElement node.
     *
     * @param \SimpleXMLElement $qnode
     * @return array<string,mixed>
     */
    public function parse_question(\SimpleXMLElement $qnode): array {
        $name = trim((string)($qnode->name->text ?? ''));

        // Extract question text — may be CDATA-wrapped HTML
        $rawtextnode = $qnode->questiontext->text ?? null;
        $rawtext = $rawtextnode !== null ? (string)$rawtextnode : '';
        // Strip CDATA markers if present as literal text (already decoded by SimpleXML)
        $rawtext = preg_replace('/^<!\[CDATA\[|\]\]>$/', '', trim($rawtext));

        $placeholders = $this->extract_placeholders($rawtext);
        $steps = $this->extract_steps($rawtext, $placeholders);
        $dragboxes = $this->extract_dragboxes($qnode);
        $slot_answers = $this->map_slot_answers($placeholders, $dragboxes);
        $image_refs = $this->detect_image_refs($rawtext);

        return [
            'name' => $name,
            'raw_text' => $rawtext,
            'placeholders' => $placeholders,
            'steps' => $steps,
            'dragboxes' => $dragboxes,
            'slot_answers' => $slot_answers,
            'image_refs' => $image_refs,
            'has_image_refs' => !empty($image_refs),
            'step_count' => count($steps),
            'slot_count' => count($placeholders),
        ];
    }

    /**
     * Extract [[N]] placeholder positions from question text (left-to-right order).
     *
     * @param string $text Raw question HTML text.
     * @return array<int,int> Map of slot_index (0-based) => group_number (N from [[N]]).
     */
    public function extract_placeholders(string $text): array {
        $placeholders = [];
        // Match [[N]] — already normalised from ((N]]
        if (!preg_match_all('/\[\[(\d+)\]\]/', $text, $matches, PREG_OFFSET_CAPTURE)) {
            return $placeholders;
        }

        foreach ($matches[1] as $idx => [$groupnum, $offset]) {
            $placeholders[] = [
                'slot_index' => $idx,          // 0-based position in text
                'group_number' => (int)$groupnum,
                'text_offset' => $offset,
            ];
        }

        return $placeholders;
    }

    /**
     * Extract step boundaries from question text.
     *
     * Steps are identified by "Step N:" labels. If no labels exist, each [[N]]
     * placeholder is treated as its own single-field step.
     *
     * @param string $text Raw question HTML.
     * @param array $placeholders From extract_placeholders().
     * @return array<int,array> Array of step definitions.
     */
    public function extract_steps(string $text, array $placeholders): array {
        // Try to find "Step N:" labels
        $plain = preg_replace('/<[^>]+>/', ' ', $text);
        $plain = html_entity_decode($plain, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $plain = preg_replace('/\s+/', ' ', $plain);

        $has_step_labels = (bool)preg_match('/Step\s+\d+\s*:/i', $plain);

        if ($has_step_labels) {
            return $this->extract_labelled_steps($plain, $placeholders);
        }

        // Fallback: one step per placeholder
        $steps = [];
        foreach ($placeholders as $ph) {
            $steps[] = [
                'id' => 'step' . ($ph['slot_index'] + 1),
                'label' => 'Step ' . ($ph['slot_index'] + 1),
                'slot_indices' => [$ph['slot_index']],
                'type' => 'computational',
            ];
        }

        return $steps;
    }

    /**
     * Extract dragbox entries from a question node.
     *
     * @param \SimpleXMLElement $qnode
     * @return array<int,array{text:string,group:int,position:int}>
     */
    public function extract_dragboxes(\SimpleXMLElement $qnode): array {
        $dragboxes = [];
        $position = 0;

        foreach ($qnode->dragbox as $db) {
            $text = trim((string)($db->text ?? ''));
            $group = (int)($db->group ?? 1);
            $dragboxes[] = [
                'text' => $text,
                'group' => $group,
                'position' => $position,
            ];
            $position++;
        }

        return $dragboxes;
    }

    /**
     * Map each slot to its correct answer and distractors.
     *
     * Convention (confirmed from TASK-SSQC-001):
     * - All dragboxes are group 1 (single shared pool).
     * - dragbox[i] (0-indexed, i < N) is the correct answer for slot [[i+1]].
     * - dragboxes at index >= N are distractors shared across all slots.
     *
     * @param array $placeholders
     * @param array $dragboxes
     * @return array<int,array{slot_index:int,correct:string,distractors:array}>
     */
    public function map_slot_answers(array $placeholders, array $dragboxes): array {
        $n = count($placeholders);
        $slot_answers = [];

        foreach ($placeholders as $ph) {
            $slot_idx = $ph['slot_index'];
            $correct = isset($dragboxes[$slot_idx]) ? $dragboxes[$slot_idx]['text'] : '';

            $distractors = [];
            foreach ($dragboxes as $pos => $db) {
                if ($pos !== $slot_idx) {
                    $distractors[] = $db['text'];
                }
            }

            $slot_answers[] = [
                'slot_index' => $slot_idx,
                'group_number' => $ph['group_number'],
                'correct' => $correct,
                'distractors' => $distractors,
            ];
        }

        return $slot_answers;
    }

    /**
     * Detect image references in question text.
     *
     * @param string $text
     * @return string[] List of image identifiers/src values found.
     */
    public function detect_image_refs(string $text): array {
        $refs = [];

        // <img src="..."> or @@PLUGINFILE@@/... patterns
        if (preg_match_all('/<img[^>]+src=["\']([^"\']+)["\']/', $text, $m)) {
            foreach ($m[1] as $src) {
                $refs[] = $src;
            }
        }

        // img_ filename references (inline text refs from source data)
        if (preg_match_all('/img_\w+/i', $text, $m)) {
            foreach ($m[0] as $ref) {
                if (!in_array($ref, $refs, true)) {
                    $refs[] = $ref;
                }
            }
        }

        return array_values(array_unique($refs));
    }

    /**
     * Extract steps from text that contains "Step N:" labels.
     *
     * @param string $plain Plain text (HTML stripped).
     * @param array $placeholders
     * @return array
     */
    private function extract_labelled_steps(string $plain, array $placeholders): array {
        // Split on "Step N:" boundaries
        $parts = preg_split('/(?=Step\s+\d+\s*:)/i', $plain, -1, PREG_SPLIT_NO_EMPTY);
        $steps = [];
        $slot_cursor = 0;

        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            // Count [[N]] placeholders in this part
            $ph_count = preg_match_all('/\[\[\d+\]\]/', $part);

            // Extract step label ("Step N: ...instruction...")
            $label = preg_replace('/\[\[\d+\]\].*$/s', '', $part);
            $label = trim(preg_replace('/\s+/', ' ', $label));
            if (strlen($label) > 120) {
                $label = substr($label, 0, 117) . '...';
            }

            $slot_indices = [];
            for ($i = 0; $i < $ph_count && $slot_cursor < count($placeholders); $i++) {
                $slot_indices[] = $slot_cursor;
                $slot_cursor++;
            }

            if (empty($slot_indices)) {
                continue;
            }

            $first_slot = $slot_indices[0];
            $steps[] = [
                'id' => 'step' . ($first_slot + 1),
                'label' => $label !== '' ? $label : ('Step ' . ($first_slot + 1)),
                'slot_indices' => $slot_indices,
                'type' => 'computational',
            ];
        }

        // If label parsing produced nothing, fall back to one-per-placeholder
        if (empty($steps)) {
            foreach ($placeholders as $ph) {
                $steps[] = [
                    'id' => 'step' . ($ph['slot_index'] + 1),
                    'label' => 'Step ' . ($ph['slot_index'] + 1),
                    'slot_indices' => [$ph['slot_index']],
                    'type' => 'computational',
                ];
            }
        }

        return $steps;
    }
}
