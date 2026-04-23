<?php
// This file is part of Moodle - http://moodle.org/

namespace qtype_structuredsteps\local\converter\engines;

defined('MOODLE_INTERNAL') || die();

/**
 * Phase 2 engine: Logic Sequence Patterns and Sets questions.
 *
 * Handles:
 * - Numeric/symbolic sequences: identify rule (arithmetic, geometric), generate trace steps
 * - Set operations: union, intersection, complement, Venn diagram notation
 *
 * Image-flagged questions (all Logic Sequence Pattern questions have images) are held in
 * review_queue. Non-image Sets questions (≈30 of 60) are the primary auto-convert target.
 */
class TraceEngine implements ConverterEngineInterface {

    /** @var array<string,string[]> Set operation → step templates */
    private const SET_OPS = [
        'union'        => ['Define set A and set B', 'Combine all elements from both sets', 'Remove duplicates', 'State A ∪ B'],
        'intersection' => ['Define set A and set B', 'Identify elements common to both sets', 'State A ∩ B'],
        'complement'   => ['Define the universal set U', 'Identify elements of the given set', 'List elements in U not in the set', 'State the complement'],
        'subset'       => ['Define both sets', 'Check every element of the potential subset', 'State whether A ⊆ B and justify'],
        'difference'   => ['Define set A and set B', 'Remove elements of B from A', 'State A \\ B'],
        'venn'         => ['Draw and label Venn diagram regions', 'Assign elements to correct regions', 'Verify all elements are placed', 'Read off required value or set'],
        'cardinality'  => ['List all elements of the set', 'Count distinct elements', 'State |A|'],
    ];

    /** @var array<string,string[]> Sequence type → step templates */
    private const SEQUENCE_TYPES = [
        'arithmetic'  => ['Identify the first term', 'Find the common difference d', 'Apply formula: aₙ = a₁ + (n-1)d', 'State the result'],
        'geometric'   => ['Identify the first term', 'Find the common ratio r', 'Apply formula: aₙ = a₁ × r^(n-1)', 'State the result'],
        'pattern'     => ['Examine consecutive terms', 'Identify the rule or pattern', 'Apply the rule to find the next term(s)', 'State the result'],
    ];

    public function getEngineName(): string {
        return 'trace';
    }

    public function canHandle(array $question_data): bool {
        $lower = mb_strtolower($question_data['raw_text'] ?? '');
        $topic = mb_strtolower($question_data['topic'] ?? '');

        return str_contains($topic, 'set')
            || str_contains($topic, 'logic')
            || str_contains($topic, 'sequence')
            || str_contains($topic, 'pattern')
            || preg_match('/\b(union|intersection|complement|venn|subset|cardinality)\b/', $lower)
            || preg_match('/\b(sequence|pattern|next term|common difference|common ratio)\b/', $lower);
    }

    public function convertToSteps(array $question_data): array {
        if (!empty($question_data['has_image_refs'])) {
            return [
                'steps'  => [],
                'engine' => $this->getEngineName(),
                'status' => 'review_queue',
                'notes'  => ['Image references detected — held for SME review and asset upload.'],
            ];
        }

        $lower = mb_strtolower($question_data['raw_text'] ?? '');
        $topic = mb_strtolower($question_data['topic'] ?? '');

        if ($this->is_set_question($topic, $lower)) {
            return $this->build_set_steps($lower);
        }

        return $this->build_sequence_steps($lower);
    }

    // ------------------------------------------------------------------
    // Sets step builders
    // ------------------------------------------------------------------

    private function is_set_question(string $topic, string $lower): bool {
        return str_contains($topic, 'set')
            || preg_match('/\b(union|intersection|complement|venn|subset|cardinality|set notation)\b/', $lower) === 1;
    }

    private function build_set_steps(string $lower): array {
        $op_key   = $this->detect_set_op($lower);
        $template = self::SET_OPS[$op_key];

        $steps = [];
        foreach ($template as $i => $label) {
            $steps[] = ['label' => 'Step ' . ($i + 1), 'content' => $label];
        }

        return [
            'steps'  => $steps,
            'engine' => $this->getEngineName(),
            'status' => 'auto_convert_eligible',
            'notes'  => ['set_op:' . $op_key],
        ];
    }

    private function detect_set_op(string $lower): string {
        if (str_contains($lower, 'venn')) {
            return 'venn';
        }
        if (str_contains($lower, 'union') || str_contains($lower, '∪')) {
            return 'union';
        }
        if (str_contains($lower, 'intersection') || str_contains($lower, '∩')) {
            return 'intersection';
        }
        if (str_contains($lower, 'complement') || str_contains($lower, "a'") || str_contains($lower, "b'")) {
            return 'complement';
        }
        if (str_contains($lower, 'subset') || str_contains($lower, '⊆')) {
            return 'subset';
        }
        if (str_contains($lower, 'difference') || str_contains($lower, '\\')) {
            return 'difference';
        }
        if (str_contains($lower, 'cardinality') || preg_match('/\|[a-z]\|/i', $lower)) {
            return 'cardinality';
        }
        // Default: union (most common set question type)
        return 'union';
    }

    // ------------------------------------------------------------------
    // Sequence step builders
    // ------------------------------------------------------------------

    private function build_sequence_steps(string $lower): array {
        $seq_type = $this->detect_sequence_type($lower);
        $template = self::SEQUENCE_TYPES[$seq_type];

        $steps = [];
        foreach ($template as $i => $label) {
            $steps[] = ['label' => 'Step ' . ($i + 1), 'content' => $label];
        }

        return [
            'steps'  => $steps,
            'engine' => $this->getEngineName(),
            'status' => 'auto_convert_eligible',
            'notes'  => ['sequence_type:' . $seq_type],
        ];
    }

    private function detect_sequence_type(string $lower): string {
        if (str_contains($lower, 'common ratio') || str_contains($lower, 'geometric')
            || preg_match('/multiply.*by|ratio\s*=/', $lower)
        ) {
            return 'geometric';
        }
        if (str_contains($lower, 'common difference') || str_contains($lower, 'arithmetic')
            || preg_match('/add.*to each|difference\s*=/', $lower)
        ) {
            return 'arithmetic';
        }
        return 'pattern';
    }
}
