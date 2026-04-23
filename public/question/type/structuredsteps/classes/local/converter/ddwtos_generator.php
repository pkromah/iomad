<?php
// This file is part of Moodle - http://moodle.org/

namespace qtype_structuredsteps\local\converter;

defined('MOODLE_INTERNAL') || die();

/**
 * Generates qtype_structuredsteps JSON from a parsed DDWTOS question.
 *
 * Dispatches to engine-specific mark ratios and field types.
 * All generated JSON is validated by model_validator before the caller
 * proceeds to import.
 *
 * CXC marking-scheme defaults (from spec D-SSQC-005):
 * - step_calculation / long_multiplication / long_division: method=1, accuracy=1 (equal per step)
 * - ledger_poa / stoichiometry: accuracy-only, marks=1 per field
 */
class ddwtos_generator {

    /**
     * Generate validated structuredsteps JSON for a classified DDWTOS question.
     *
     * @param array $parsed   Output of ddwtos_parser::parse_question().
     * @param string $engine  Target engine name from ddwtos_classifier.
     * @return array{model_json:string,validation_error:?string,field_count:int,step_count:int}
     */
    public function generate(array $parsed, string $engine): array {
        $model = $this->build_model($parsed, $engine);
        $json = json_encode($model, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $validator = new \qtype_structuredsteps\local\model_validator();
        $error = $validator->validate($json, $engine);

        return [
            'model_json' => $json,
            'validation_error' => $error,
            'field_count' => count($model['fields'] ?? []),
            'step_count' => count($model['steps'] ?? []),
        ];
    }

    /**
     * Build the model array from parsed DDWTOS data.
     *
     * @param array $parsed
     * @param string $engine
     * @return array<string,mixed>
     */
    private function build_model(array $parsed, string $engine): array {
        $steps_raw = $parsed['steps'] ?? [];
        $slot_answers = $parsed['slot_answers'] ?? [];

        // Index slot_answers by slot_index for easy lookup
        $slot_map = [];
        foreach ($slot_answers as $sa) {
            $slot_map[(int)$sa['slot_index']] = $sa;
        }

        $steps = [];
        $fields = [];

        foreach ($steps_raw as $step_def) {
            $step_id = (string)$step_def['id'];
            $slot_indices = (array)($step_def['slot_indices'] ?? []);
            $step_type = $this->resolve_step_type($engine, $step_def, $steps_raw);

            $steps[] = [
                'id' => $step_id,
                'label' => (string)($step_def['label'] ?? $step_id),
                'type' => $step_type,
                'marks' => $this->step_marks($engine, $step_type, count($slot_indices)),
            ];

            foreach ($slot_indices as $slot_idx) {
                $sa = $slot_map[$slot_idx] ?? null;
                $correct = $sa ? $this->clean_text($sa['correct']) : '';
                $all_options = $sa ? $this->collect_options($sa) : [];

                // Skip phantom slots: source DDWTOS multi-part questions place an empty
                // dragbox at position 0 before the first step label. These produce fields
                // with expected="" which are invalid in qtype_structuredsteps.
                if ($correct === '') {
                    continue;
                }

                $field_id = 'f' . ($slot_idx + 1);
                $field_type = $this->resolve_field_type($engine, $correct);

                $field = [
                    'id' => $field_id,
                    'step_id' => $step_id,
                    'type' => $field_type,
                    'expected' => $correct,
                    'marks' => $this->field_marks($engine),
                ];

                if ($field_type === 'numberbox') {
                    $field['tolerance'] = 0;
                } elseif ($field_type === 'choice' && !empty($all_options)) {
                    $field['options'] = $all_options;
                }

                if (!empty($parsed['has_image_refs'])) {
                    $field['asset_note'] = 'Question references images — manual attachment required after import.';
                }

                $fields[] = $field;
            }
        }

        return [
            'schema_version' => '1.0',
            'engine' => $engine,
            'engine_version' => '1.0',
            'metadata' => [
                'source' => 'ddwtos_conversion',
                'source_name' => (string)($parsed['name'] ?? ''),
                'converted_at' => date('Y-m-d'),
            ],
            'params' => new \stdClass(),  // engine-specific params not used for converted questions
            'grading' => [
                'mode' => 'field_sum',
            ],
            'steps' => $steps,
            'fields' => $fields,
        ];
    }

    /**
     * Resolve the step type for CXC marking scheme.
     *
     * - Last step is 'computational' (accuracy mark).
     * - All preceding steps are 'method' for step_calculation; 'computational' for algorithmic.
     *
     * @param string $engine
     * @param array $step_def
     * @param array $all_steps
     * @return string
     */
    private function resolve_step_type(string $engine, array $step_def, array $all_steps): string {
        $is_last = ($step_def['id'] === end($all_steps)['id']);

        if (in_array($engine, ['step_calculation'], true)) {
            return $is_last ? 'computational' : 'method';
        }

        return 'computational';
    }

    /**
     * Marks per step (display only — actual marks summed from fields).
     *
     * @param string $engine
     * @param string $step_type
     * @param int $field_count
     * @return float
     */
    private function step_marks(string $engine, string $step_type, int $field_count): float {
        return (float)$field_count * $this->field_marks($engine);
    }

    /**
     * Marks per field by engine type.
     *
     * @param string $engine
     * @return float
     */
    private function field_marks(string $engine): float {
        return 1.0;
    }

    /**
     * Resolve field type.
     *
     * Uses 'choice' for text/working answers (drag-drop origin),
     * 'numberbox' when the expected value is a pure number.
     *
     * @param string $engine
     * @param string $expected
     * @return string
     */
    private function resolve_field_type(string $engine, string $expected): string {
        $stripped = preg_replace('/\$\$[^$]*\$\$/', '', $expected);
        $stripped = preg_replace('/<[^>]+>/', '', $stripped ?? '');
        $stripped = trim(preg_replace('/[\s\.,]+/', '', $stripped) ?? '');

        if ($stripped !== '' && is_numeric($stripped)) {
            return 'numberbox';
        }

        return 'choice';
    }

    /**
     * Collect all options for a slot (correct first, then distractors), cleaned.
     *
     * @param array $slot_answer
     * @return string[]
     */
    private function collect_options(array $slot_answer): array {
        $options = [$this->clean_text($slot_answer['correct'])];
        foreach ((array)($slot_answer['distractors'] ?? []) as $d) {
            $clean = $this->clean_text($d);
            if ($clean !== '' && !in_array($clean, $options, true)) {
                $options[] = $clean;
            }
        }
        return $options;
    }

    /**
     * Normalise dragbox text: strip surrounding whitespace.
     * Preserve LaTeX ($$...$$) and mathematical notation.
     *
     * @param string $text
     * @return string
     */
    private function clean_text(string $text): string {
        // Decode HTML entities but keep LaTeX and special math chars
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Strip HTML tags except for math-related spans (keep content)
        $text = preg_replace('/<[^>]+>/', '', $text) ?? $text;
        return trim($text);
    }
}
