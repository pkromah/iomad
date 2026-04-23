<?php
// This file is part of Moodle - http://moodle.org/

namespace qtype_structuredsteps\local\converter\engines;

defined('MOODLE_INTERNAL') || die();

/**
 * Phase 2 engine: Vectors and Matrices questions.
 *
 * Handles:
 * - Matrix operations: addition, subtraction, multiplication, determinant, inverse, transpose
 * - Vector operations: addition, subtraction, magnitude, dot product, component form
 *
 * Image-flagged questions are held in review_queue regardless of operation type.
 */
class GraphEngine implements ConverterEngineInterface {

    /** @var array<string,string[]> Operation keyword → step template key */
    private const MATRIX_OPS = [
        'determinant'      => ['State matrix', 'Apply determinant formula', 'Substitute values', 'State result'],
        'inverse'          => ['State matrix', 'Find determinant', 'Form adjugate matrix', 'Multiply by 1/det', 'State result'],
        'multiply'         => ['State dimensions', 'Set up row × column products', 'Compute each element', 'Write result matrix'],
        'transpose'        => ['State original matrix', 'Swap rows and columns', 'State transposed matrix'],
        'add'              => ['State both matrices', 'Add corresponding elements', 'Write result matrix'],
        'subtract'         => ['State both matrices', 'Subtract corresponding elements', 'Write result matrix'],
        'eigenvalue'       => ['State matrix', 'Form characteristic equation det(A - λI) = 0', 'Solve for λ', 'State eigenvalues'],
    ];

    /** @var array<string,string[]> Operation keyword → step template key */
    private const VECTOR_OPS = [
        'add'              => ['Identify component form of each vector', 'Add corresponding components', 'State resultant vector'],
        'subtract'         => ['Identify component form of each vector', 'Subtract corresponding components', 'State resultant vector'],
        'magnitude'        => ['Identify vector components', 'Apply magnitude formula √(x² + y²)', 'Calculate result', 'State magnitude'],
        'dot product'      => ['Identify components of each vector', 'Multiply corresponding components', 'Sum products', 'State scalar result'],
        'unit vector'      => ['Find magnitude of vector', 'Divide each component by magnitude', 'State unit vector'],
        'scalar multiple'  => ['Identify scalar and vector', 'Multiply each component by scalar', 'State resultant vector'],
    ];

    public function getEngineName(): string {
        return 'graph';
    }

    public function canHandle(array $question_data): bool {
        $lower = mb_strtolower($question_data['raw_text'] ?? '');
        $topic = mb_strtolower($question_data['topic'] ?? '');

        return str_contains($topic, 'matri')
            || str_contains($topic, 'vector')
            || preg_match('/\b(matrix|matrices|determinant|inverse|transpose|eigenvalue)\b/', $lower)
            || preg_match('/\b(vector|magnitude|dot product|component form)\b/', $lower);
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

        if (str_contains($topic, 'matri') || $this->is_matrix_question($lower)) {
            return $this->build_matrix_steps($lower);
        }

        return $this->build_vector_steps($lower);
    }

    // ------------------------------------------------------------------
    // Matrix step builders
    // ------------------------------------------------------------------

    private function is_matrix_question(string $lower): bool {
        return preg_match('/\b(matrix|matrices|determinant|inverse|transpose|eigenvalue)\b/', $lower) === 1;
    }

    private function build_matrix_steps(string $lower): array {
        $op_key   = $this->detect_matrix_op($lower);
        $template = self::MATRIX_OPS[$op_key];

        $steps = [];
        foreach ($template as $i => $label) {
            $steps[] = ['label' => 'Step ' . ($i + 1), 'content' => $label];
        }

        return [
            'steps'  => $steps,
            'engine' => $this->getEngineName(),
            'status' => 'auto_convert_eligible',
            'notes'  => ['matrix_op:' . $op_key],
        ];
    }

    private function detect_matrix_op(string $lower): string {
        if (str_contains($lower, 'eigenvalue') || str_contains($lower, 'characteristic')) {
            return 'eigenvalue';
        }
        if (str_contains($lower, 'inverse') || preg_match('/\ba\s*[\^-]\s*[\{(-]?\s*1\b/', $lower)) {
            return 'inverse';
        }
        if (str_contains($lower, 'determinant') || str_contains($lower, 'det(')) {
            return 'determinant';
        }
        if (str_contains($lower, 'transpose')) {
            return 'transpose';
        }
        if (preg_match('/multiply|product of|×/', $lower)) {
            return 'multiply';
        }
        if (preg_match('/subtract|minus|difference/', $lower)) {
            return 'subtract';
        }
        // Default: addition
        return 'add';
    }

    // ------------------------------------------------------------------
    // Vector step builders
    // ------------------------------------------------------------------

    private function build_vector_steps(string $lower): array {
        $op_key   = $this->detect_vector_op($lower);
        $template = self::VECTOR_OPS[$op_key];

        $steps = [];
        foreach ($template as $i => $label) {
            $steps[] = ['label' => 'Step ' . ($i + 1), 'content' => $label];
        }

        return [
            'steps'  => $steps,
            'engine' => $this->getEngineName(),
            'status' => 'auto_convert_eligible',
            'notes'  => ['vector_op:' . $op_key],
        ];
    }

    private function detect_vector_op(string $lower): string {
        if (str_contains($lower, 'dot product') || str_contains($lower, 'scalar product')) {
            return 'dot product';
        }
        if (str_contains($lower, 'magnitude') || str_contains($lower, 'modulus')) {
            return 'magnitude';
        }
        if (str_contains($lower, 'unit vector')) {
            return 'unit vector';
        }
        if (preg_match('/\d+\s*\*|scalar\s*multiple|k\s*[a-z]/', $lower)) {
            return 'scalar multiple';
        }
        if (preg_match('/subtract|minus|difference/', $lower)) {
            return 'subtract';
        }
        // Default: addition
        return 'add';
    }
}
