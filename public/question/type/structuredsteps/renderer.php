<?php
// This file is part of Moodle - http://moodle.org/

/**
 * Renderer for qtype_structuredsteps.
 *
 * @package   qtype_structuredsteps
 */

defined('MOODLE_INTERNAL') || die();

/**
 * qtype_structuredsteps renderer.
 */
class qtype_structuredsteps_renderer extends qtype_renderer {
    public function formulation_and_controls(question_attempt $qa, question_display_options $options) {
        $question = $qa->get_question();
        $current = $qa->get_last_qt_var('answer');

        $inputname = $qa->get_qt_field_name('answer');
        $attrs = [
            'name' => $inputname,
            'id' => $inputname,
            'rows' => 6,
            'class' => 'form-control',
        ];
        if ($options->readonly) {
            $attrs['readonly'] = 'readonly';
        }

        $questiontext = html_writer::tag('div', $question->format_questiontext($qa), ['class' => 'qtext']);
        $answerlabel = html_writer::tag('label',
            $options->add_question_identifier_to_label(get_string('answer', 'question'), true),
            ['for' => $inputname]
        );
        $textarea = html_writer::tag('textarea', s($current), $attrs);

        $out = $questiontext;
        $out .= html_writer::tag('div', $answerlabel . $textarea, ['class' => 'ablock']);

        if ($qa->get_state() == question_state::$invalid) {
            $out .= html_writer::nonempty_tag('div',
                $question->get_validation_error(['answer' => $current]),
                ['class' => 'validationerror']
            );
        }

        return $out;
    }

    public function correct_response(question_attempt $qa) {
        $question = $qa->get_question();
        $response = $question->get_correct_response();
        if (empty($response) || !array_key_exists('answer', $response) || trim((string) $response['answer']) === '') {
            return '';
        }
        return get_string('correctansweris', 'qtype_structuredsteps', s($response['answer']));
    }
}
