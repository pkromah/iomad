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
        $engines = array_combine(
            \qtype_structuredsteps\local\engine_registry::get_available_engines(),
            \qtype_structuredsteps\local\engine_registry::get_available_engines()
        );

        $mform->addElement('select', 'engine', get_string('engine', 'qtype_structuredsteps'), $engines);
        $mform->addHelpButton('engine', 'engine', 'qtype_structuredsteps');
        $mform->setDefault('engine', $this->get_default_value('engine', 'long_multiplication'));

        $mform->addElement('textarea', 'model_json', get_string('modeljson', 'qtype_structuredsteps'), [
            'rows' => 20,
            'cols' => 100,
            'class' => 'w-100 font-monospace',
        ]);
        $mform->addHelpButton('model_json', 'modeljson', 'qtype_structuredsteps');
        $mform->addElement('static', 'model_json_hint', '', get_string('modeljsonauthoringhint', 'qtype_structuredsteps'));
        $mform->setType('model_json', PARAM_RAW);
        $defaultengine = (string)$this->get_default_value('engine', 'long_multiplication');
        $mform->setDefault('model_json', \qtype_structuredsteps\local\model_template_factory::starter_json($defaultengine));

        $this->add_interactive_settings();
    }

    protected function data_preprocessing($question) {
        $question = parent::data_preprocessing($question);
        $question = $this->data_preprocessing_hints($question);

        if (!empty($question->options)) {
            $question->engine = $question->options->engine;
            $question->model_json = $question->options->model_json;
        } else if (empty($question->model_json)) {
            $engine = !empty($question->engine) ? (string)$question->engine : (string)$this->get_default_value('engine', 'long_multiplication');
            $question->model_json = \qtype_structuredsteps\local\model_template_factory::starter_json($engine);
        }

        return $question;
    }

    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        $engine = (string)($data['engine'] ?? 'long_multiplication');
        $modeljson = trim((string)($data['model_json'] ?? ''));
        if ($modeljson === '') {
            return $errors;
        }

        $validator = new \qtype_structuredsteps\local\model_validator();
        $error = $validator->validate($modeljson, $engine);
        if ($error !== null) {
            $errors['model_json'] = get_string('invalidmodeljson', 'qtype_structuredsteps', $error);
        }

        return $errors;
    }

    public function qtype() {
        return 'structuredsteps';
    }

}
