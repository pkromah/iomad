<?php
// This file is part of Moodle - http://moodle.org/

namespace qtype_structuredsteps\local\converter\engines;

defined('MOODLE_INTERNAL') || die();

/**
 * Phase 2 engine: Statistics questions.
 *
 * Handles:
 * - Central tendency: mean, median, mode
 * - Frequency tables and cumulative frequency
 * - Probability: simple, conditional, complementary
 * - Histogram/bar chart interpretation
 * - Box-and-whisker plots
 *
 * All current CXC Statistics questions are image-flagged (100% image density).
 * This engine is built for future use once image assets are uploaded via the
 * ssqc-image-asset-import stream. PHPUnit coverage is via synthetic inputs.
 */
class EvidenceMappingEngine implements ConverterEngineInterface {

    /** @var array<string,string[]> Statistical operation → step templates */
    private const STAT_OPS = [
        'mean'                 => ['Identify all data values', 'Sum all values: Σx', 'Count the number of values: n', 'Apply formula: mean = Σx / n', 'State result'],
        'median'               => ['List all values in ascending order', 'Count total values n', 'Locate middle value (or average of two middle values)', 'State median'],
        'mode'                 => ['List all values', 'Identify the value(s) that appear most frequently', 'State mode(s)'],
        'frequency'            => ['Read frequency table headings', 'Identify required frequency or class interval', 'Calculate cumulative frequency if required', 'State answer'],
        'cumulative_frequency' => ['Set up cumulative frequency column', 'Add each frequency progressively', 'Plot or read off cumulative frequency curve', 'Identify required percentile or value'],
        'probability'          => ['Identify the sample space', 'Count favourable outcomes', 'Apply formula: P(event) = favourable / total', 'Simplify fraction if required', 'State probability'],
        'conditional'          => ['Identify given condition', 'Restrict sample space to condition', 'Count favourable outcomes in restricted space', 'Apply P(A|B) = P(A∩B) / P(B)', 'State result'],
        'histogram'            => ['Read axis labels and scale', 'Identify required bar or class interval', 'Calculate frequency or frequency density', 'State answer'],
        'range'                => ['Identify maximum value', 'Identify minimum value', 'Calculate range = max − min', 'State result'],
        'standard_deviation'   => ['Find the mean', 'Calculate each squared deviation (x − mean)²', 'Sum squared deviations', 'Divide by n (or n−1 for sample)', 'Take square root', 'State standard deviation'],
        'box_whisker'          => ['Find minimum, Q1, median, Q3, maximum', 'Draw and label box-and-whisker plot', 'Read off required value from diagram'],
    ];

    public function getEngineName(): string {
        return 'evidence_mapping';
    }

    public function canHandle(array $question_data): bool {
        $lower = mb_strtolower($question_data['raw_text'] ?? '');
        $topic = mb_strtolower($question_data['topic'] ?? '');

        return str_contains($topic, 'statistic')
            || str_contains($topic, 'frequency')
            || str_contains($topic, 'probability')
            || preg_match('/\b(mean|median|mode|variance|standard deviation|histogram|cumulative|quartile|probability|sample space)\b/', $lower);
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

        $lower  = mb_strtolower($question_data['raw_text'] ?? '');
        $op_key = $this->detect_stat_op($lower);
        $template = self::STAT_OPS[$op_key];

        // If question contains an embedded HTML table, add a data-extraction prefix step.
        $steps = [];
        if ($this->has_data_table($question_data['raw_text'] ?? '')) {
            $steps[] = ['label' => 'Step 1', 'content' => 'Extract data values from the table'];
            foreach ($template as $i => $label) {
                $steps[] = ['label' => 'Step ' . ($i + 2), 'content' => $label];
            }
        } else {
            foreach ($template as $i => $label) {
                $steps[] = ['label' => 'Step ' . ($i + 1), 'content' => $label];
            }
        }

        return [
            'steps'  => $steps,
            'engine' => $this->getEngineName(),
            'status' => 'auto_convert_eligible',
            'notes'  => ['stat_op:' . $op_key],
        ];
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function detect_stat_op(string $lower): string {
        if (str_contains($lower, 'standard deviation') || str_contains($lower, 'variance')) {
            return 'standard_deviation';
        }
        if (str_contains($lower, 'cumulative frequency') || str_contains($lower, 'ogive')) {
            return 'cumulative_frequency';
        }
        if (str_contains($lower, 'conditional') || preg_match('/p\s*\([a-z]\s*\|\s*[a-z]\)/', $lower)) {
            return 'conditional';
        }
        if (str_contains($lower, 'probability') || str_contains($lower, 'sample space')
            || preg_match('/\bp\s*\([a-z]/', $lower)
        ) {
            return 'probability';
        }
        if (str_contains($lower, 'median')) {
            return 'median';
        }
        if (str_contains($lower, 'mode')) {
            return 'mode';
        }
        if (str_contains($lower, 'mean') || str_contains($lower, 'average')) {
            return 'mean';
        }
        if (str_contains($lower, 'range')) {
            return 'range';
        }
        if (str_contains($lower, 'histogram') || str_contains($lower, 'bar chart')) {
            return 'histogram';
        }
        if (str_contains($lower, 'box') || str_contains($lower, 'whisker') || str_contains($lower, 'quartile')) {
            return 'box_whisker';
        }
        if (str_contains($lower, 'frequency')) {
            return 'frequency';
        }
        // Default: mean (most common statistics question type)
        return 'mean';
    }

    private function has_data_table(string $html): bool {
        return stripos($html, '<table') !== false;
    }
}
