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
    public $schema_version = '1.0';

    /** @var string */
    public $engine_version = '1.0';

    /** @var string */
    public $model_json = '{}';

    /** @var string */
    public $modeljson = '{}';

    /** @var int */
    public $timecreated = 0;

    /** @var int */
    public $timemodified = 0;

    public function get_expected_data() {
        $expected = ['answer' => PARAM_RAW_TRIMMED];

        foreach ($this->get_model_fields() as $field) {
            if (empty($field['id'])) {
                continue;
            }
            $expected[$this->get_field_var_name((string)$field['id'])] = PARAM_RAW_TRIMMED;
        }

        return $expected;
    }

    public function summarise_response(array $response) {
        $responses = $this->extract_field_responses_from_question_response($response);
        if (!empty($responses)) {
            return json_encode($responses);
        }

        return $response['answer'] ?? null;
    }

    public function un_summarise_response(string $summary) {
        if ($summary === '') {
            return [];
        }

        if ($summary[0] === '{') {
            try {
                $decoded = json_decode($summary, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                $decoded = null;
            }

            if (is_array($decoded)) {
                $response = [];
                foreach ($this->get_model_fields() as $field) {
                    if (empty($field['id'])) {
                        continue;
                    }
                    $fieldid = (string)$field['id'];
                    if (array_key_exists($fieldid, $decoded)) {
                        $response[$this->get_field_var_name($fieldid)] = (string)$decoded[$fieldid];
                    }
                }
                return $response;
            }
        }

        return ['answer' => $summary];
    }

    public function is_complete_response(array $response) {
        if (array_key_exists('answer', $response) && trim((string)$response['answer']) !== '') {
            return true;
        }

        $responses = $this->extract_field_responses_from_question_response($response);
        foreach ($responses as $value) {
            if (trim((string)$value) !== '') {
                return true;
            }
        }

        return false;
    }

    public function get_validation_error(array $response) {
        if ($this->is_gradable_response($response)) {
            return '';
        }
        return get_string('missinganswer', 'qtype_structuredsteps');
    }

    public function is_same_response(array $prevresponse, array $newresponse) {
        if (!question_utils::arrays_same_at_key_missing_is_blank($prevresponse, $newresponse, 'answer')) {
            return false;
        }

        foreach ($this->get_model_fields() as $field) {
            if (empty($field['id'])) {
                continue;
            }
            $fieldvar = $this->get_field_var_name((string)$field['id']);
            if (!question_utils::arrays_same_at_key_missing_is_blank($prevresponse, $newresponse, $fieldvar)) {
                return false;
            }
        }

        return true;
    }

    public function grade_response(array $response) {
        $grader = new \qtype_structuredsteps\local\grader();
        $fraction = $grader->grade_response($this->normalise_response_for_grader($response), $this->modeljson);
        return [$fraction, question_state::graded_state_for_fraction($fraction)];
    }

    public function get_correct_response() {
        $grader = new \qtype_structuredsteps\local\grader();
        $correct = $grader->get_expected_response($this->modeljson);

        $response = [];
        foreach ($this->get_model_fields() as $field) {
            if (empty($field['id'])) {
                continue;
            }
            $fieldid = (string)$field['id'];
            if (array_key_exists('expected', $field)) {
                $response[$this->get_field_var_name($fieldid)] = (string)$field['expected'];
            }
        }

        if ($correct !== null) {
            $response['answer'] = $correct;
        }

        return empty($response) ? null : $response;
    }

    public function get_question_definition_for_external_rendering(question_attempt $qa, question_display_options $options) {
        return [
            'engine' => $this->engine,
            'modeljson' => $this->modeljson,
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function get_model_steps(): array {
        $model = $this->decode_model();
        if ($model === null || empty($model['steps']) || !is_array($model['steps'])) {
            return [];
        }

        return $model['steps'];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function get_model_fields(): array {
        $model = $this->decode_model();
        if ($model === null || empty($model['fields']) || !is_array($model['fields'])) {
            return [];
        }

        return $model['fields'];
    }

    /**
     * @return array<string,mixed>
     */
    public function get_model_params(): array {
        $model = $this->decode_model();
        if ($model === null || empty($model['params']) || !is_array($model['params'])) {
            return [];
        }

        return $model['params'];
    }

    /**
     * @param string $fieldid
     * @return string
     */
    public function get_field_var_name(string $fieldid): string {
        $normalised = preg_replace('/[^a-zA-Z0-9_]/', '_', $fieldid);
        return 'resp_' . $normalised;
    }

    /**
     * @param array $response
     * @return array
     */
    /**
     * Normalise question response into grader payload shape.
     *
     * @param array $response
     * @return array
     */
    public function normalise_question_response_for_grader(array $response): array {
        return $this->normalise_response_for_grader($response);
    }

    private function normalise_response_for_grader(array $response): array {
        if (isset($response['responses']) && is_array($response['responses'])) {
            return $response;
        }

        $response['responses'] = $this->extract_field_responses_from_question_response($response);
        return $response;
    }

    /**
     * @param array $response
     * @return array<string,string>
     */
    private function extract_field_responses_from_question_response(array $response): array {
        $responses = [];

        foreach ($this->get_model_fields() as $field) {
            if (empty($field['id'])) {
                continue;
            }

            $fieldid = (string)$field['id'];
            $fieldvar = $this->get_field_var_name($fieldid);
            if (array_key_exists($fieldvar, $response)) {
                $responses[$fieldid] = trim((string)$response[$fieldvar]);
            }
        }

        return $responses;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function decode_model(): ?array {
        try {
            $decoded = json_decode($this->modeljson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}
