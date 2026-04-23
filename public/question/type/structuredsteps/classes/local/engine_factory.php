<?php
// This file is part of Moodle - http://moodle.org/

namespace qtype_structuredsteps\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Factory for structuredsteps engines.
 */
class engine_factory {
    /**
     * @var array<string,string>
     */
    private const ENGINE_CLASSMAP = [
        'long_multiplication' => '\\qtype_structuredsteps\\local\\engine\\long_multiplication_engine',
        'long_division' => '\\qtype_structuredsteps\\local\\engine\\long_division_engine',
        'step_calculation' => '\\qtype_structuredsteps\\local\\engine\\step_calculation_engine',
        'ledger_poa' => '\\qtype_structuredsteps\\local\\engine\\ledger_poa_engine',
        'stoichiometry' => '\\qtype_structuredsteps\\local\\engine\\stoichiometry_engine',
        'evidence_table' => '\\qtype_structuredsteps\\local\\engine\\evidence_table_engine',
        'graphing' => '\\qtype_structuredsteps\\local\\engine\\graphing_engine',
        // Primary school CLOZE conversion engines (D-PSQC-005).
        // Both use field_sum grading with numberbox fields — step_calculation_engine is the correct runtime.
        'AlgorithmicWorkingEngine' => '\\qtype_structuredsteps\\local\\engine\\step_calculation_engine',
        'StepCalculationEngine'    => '\\qtype_structuredsteps\\local\\engine\\step_calculation_engine',
    ];

    /**
     * Create an engine instance.
     *
     * @param string $engine
     * @return \qtype_structuredsteps\local\engine\base_engine|null
     */
    public static function create(string $engine): ?\qtype_structuredsteps\local\engine\base_engine {
        if (!isset(self::ENGINE_CLASSMAP[$engine])) {
            return null;
        }

        $classname = self::ENGINE_CLASSMAP[$engine];
        return new $classname();
    }
}
