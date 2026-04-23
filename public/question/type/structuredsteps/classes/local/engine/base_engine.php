<?php
// This file is part of Moodle - http://moodle.org/

namespace qtype_structuredsteps\local\engine;

defined('MOODLE_INTERNAL') || die();

/**
 * Base engine contract for structuredsteps engines.
 */
abstract class base_engine {
    /**
     * Validate response structure against a model.
     *
     * @param array $response
     * @param array $model
     * @return array{is_valid:bool,errors:array}
     */
    abstract public function validate_response(array $response, array $model): array;

    /**
     * Grade a response against a model.
     *
     * @param array $response
     * @param array $model
     * @return array
     */
    abstract public function grade(array $response, array $model): array;

    /**
     * Classify response errors against model.
     *
     * @param array $response
     * @param array $model
     * @return array
     */
    abstract public function classify_errors(array $response, array $model): array;

    /**
     * Return engine metadata.
     *
     * @return array
     */
    abstract public function get_metadata(): array;
}
