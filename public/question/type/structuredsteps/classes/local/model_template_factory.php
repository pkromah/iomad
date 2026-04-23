<?php
// This file is part of Moodle - http://moodle.org/

namespace qtype_structuredsteps\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Provides starter model JSON templates per engine for teacher authoring.
 */
class model_template_factory {
    /**
     * Return a valid starter model for the requested engine.
     *
     * @param string $engine
     * @return string
     */
    public static function starter_json(string $engine): string {
        $engine = trim($engine);
        if ($engine === '' || !engine_registry::is_available($engine)) {
            $engine = 'long_multiplication';
        }

        $builders = [
            'long_multiplication' => [self::class, 'long_multiplication_model'],
            'long_division' => [self::class, 'long_division_model'],
            'step_calculation' => [self::class, 'step_calculation_model'],
            'ledger_poa' => [self::class, 'ledger_poa_model'],
            'stoichiometry' => [self::class, 'stoichiometry_model'],
            'evidence_table' => [self::class, 'evidence_table_model'],
            'graphing' => [self::class, 'graphing_model'],
        ];

        $model = isset($builders[$engine]) ? call_user_func($builders[$engine]) : self::long_multiplication_model();
        return json_encode($model, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return array<string,mixed>
     */
    private static function base_model(string $engine): array {
        return [
            'schema_version' => '1.0',
            'engine' => $engine,
            'engine_version' => '1.0',
            'metadata' => [
                'subject' => 'PCLPlus',
                'topic' => $engine,
            ],
            'params' => [],
            'layout' => [
                'mode' => 'step_cards',
            ],
            'steps' => [],
            'fields' => [],
            'grading' => [
                'mode' => 'field_sum',
                'expected_response' => '',
            ],
            'feedback_rules' => [],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private static function long_multiplication_model(): array {
        $model = self::base_model('long_multiplication');
        $model['steps'] = [
            ['id' => 'step1', 'label' => 'Multiply by units', 'type' => 'computational', 'marks' => 1],
            ['id' => 'step2', 'label' => 'Multiply by tens', 'type' => 'computational', 'marks' => 1],
        ];
        $model['fields'] = [
            ['id' => 's1_c1', 'step_id' => 'step1', 'type' => 'numberbox', 'expected' => '318', 'marks' => 1, 'tolerance' => 0],
            ['id' => 's2_c1', 'step_id' => 'step2', 'type' => 'numberbox', 'expected' => '1060', 'marks' => 1, 'tolerance' => 0],
        ];
        return $model;
    }

    /**
     * @return array<string,mixed>
     */
    private static function long_division_model(): array {
        $model = self::base_model('long_division');
        $model['params'] = [
            'dividend' => 784,
            'divisor' => 12,
            'show_remainder' => true,
            'entry_mode' => 'structured',
            'tolerance' => 0.1,
        ];
        $model['steps'] = [
            ['id' => 'ld_step1', 'label' => 'Divide 78 by 12', 'type' => 'procedural', 'marks' => 1],
            ['id' => 'ld_step2', 'label' => 'Subtract 72', 'type' => 'computational', 'marks' => 1],
            ['id' => 'ld_final', 'label' => 'Remainder', 'type' => 'computational', 'marks' => 1],
        ];
        $model['fields'] = [
            ['id' => 'q1', 'step_id' => 'ld_step1', 'type' => 'digitbox', 'expected' => '6', 'marks' => 1, 'role' => 'quotient_digit'],
            ['id' => 's1_result', 'step_id' => 'ld_step2', 'type' => 'digitbox', 'expected' => '6', 'marks' => 1, 'role' => 'subtraction_result'],
            ['id' => 'remainder', 'step_id' => 'ld_final', 'type' => 'numberbox', 'expected' => '4', 'marks' => 1, 'role' => 'remainder'],
        ];
        return $model;
    }

    /**
     * @return array<string,mixed>
     */
    private static function step_calculation_model(): array {
        $model = self::base_model('step_calculation');
        $model['steps'] = [
            ['id' => 'calc1', 'label' => 'Calculate', 'type' => 'computational', 'marks' => 2],
        ];
        $model['fields'] = [
            ['id' => 'f_num', 'step_id' => 'calc1', 'type' => 'numberbox', 'expected' => '10', 'marks' => 1, 'tolerance' => 0.5],
            ['id' => 'f_text', 'step_id' => 'calc1', 'type' => 'structured_text', 'expected' => 'method', 'marks' => 1],
        ];
        return $model;
    }

    /**
     * @return array<string,mixed>
     */
    private static function ledger_poa_model(): array {
        $model = self::base_model('ledger_poa');
        $model['params'] = [
            'mode' => 't_account',
            'decimal_places' => 2,
        ];
        $model['steps'] = [
            ['id' => 'poa_step1', 'label' => 'Entry', 'type' => 'conceptual', 'marks' => 3],
        ];
        $model['fields'] = [
            ['id' => 'entry_side', 'step_id' => 'poa_step1', 'type' => 'choice', 'expected' => 'debit', 'marks' => 1],
            ['id' => 'entry_amount', 'step_id' => 'poa_step1', 'type' => 'numberbox', 'expected' => '500', 'marks' => 1, 'tolerance' => 0],
            ['id' => 'entry_narration', 'step_id' => 'poa_step1', 'type' => 'text', 'expected' => 'Cash received', 'marks' => 1],
        ];
        return $model;
    }

    /**
     * @return array<string,mixed>
     */
    private static function stoichiometry_model(): array {
        $model = self::base_model('stoichiometry');
        $model['params'] = [
            'mode' => 'mass_to_mass',
            'equation' => '2H2 + O2 -> 2H2O',
            'tolerance' => 0.01,
            'expected_unit' => 'g',
            'allow_fractional_coefficients' => false,
        ];
        $model['steps'] = [
            ['id' => 'balance', 'label' => 'Balance equation', 'type' => 'conceptual', 'marks' => 1],
            ['id' => 'ratio', 'label' => 'Apply ratio', 'type' => 'conceptual', 'marks' => 1],
            ['id' => 'result', 'label' => 'Final mass', 'type' => 'computational', 'marks' => 1],
        ];
        $model['fields'] = [
            ['id' => 'coef_h2', 'step_id' => 'balance', 'type' => 'digitbox', 'expected' => '2', 'marks' => 1, 'role' => 'coefficient'],
            ['id' => 'ratio_numerator', 'step_id' => 'ratio', 'type' => 'numberbox', 'expected' => '2', 'marks' => 1, 'role' => 'ratio'],
            ['id' => 'mass_target', 'step_id' => 'result', 'type' => 'numberbox', 'expected' => '36', 'marks' => 1, 'role' => 'conversion'],
        ];
        return $model;
    }

    /**
     * @return array<string,mixed>
     */
    private static function evidence_table_model(): array {
        $model = self::base_model('evidence_table');
        $model['params'] = [
            'mode' => 'answer_evidence',
            'expected_answer_keywords' => ['lonely', 'isolated', 'alone'],
            'expected_evidence_keywords' => ['no friends', 'nobody spoke', 'empty room'],
            'require_explanation' => true,
            'explanation_min_length' => 20,
            'max_length' => 150,
        ];
        $model['steps'] = [
            ['id' => 'answer_step', 'label' => 'Answer', 'type' => 'conceptual', 'marks' => 1],
            ['id' => 'evidence_step', 'label' => 'Evidence', 'type' => 'justification', 'marks' => 1],
            ['id' => 'explanation_step', 'label' => 'Explanation', 'type' => 'justification', 'marks' => 1],
        ];
        $model['fields'] = [
            ['id' => 'answer', 'step_id' => 'answer_step', 'type' => 'structured_text', 'marks' => 1, 'role' => 'answer'],
            ['id' => 'evidence', 'step_id' => 'evidence_step', 'type' => 'structured_text', 'marks' => 1, 'role' => 'evidence'],
            ['id' => 'explanation', 'step_id' => 'explanation_step', 'type' => 'structured_text', 'marks' => 1, 'role' => 'explanation'],
        ];
        return $model;
    }

    /**
     * @return array<string,mixed>
     */
    private static function graphing_model(): array {
        $model = self::base_model('graphing');
        $model['params'] = [
            'mode' => 'straight_line',
            'tolerance' => 0.1,
            'require_gradient' => true,
        ];
        $model['steps'] = [
            ['id' => 'plot_step', 'label' => 'Plot points', 'type' => 'procedural', 'marks' => 2],
            ['id' => 'grad_step', 'label' => 'Gradient', 'type' => 'computational', 'marks' => 1],
        ];
        $model['fields'] = [
            ['id' => 'p1', 'step_id' => 'plot_step', 'type' => 'graphplot', 'marks' => 1, 'role' => 'point', 'expected_x' => 1, 'expected_y' => 2, 'source' => 'plot'],
            ['id' => 'p2', 'step_id' => 'plot_step', 'type' => 'graphplot', 'marks' => 1, 'role' => 'point', 'expected_x' => 2, 'expected_y' => 4, 'source' => 'plot'],
            ['id' => 'gradient', 'step_id' => 'grad_step', 'type' => 'numberbox', 'marks' => 1, 'role' => 'gradient', 'expected' => '2', 'tolerance' => 0.1],
        ];
        return $model;
    }
}
