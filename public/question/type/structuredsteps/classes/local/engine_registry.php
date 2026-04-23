<?php
// This file is part of Moodle - http://moodle.org/

namespace qtype_structuredsteps\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Engine registry for qtype_structuredsteps.
 */
class engine_registry {
    /** @var string[] */
    private const SUPPORTED_ENGINES = [
        'long_multiplication',
        'long_division',
        'step_calculation',
        'ledger_poa',
        'stoichiometry',
        'evidence_table',
        'graphing',
        // Phase 2 engines
        'trace',
        'graph',
        'evidence_mapping',
        // Primary school CLOZE conversion engines (D-PSQC-005)
        'AlgorithmicWorkingEngine',
        'StepCalculationEngine',
    ];

    /** @var string[] */
    private const AVAILABLE_ENGINES = [
        'long_multiplication',
        'long_division',
        'step_calculation',
        'ledger_poa',
        'stoichiometry',
        'evidence_table',
        'graphing',
        // Phase 2 engines
        'trace',
        'graph',
        'evidence_mapping',
        // Primary school CLOZE conversion engines (D-PSQC-005)
        'AlgorithmicWorkingEngine',
        'StepCalculationEngine',
    ];

    /**
     * @return string[]
     */
    public static function get_supported_engines(): array {
        return self::SUPPORTED_ENGINES;
    }

    /**
     * Engines currently implemented in runtime.
     *
     * @return string[]
     */
    public static function get_available_engines(): array {
        return self::AVAILABLE_ENGINES;
    }

    /**
     * @param string $engine
     * @return bool
     */
    public static function is_supported(string $engine): bool {
        return in_array($engine, self::get_supported_engines(), true);
    }

    /**
     * @param string $engine
     * @return bool
     */
    public static function is_available(string $engine): bool {
        return in_array($engine, self::get_available_engines(), true);
    }
}
