<?php
// This file is part of Moodle - http://moodle.org/

/**
 * Editing form for qtype_structuredsteps.
 *
 * @package   qtype_structuredsteps
 */

defined('MOODLE_INTERNAL') || die();

/**
 * qtype_structuredsteps editing form.
 */
class qtype_structuredsteps_edit_form extends question_edit_form {
    protected function definition_inner($mform) {
        $engines = [
            'long_multiplication' => 'long_multiplication',
            'long_division' => 'long_division',
            'step_calculation' => 'step_calculation',
            'ledger_poa' => 'ledger_poa',
            'stoichiometry' => 'stoichiometry',
            'evidence_table' => 'evidence_table',
            'graphing' => 'graphing',
        ];

        $mform->addElement('select', 'engine', get_string('engine', 'qtype_structuredsteps'), $engines);
        $mform->addHelpButton('engine', 'engine', 'qtype_structuredsteps');
        $mform->setDefault('engine', $this->get_default_value('engine', 'long_multiplication'));

        $mform->addElement('textarea', 'model_json', get_string('modeljson', 'qtype_structuredsteps'), [
            'rows' => 20,
            'cols' => 100,
            'class' => 'w-100 font-monospace',
        ]);
        $mform->addHelpButton('model_json', 'modeljson', 'qtype_structuredsteps');
        $mform->setType('model_json', PARAM_RAW);
        $mform->setDefault('model_json', json_encode([
            'schema_version' => '1.0',
            'engine' => 'long_multiplication',
            'engine_version' => '1.0',
            'metadata' => new stdClass(),
            'params' => new stdClass(),
            'layout' => new stdClass(),
            'steps' => [],
            'fields' => [],
            'grading' => [
                'expected_response' => '',
            ],
            'feedback_rules' => [],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->add_interactive_settings();
    }

    protected function data_preprocessing($question) {
        $question = parent::data_preprocessing($question);
        $question = $this->data_preprocessing_hints($question);

        if (!empty($question->options)) {
            $question->engine = $question->options->engine;
            $question->model_json = $question->options->model_json;
        }

        return $question;
    }

    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        $validator = new \qtype_structuredsteps\local\model_validator();
        $error = $validator->validate((string) ($data['model_json'] ?? ''));
        if ($error !== null) {
            $errors['model_json'] = get_string('invalidmodeljson', 'qtype_structuredsteps', $error);
        }

        return $errors;
    }

    public function qtype() {
        return 'structuredsteps';
    }
}
