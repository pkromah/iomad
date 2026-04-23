<?php
// This file is part of Moodle - http://moodle.org/

/**
 * Question type class for qtype_structuredsteps.
 *
 * @package   qtype_structuredsteps
 * @copyright 2026 Pennacool
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/questionlib.php');
require_once($CFG->dirroot . '/question/type/structuredsteps/question.php');

/**
 * qtype_structuredsteps implementation.
 */
class qtype_structuredsteps extends question_type {
    public function extra_question_fields() {
        return ['qtype_structuredsteps_options', 'schema_version', 'engine', 'engine_version', 'model_json', 'timecreated', 'timemodified'];
    }

    public function save_defaults_for_new_questions(stdClass $fromform): void {
        parent::save_defaults_for_new_questions($fromform);
        if (!empty($fromform->engine)) {
            $this->set_default_value('engine', $fromform->engine);
        }
    }

    public function save_question_options($question) {
        global $DB;

        parent::save_question_options($question);

        $modeljson = trim((string) ($question->model_json ?? '{}'));
        $schema = '1.0';
        $engineversion = '1.0';

        if ($record = $DB->get_record('qtype_structuredsteps_options', ['questionid' => $question->id])) {
            $record->schema_version = $schema;
            $record->engine = (string) ($question->engine ?? 'long_multiplication');
            $record->engine_version = $engineversion;
            $record->model_json = $modeljson;
            $record->timemodified = time();
            $DB->update_record('qtype_structuredsteps_options', $record);
        } else {
            $record = new stdClass();
            $record->questionid = $question->id;
            $record->schema_version = $schema;
            $record->engine = (string) ($question->engine ?? 'long_multiplication');
            $record->engine_version = $engineversion;
            $record->model_json = $modeljson;
            $record->timecreated = time();
            $record->timemodified = $record->timecreated;
            $DB->insert_record('qtype_structuredsteps_options', $record);
        }

        $this->save_hints($question);

        return true;
    }

    public function get_question_options($question) {
        global $DB;

        if (!parent::get_question_options($question)) {
            return false;
        }

        $question->options = $DB->get_record('qtype_structuredsteps_options', ['questionid' => $question->id]);
        if (!$question->options) {
            return false;
        }

        return true;
    }

    protected function initialise_question_instance(question_definition $question, $questiondata) {
        parent::initialise_question_instance($question, $questiondata);
        /** @var qtype_structuredsteps_question $question */
        $question->engine = $questiondata->options->engine;
        $question->modeljson = $questiondata->options->model_json;
    }

    public function delete_question($questionid, $contextid) {
        global $DB;
        $DB->delete_records('qtype_structuredsteps_options', ['questionid' => $questionid]);
        parent::delete_question($questionid, $contextid);
    }

    public function get_possible_responses($questiondata) {
        return [
            $questiondata->id => [
                null => question_possible_response::no_response(),
            ],
        ];
    }
}
