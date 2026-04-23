<?php
// This file is part of Moodle - http://moodle.org/

/**
 * Structuredsteps question definition class.
 *
 * @package   qtype_structuredsteps
 * @copyright 2026 Pennacool
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/question/type/questionbase.php');

/**
 * Represents a structuredsteps question.
 */
class qtype_structuredsteps_question extends question_graded_automatically {
    /** @var string */
    public $engine = 'long_multiplication';

    /** @var string */
    public $modeljson = '{}';

    public function get_expected_data() {
        return ['answer' => PARAM_RAW_TRIMMED];
    }

    public function summarise_response(array $response) {
        return $response['answer'] ?? null;
    }

    public function un_summarise_response(string $summary) {
        if ($summary === '') {
            return [];
        }
        return ['answer' => $summary];
    }

    public function is_complete_response(array $response) {
        return array_key_exists('answer', $response) && trim((string) $response['answer']) !== '';
    }

    public function get_validation_error(array $response) {
        if ($this->is_gradable_response($response)) {
            return '';
        }
        return get_string('missinganswer', 'qtype_structuredsteps');
    }

    public function is_same_response(array $prevresponse, array $newresponse) {
        return question_utils::arrays_same_at_key_missing_is_blank($prevresponse, $newresponse, 'answer');
    }

    public function grade_response(array $response) {
        $grader = new \qtype_structuredsteps\local\grader();
        $fraction = $grader->grade_response($response, $this->modeljson);
        return [$fraction, question_state::graded_state_for_fraction($fraction)];
    }

    public function get_correct_response() {
        $grader = new \qtype_structuredsteps\local\grader();
        $correct = $grader->get_expected_response($this->modeljson);
        if ($correct === null) {
            return null;
        }
        return ['answer' => $correct];
    }

    public function get_question_definition_for_external_rendering(question_attempt $qa, question_display_options $options) {
        return [
            'engine' => $this->engine,
            'modeljson' => $this->modeljson,
        ];
    }
}
