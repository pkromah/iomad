<?php
// This file is part of Moodle - http://moodle.org/

/**
 * @package   qtype_structuredsteps
 * @copyright 2026 Pennacool
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Restore plugin class for qtype_structuredsteps.
 */
class restore_qtype_structuredsteps_plugin extends restore_qtype_plugin {
    /**
     * Returns the paths to be handled by the plugin at question level.
     *
     * @return array
     */
    protected function define_question_plugin_structure() {
        $paths = [];
        $elename = 'structuredsteps';
        $elepath = $this->get_pathfor('/structuredsteps');
        $paths[] = new restore_path_element($elename, $elepath);
        return $paths;
    }

    /**
     * Process the qtype/structuredsteps element.
     *
     * @param array $data
     * @return void
     */
    public function process_structuredsteps($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;

        $oldquestionid = $this->get_old_parentid('question');
        $newquestionid = $this->get_new_parentid('question');
        $questioncreated = (bool)$this->get_mappingid('question_created', $oldquestionid);

        if (!$questioncreated) {
            return;
        }

        $record = new stdClass();
        $record->questionid = $newquestionid;
        $record->schema_version = (string)($data->schema_version ?? '1.0');
        $record->engine = (string)($data->engine ?? 'long_multiplication');
        $record->engine_version = (string)($data->engine_version ?? '1.0');
        $record->model_json = (string)($data->model_json ?? '');
        $record->timecreated = time();
        $record->timemodified = $record->timecreated;

        $this->validate_and_log_restore_state($record);

        if (!$DB->record_exists('qtype_structuredsteps_options', ['questionid' => $record->questionid])) {
            $newitemid = $DB->insert_record('qtype_structuredsteps_options', $record);
            $this->set_mapping('qtype_structuredsteps_options', $oldid, $newitemid);
        }
    }

    /**
     * Validate restored payload and emit warnings when integrity risks are detected.
     *
     * @param stdClass $record
     * @return void
     */
    private function validate_and_log_restore_state(stdClass $record): void {
        if (!\qtype_structuredsteps\local\engine_registry::is_supported($record->engine)) {
            $this->log("Structuredsteps restore warning: unsupported engine '{$record->engine}'.", backup::LOG_WARNING);
            return;
        }

        if (!\qtype_structuredsteps\local\engine_registry::is_available($record->engine)) {
            $this->log("Structuredsteps restore warning: unavailable engine '{$record->engine}'.", backup::LOG_WARNING);
        }

        $validator = new \qtype_structuredsteps\local\model_validator();
        $error = $validator->validate($record->model_json, $record->engine);
        if ($error !== null) {
            // Keep canonical payload exactly as backed up, but emit warning for manual follow-up.
            $this->log("Structuredsteps restore warning: model validation failed: {$error}", backup::LOG_WARNING);
        }
    }
}
