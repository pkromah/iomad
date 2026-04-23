<?php
// This file is part of Moodle - http://moodle.org/

namespace qtype_structuredsteps\local\converter\engines;

defined('MOODLE_INTERNAL') || die();

/**
 * Interface for Phase 2 converter engine modules.
 *
 * Each engine is responsible for a domain of questions that Phase 1 rule-based
 * engines (step_calculation, long_multiplication, long_division) do not handle.
 *
 * An engine is selected when the classifier returns its name. The engine then
 * converts the parsed question data into a structured steps model array.
 */
interface ConverterEngineInterface {

    /**
     * Return true if this engine can process the given parsed question.
     *
     * The classifier already routes by topic/keyword; this method provides a
     * secondary guard based on question content when needed.
     *
     * @param array $question_data Parsed question from ddwtos_parser::parse_question().
     * @return bool
     */
    public function canHandle(array $question_data): bool;

    /**
     * Convert a parsed question to a structured steps model.
     *
     * Returns an array with:
     *   - 'steps': array of step objects, each with 'label' and 'content'
     *   - 'engine': string engine name
     *   - 'status': 'auto_convert_eligible' | 'review_queue'
     *   - 'notes': array of string notes (optional diagnostic info)
     *
     * If the question cannot be auto-converted (e.g. image-flagged), status must
     * be 'review_queue' and steps may be empty.
     *
     * @param array $question_data Parsed question from ddwtos_parser::parse_question().
     * @return array{steps:array,engine:string,status:string,notes:array}
     */
    public function convertToSteps(array $question_data): array;

    /**
     * Return the canonical engine name string.
     *
     * This must match the name returned by the classifier (e.g. 'trace', 'graph',
     * 'evidence_mapping').
     *
     * @return string
     */
    public function getEngineName(): string;
}
