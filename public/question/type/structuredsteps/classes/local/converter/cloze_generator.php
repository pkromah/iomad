<?php
// This file is part of Moodle - http://moodle.org/

namespace qtype_structuredsteps\local\converter;

defined('MOODLE_INTERNAL') || die();

/**
 * Generates qtype_structuredsteps JSON from a parsed CLOZE NUMERICAL question.
 *
 * Implements TASK-PSQC-009 (AlgorithmicWorkingEngine) and TASK-PSQC-010 (StepCalculationEngine).
 *
 * SEA marking scheme (D-PSQC-004):
 * - partial_credit_enabled = false (full marks on final answer only)
 * - method_marks_enabled   = false (no method marks — SEA differs from CXC here)
 * - All steps are 'computational' type; all fields are 'numberbox'
 * - Tolerance carried through from parsed CLOZE sub-part (e.g. {1:NUMERICAL:=15:0.5})
 *
 * Input: output of cloze_parser::parse_question() with all_numerical=true.
 * Output: same shape as ddwtos_generator::generate() for pipeline compatibility.
 *
 * @package qtype_structuredsteps
 */
class cloze_generator {

    /**
     * Generate validated structuredsteps JSON for a classified CLOZE question.
     *
     * @param array  $parsed  Output of cloze_parser::parse_question().
     * @param string $engine  Target engine from cloze_classifier (AlgorithmicWorkingEngine|StepCalculationEngine).
     * @return array{model_json:string,validation_error:?string,field_count:int,step_count:int}
     */
    public function generate(array $parsed, string $engine): array {
        $model = $this->build_model($parsed, $engine);
        $json  = json_encode($model, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $validator = new \qtype_structuredsteps\local\model_validator();
        $error     = $validator->validate($json, $engine);

        return [
            'model_json'       => $json,
            'validation_error' => $error,
            'field_count'      => count($model['fields'] ?? []),
            'step_count'       => count($model['steps'] ?? []),
        ];
    }

    /**
     * Build the model array from parsed CLOZE data.
     *
     * @param array  $parsed
     * @param string $engine
     * @return array<string,mixed>
     */
    private function build_model(array $parsed, string $engine): array {
        $steps_raw    = (array)($parsed['steps']    ?? []);
        $slot_answers = (array)($parsed['slot_answers'] ?? []);
        $sub_parts    = (array)($parsed['sub_parts']    ?? []);

        // Index slot_answers by slot_index for O(1) lookup.
        $slot_map = [];
        foreach ($slot_answers as $sa) {
            $slot_map[(int)$sa['slot_index']] = $sa;
        }

        // Index sub_parts by position for tolerance lookup.
        $part_map = [];
        foreach ($sub_parts as $part) {
            $part_map[(int)$part['position']] = $part;
        }

        $steps  = [];
        $fields = [];

        foreach ($steps_raw as $step_def) {
            $step_id      = (string)$step_def['id'];
            $slot_indices = (array)($step_def['slot_indices'] ?? []);

            $steps[] = [
                'id'    => $step_id,
                'label' => (string)($step_def['label'] ?? $step_id),
                'type'  => 'computational',   // SEA: no method marks
                'marks' => (float)count($slot_indices),
            ];

            foreach ($slot_indices as $slot_idx) {
                $slot_idx = (int)$slot_idx;
                $sa       = $slot_map[$slot_idx] ?? null;
                if ($sa === null) {
                    continue;
                }

                $correct = trim((string)($sa['correct'] ?? ''));
                if ($correct === '') {
                    continue; // skip phantom slots
                }

                // Tolerance from sub_part answers (NUMERICAL sub-type only).
                $tolerance = $this->resolve_tolerance($part_map, $slot_idx);

                $field_id = 'f' . ($slot_idx + 1);

                $field = [
                    'id'        => $field_id,
                    'step_id'   => $step_id,
                    'type'      => 'numberbox',
                    'expected'  => $correct,
                    'tolerance' => $tolerance,
                    'marks'     => 1.0,
                ];

                if (!empty($parsed['has_image_refs'])) {
                    $field['asset_note'] = 'Question references images — manual attachment required after import.';
                }

                $fields[] = $field;
            }
        }

        return [
            'schema_version' => '1.0',
            'engine'         => $engine,
            'engine_version' => '1.0',
            'metadata'       => [
                'source'           => 'cloze_conversion',
                'source_name'      => (string)($parsed['name']     ?? ''),
                'source_category'  => (string)($parsed['category'] ?? ''),
                'converted_at'     => date('Y-m-d'),
                'sea_marking'      => true,
            ],
            'params'  => [
                'partial_credit_enabled' => false,
                'method_marks_enabled'   => false,
            ],
            'grading' => [
                'mode'                   => 'field_sum',
                'partial_credit_enabled' => false,
            ],
            'steps'  => $steps,
            'fields' => $fields,
        ];
    }

    /**
     * Resolve tolerance for a slot_index from sub_parts map.
     *
     * Looks up sub_part at position == slot_idx; reads tolerance from first answer.
     * Returns 0 if sub_part not found or tolerance is null/empty.
     *
     * @param array<int,array> $part_map  sub_parts indexed by position.
     * @param int              $slot_idx
     * @return float
     */
    private function resolve_tolerance(array $part_map, int $slot_idx): float {
        $part = $part_map[$slot_idx] ?? null;
        if ($part === null) {
            return 0.0;
        }
        $answers = (array)($part['answers'] ?? []);
        if (empty($answers)) {
            return 0.0;
        }
        $tol = $answers[0]['tolerance'] ?? null;
        if ($tol === null || $tol === '' || !is_numeric($tol)) {
            return 0.0;
        }
        return max(0.0, (float)$tol);
    }
}
