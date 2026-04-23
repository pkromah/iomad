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
        /** @var qtype_structuredsteps_question $question */
        $question = $qa->get_question();

        $steps = $question->get_model_steps();
        $fields = $question->get_model_fields();
        $params = $question->get_model_params();
        $currentresponse = $this->current_response($qa, $question, $fields);
        $gradingdetails = $this->resolve_grading_details($question, $options, $currentresponse, $fields);
        $runtimeconfig = $this->build_runtime_config($qa, $params, $steps, $fields);
        // Normalise $$ delimiters before format_text so the mathjaxloader filter
        // sees \(...\) and MathJax renders them inline.
        $rawtextformat = $question->questiontextformat ?? FORMAT_HTML;
        $rawqtext = self::normalise_math_delimiters($question->questiontext ?? '');
        $qtext = preg_replace('/\[\[\d+\]\]/', '',
            format_text($rawqtext, $rawtextformat));
        $out = $this->render_from_template('qtype_structuredsteps/question', [
            'qtext' => $qtext,
            'runtimeconfig' => $runtimeconfig,
            'hasstructuredfields' => !empty($fields),
            'steps' => !empty($fields) ? $this->build_step_contexts($qa, $options, $question, $steps, $fields, $params, $gradingdetails) : [],
            'fallbackhtml' => empty($fields) ? $this->render_fallback_answer_box($qa, $options, $question) : '',
            'dock_prev_label' => get_string('inputdock_prev', 'qtype_structuredsteps'),
            'dock_next_label' => get_string('inputdock_next', 'qtype_structuredsteps'),
            'dock_backspace_label' => get_string('inputdock_backspace', 'qtype_structuredsteps'),
            'dock_clear_label' => get_string('inputdock_clear', 'qtype_structuredsteps'),
            'dock_done_label' => get_string('inputdock_done', 'qtype_structuredsteps'),
            // QSS-037: descriptive aria-labels for dock buttons (WCAG 2.1 §4.1.2).
            'dock_nav_region_label' => get_string('inputdock_region', 'qtype_structuredsteps'),
            'dock_prev_aria' => get_string('inputdock_prev_aria', 'qtype_structuredsteps'),
            'dock_next_aria' => get_string('inputdock_next_aria', 'qtype_structuredsteps'),
            'dock_backspace_aria' => get_string('inputdock_backspace_aria', 'qtype_structuredsteps'),
            'dock_clear_aria' => get_string('inputdock_clear_aria', 'qtype_structuredsteps'),
            'dock_done_aria' => get_string('inputdock_done_aria', 'qtype_structuredsteps'),
        ]);

        if (!empty($fields)) {
            $this->page->requires->js_call_amd('qtype_structuredsteps/runtime', 'initAll');
        }

        if ($qa->get_state() == question_state::$invalid) {
            $out .= html_writer::nonempty_tag('div',
                $question->get_validation_error($currentresponse),
                ['class' => 'validationerror']
            );
        }

        return $out;
    }

    public function correct_response(question_attempt $qa) {
        /** @var qtype_structuredsteps_question $question */
        $question = $qa->get_question();
        $response = $question->get_correct_response();
        if (empty($response) || !is_array($response)) {
            return '';
        }

        $items = [];
        foreach ($question->get_model_fields() as $field) {
            if (empty($field['id'])) {
                continue;
            }

            $fieldid = (string)$field['id'];
            $fieldvar = $question->get_field_var_name($fieldid);
            if (array_key_exists($fieldvar, $response) && trim((string)$response[$fieldvar]) !== '') {
                $label = $this->resolve_field_label($field, $fieldid);
                $items[] = html_writer::tag('li',
                    s($label) . ': ' . format_text(self::normalise_math_delimiters((string)$response[$fieldvar]), FORMAT_HTML)
                );
            }
        }

        if (!empty($response['answer']) && trim((string)$response['answer']) !== '') {
            $items[] = html_writer::tag('li',
                s(get_string('answer', 'question')) . ': ' . format_text(self::normalise_math_delimiters((string)$response['answer']), FORMAT_HTML)
            );
        }

        if (empty($items)) {
            return '';
        }

        return get_string('correctansweris', 'qtype_structuredsteps', html_writer::tag('ul', implode('', $items), ['class' => 'mb-0']));
    }

    private function render_fallback_answer_box(question_attempt $qa, question_display_options $options, qtype_structuredsteps_question $question): string {
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

        $answerlabel = html_writer::tag('label',
            $options->add_question_identifier_to_label(get_string('answer', 'question'), true),
            ['for' => $inputname]
        );
        $textarea = html_writer::tag('textarea', s((string)$current), $attrs);

        return html_writer::tag('div', $answerlabel . $textarea, ['class' => 'ablock']);
    }

    private function render_structured_fields(
        question_attempt $qa,
        question_display_options $options,
        qtype_structuredsteps_question $question,
        array $steps,
        array $fields,
        array $params,
        ?array $gradingdetails
    ): string {
        $stepcontexts = $this->build_step_contexts($qa, $options, $question, $steps, $fields, $params, $gradingdetails);

        return html_writer::tag('div', $this->render_from_template('qtype_structuredsteps/question', [
            'qtext' => '',
            'runtimeconfig' => '',
            'hasstructuredfields' => true,
            'steps' => $stepcontexts,
            'fallbackhtml' => '',
        ]), ['class' => 'ablock structuredsteps-form structuredsteps-mobile-first']);
    }

    /**
     * Build template-ready step contexts preserving current deterministic render behavior.
     *
     * @return array<int,array<string,mixed>>
     */
    private function build_step_contexts(
        question_attempt $qa,
        question_display_options $options,
        qtype_structuredsteps_question $question,
        array $steps,
        array $fields,
        array $params,
        ?array $gradingdetails
    ): array {
        $fieldsbystep = $this->group_fields_by_step($fields);
        $sections = [];

        // QSS-040/041: pre-count answer steps (all steps with fields, minus the context step at index 0).
        $matchcount = 0;
        foreach ($steps as $step) {
            if (is_array($step) && !empty($step['id']) && !empty($fieldsbystep[(string)$step['id']])) {
                $matchcount++;
            }
        }
        $totalanswersteps = max(0, $matchcount - 1); // step 0 is context, not counted

        $stepindex = 0;
        $answerstepnum = 0;

        foreach ($steps as $step) {
            if (!is_array($step) || empty($step['id'])) {
                continue;
            }

            $stepid = (string)$step['id'];
            if (empty($fieldsbystep[$stepid])) {
                continue;
            }

            $iscontext = ($stepindex === 0);
            if (!$iscontext) {
                $answerstepnum++;
            }

            $sections[] = $this->build_step_context(
                $qa, $options, $question, $step, $fieldsbystep[$stepid], $params, $gradingdetails,
                $stepindex, $iscontext, $answerstepnum, $totalanswersteps
            );
            unset($fieldsbystep[$stepid]);
            $stepindex++;
        }

        foreach ($fieldsbystep as $remainingfields) {
            if (empty($remainingfields)) {
                continue;
            }
            $answerstepnum++;
            $sections[] = $this->build_step_context($qa, $options, $question, [
                'id' => 'other',
                'label' => get_string('unassignedfields', 'qtype_structuredsteps'),
                'type' => 'misc',
            ], $remainingfields, $params, $gradingdetails,
                $stepindex, false, $answerstepnum, $totalanswersteps
            );
            $stepindex++;
        }

        return $sections;
    }

    /**
     * @return array<string,mixed>
     */
    private function build_step_context(
        question_attempt $qa,
        question_display_options $options,
        qtype_structuredsteps_question $question,
        array $step,
        array $fields,
        array $params,
        ?array $gradingdetails,
        int $stepindex = 0,
        bool $iscontext = false,
        int $answerstepnum = 0,
        int $totalanswersteps = 0
    ): array {
        $stepid = (string)($step['id'] ?? '');
        $steplabel = isset($step['label']) && trim((string)$step['label']) !== ''
            ? (string)$step['label']
            : get_string('step', 'qtype_structuredsteps') . ($stepid !== '' ? ' ' . $stepid : '');
        $steptype = (string)($step['type'] ?? '');

        // QSS-040: add ss-step-card base class and step position data attributes for AMD.
        // QSS-041: add ss-step-card--context modifier for step 0.
        $cardclass = 'ss-step-card card mb-3';
        if ($iscontext) {
            $cardclass .= ' ss-step-card--context';
        }
        $stepmeta = '';
        if ($gradingdetails !== null && isset($gradingdetails['step_results'][$stepid]) && is_array($gradingdetails['step_results'][$stepid])) {
            $stepresult = $gradingdetails['step_results'][$stepid];
            $iscorrect = !empty($stepresult['is_correct']);
            $cardclass .= $iscorrect ? ' border-success' : ' border-danger';
            $a = (object)[
                'awarded' => format_float((float)($stepresult['awarded_marks'] ?? 0), -1, true),
                'max' => format_float((float)($stepresult['max_marks'] ?? 0), -1, true),
            ];
            $stepmeta = html_writer::tag('small', s(get_string('stepscore', 'qtype_structuredsteps', $a)), ['class' => $iscorrect ? 'text-success' : 'text-danger']);
        }
        // QSS-036: "Type: {type}" is authoring metadata and must not appear in the student-facing view.
        // It is intentionally not rendered here; step type is available via data-ss-field-type on field elements.

        $rows = [];
        foreach ($fields as $field) {
            $rows[] = $this->build_field_context($qa, $options, $question, $field, $params, $gradingdetails, $steplabel);
        }

        // QSS-040: step badge (1-indexed answer step number; empty for context card).
        $stepbadge = $iscontext ? '' : (string)$answerstepnum;
        // Screen-reader step counter: "Step N of M" (answer steps only; silent on context).
        $stepcounter = (!$iscontext && $totalanswersteps > 0)
            ? get_string('stepcounter', 'qtype_structuredsteps', (object)['num' => $answerstepnum, 'total' => $totalanswersteps])
            : '';

        return [
            'cardclass' => $cardclass,
            'stepindex' => $stepindex,
            'iscontext' => $iscontext,
            'stepbadge' => $stepbadge,
            'stepcounter' => $stepcounter,
            'steplabel' => format_text(self::normalise_math_delimiters($steplabel), FORMAT_HTML),
            'hasstepmeta' => $stepmeta !== '',
            'stepmetahtml' => $stepmeta,
            'fields' => $rows,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function build_field_context(
        question_attempt $qa,
        question_display_options $options,
        qtype_structuredsteps_question $question,
        array $field,
        array $params,
        ?array $gradingdetails,
        string $steplabel = ''
    ): array {
        $fieldid = (string)$field['id'];
        $fieldvar = $question->get_field_var_name($fieldid);
        $inputname = $qa->get_qt_field_name($fieldvar);
        $current = (string)$qa->get_last_qt_var($fieldvar);
        $label = $this->resolve_field_label($field, $fieldid);
        $stepid = isset($field['step_id']) && trim((string)$field['step_id']) !== ''
            ? (string)$field['step_id']
            : '_unassigned';

        $fieldresult = null;
        if ($gradingdetails !== null && isset($gradingdetails['field_results'][$fieldid]) && is_array($gradingdetails['field_results'][$fieldid])) {
            $fieldresult = $gradingdetails['field_results'][$fieldid];
        }

        $helperid = $inputname . '_helper';
        // QSS-039: pass plain-text step label so render_field_input can set data-ss-label on interactive
        // controls. AMD choice.js reads this to set a meaningful aria-label on the Tom Select wrapper.
        $plainlabel = $steplabel !== '' ? $steplabel : $label;
        $control = $this->render_field_input($field, $params, $inputname, $current, $options->readonly, $fieldresult, $fieldid, $stepid, $label, $helperid, $plainlabel);
        $labelhtml = html_writer::tag('label', s($label), ['for' => $inputname, 'class' => 'mb-0']);
        $helper = $this->render_field_helper_text($field, $params, $helperid);

        $feedback = '';
        if ($fieldresult !== null) {
            $iscorrect = !empty($fieldresult['is_correct']);
            $a = (object)[
                'awarded' => format_float((float)($fieldresult['awarded_marks'] ?? 0), -1, true),
                'max' => format_float((float)($fieldresult['max_marks'] ?? 0), -1, true),
            ];
            $text = $iscorrect
                ? get_string('fieldcorrect', 'qtype_structuredsteps', $a)
                : get_string('fieldincorrect', 'qtype_structuredsteps', $a);
            $feedback = html_writer::tag('small', s($text), ['class' => $iscorrect ? 'text-success' : 'text-danger']);
        }

        $hinttext = $this->resolve_field_hint_text($field, $params);
        $hintid = $inputname . '_hintpanel';

        return [
            'rowclass' => 'form-group row align-items-start structuredsteps-field-row',
            'fieldid' => $fieldid,
            'fieldtype' => (string)($field['type'] ?? 'text'),
            'stepid' => $stepid,
            'labelhtml' => $labelhtml,
            'controlhtml' => $control,
            'helperhtml' => $helper,
            'feedbackhtml' => $feedback,
            'hashint' => $hinttext !== '',
            'hint_toggle_label' => get_string('hinttoggle', 'qtype_structuredsteps'),
            'hint_id' => $hintid,
            'hint_text' => s($hinttext),
        ];
    }

    /**
     * Build lightweight runtime config passed to AMD bootstrap.
     */
    private function build_runtime_config(question_attempt $qa, array $params, array $steps, array $fields): string {
        $layoutmode = isset($params['layout_mode']) && is_string($params['layout_mode']) && trim($params['layout_mode']) !== ''
            ? trim($params['layout_mode'])
            : 'step_card';
        $direction = isset($params['direction']) && is_string($params['direction']) && trim($params['direction']) !== ''
            ? trim($params['direction'])
            : 'ltr';

        $config = [
            'questionAttemptId' => (int)$qa->get_database_id(),
            'layoutMode' => $layoutmode,
            'navigation' => [
                'direction' => $direction,
            ],
            'counts' => [
                'steps' => count($steps),
                'fields' => count($fields),
            ],
        ];

        return json_encode($config, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    private function render_field_input(
        array $field,
        array $params,
        string $inputname,
        string $current,
        bool $readonly,
        ?array $fieldresult,
        string $fieldid,
        string $stepid,
        string $label,
        string $helperid,
        string $steplabel = ''
    ): string {
        $type = (string)($field['type'] ?? 'text');
        $stateclass = '';
        if ($fieldresult !== null) {
            $stateclass = !empty($fieldresult['is_correct']) ? ' is-valid' : ' is-invalid';
        }
        $baseclass = 'form-control ss-field ss-' . $type . $stateclass;
        $describedby = '';

        if ($type === 'structured_text' || $type === 'text') {
            if ($readonly) {
                // In readonly/review mode render a formatted div so MathJax can typeset the content.
                $rendered = format_text(self::normalise_math_delimiters($current), FORMAT_HTML);
                return html_writer::tag('div', $rendered, [
                    'class' => $baseclass . ' ss-field-readonly',
                    'data-ss-readonly' => '1',
                ]);
            }
            $attrs = [
                'name' => $inputname,
                'id' => $inputname,
                'rows' => 2,
                'class' => $baseclass,
                'data-ss-field-id' => $fieldid,
                'data-ss-field-type' => $type,
                'data-ss-step-id' => $stepid,
                'aria-label' => $label,
            ];
            $describedby = $this->resolve_field_helper_text($field, $params) !== '' ? $helperid : '';
            if ($describedby !== '') {
                $attrs['aria-describedby'] = $describedby;
            }
            return html_writer::tag('textarea', s($current), $attrs);
        }

        if ($type === 'choice' && !empty($field['options']) && is_array($field['options'])) {
            $attrs = [
                'name' => $inputname,
                'id' => $inputname,
                'class' => $baseclass,
                'data-ss-field-id' => $fieldid,
                'data-ss-field-type' => $type,
                'data-ss-step-id' => $stepid,
                'aria-label' => $label,
                // QSS-039: step-level label for Tom Select wrapper aria-label (propagated by choice.js).
                'data-ss-label' => $steplabel !== '' ? $steplabel : $label,
            ];
            $describedby = $this->resolve_field_helper_text($field, $params) !== '' ? $helperid : '';
            if ($describedby !== '') {
                $attrs['aria-describedby'] = $describedby;
            }
            if ($readonly) {
                $attrs['disabled'] = 'disabled';
            }
            $options = [];
            foreach ($field['options'] as $value => $text) {
                $optattrs = ['value' => (string)$value];
                if ((string)$value === $current) {
                    $optattrs['selected'] = 'selected';
                }
                $optattrs['data-html'] = format_text(self::normalise_math_delimiters((string)$text), FORMAT_HTML);
                $options[] = html_writer::tag('option', s((string)$text), $optattrs);
            }
            return html_writer::tag('select', implode('', $options), $attrs);
        }

        if ($type === 'unitpicker') {
            $expectedunit = isset($field['expected']) ? (string)$field['expected'] : (string)($params['expected_unit'] ?? '');
            $unitoptions = $this->resolve_unit_options($expectedunit);
            $attrs = [
                'name' => $inputname,
                'id' => $inputname,
                'class' => $baseclass,
                'data-ss-field-id' => $fieldid,
                'data-ss-field-type' => $type,
                'data-ss-step-id' => $stepid,
                'aria-label' => $label,
            ];
            $describedby = $this->resolve_field_helper_text($field, $params) !== '' ? $helperid : '';
            if ($describedby !== '') {
                $attrs['aria-describedby'] = $describedby;
            }
            if ($readonly) {
                $attrs['disabled'] = 'disabled';
            }

            $options = [];
            foreach ($unitoptions as $unit) {
                $optattrs = ['value' => $unit];
                if ($unit === $current) {
                    $optattrs['selected'] = 'selected';
                }
                $options[] = html_writer::tag('option', s($unit), $optattrs);
            }
            return html_writer::tag('select', implode('', $options), $attrs);
        }

        if ($type === 'tokenbank') {
            $tokens = $this->resolve_token_options($field, $params);
            $datalistid = $inputname . '_tokens';
            $attrs = [
                'type' => 'text',
                'name' => $inputname,
                'id' => $inputname,
                'value' => s($current),
                'list' => $datalistid,
                'class' => $baseclass,
                'autocomplete' => 'off',
                'data-ss-field-id' => $fieldid,
                'data-ss-field-type' => $type,
                'data-ss-step-id' => $stepid,
                'aria-label' => $label,
            ];
            $describedby = $this->resolve_field_helper_text($field, $params) !== '' ? $helperid : '';
            if ($describedby !== '') {
                $attrs['aria-describedby'] = $describedby;
            }
            if ($readonly) {
                $attrs['readonly'] = 'readonly';
            }

            $opts = [];
            foreach ($tokens as $token) {
                $opts[] = html_writer::empty_tag('option', ['value' => $token]);
            }
            $datalist = html_writer::tag('datalist', implode('', $opts), ['id' => $datalistid]);
            return html_writer::empty_tag('input', $attrs) . $datalist;
        }

        if ($type === 'graphplot') {
            $attrs = [
                'name' => $inputname,
                'id' => $inputname,
                'rows' => 4,
                'class' => 'form-control font-monospace ss-field ss-' . $type . $stateclass,
                'placeholder' => '{"points":[{"x":1,"y":2}]}',
                'data-widget' => 'graphplot',
                'data-ss-field-id' => $fieldid,
                'data-ss-field-type' => $type,
                'data-ss-step-id' => $stepid,
                'aria-label' => $label,
            ];
            $describedby = $this->resolve_field_helper_text($field, $params) !== '' ? $helperid : '';
            if ($describedby !== '') {
                $attrs['aria-describedby'] = $describedby;
            }
            if ($readonly) {
                $attrs['readonly'] = 'readonly';
            }

            $canvas = html_writer::tag('div', '', [
                'class' => 'border rounded mb-2 structuredsteps-graph-canvas',
                'style' => 'min-height:220px;aspect-ratio:1/1;',
                'data-target' => $inputname,
            ]);
            return $canvas . html_writer::tag('textarea', s($current), $attrs);
        }

        if ($type === 'mathfield') {
            $attrs = [
                'type' => 'text',
                'name' => $inputname,
                'id' => $inputname,
                'value' => s($current),
                'class' => 'form-control font-monospace ss-field ss-' . $type . $stateclass,
                'data-widget' => 'mathfield',
                'autocomplete' => 'off',
                'data-ss-field-id' => $fieldid,
                'data-ss-field-type' => $type,
                'data-ss-step-id' => $stepid,
                'aria-label' => $label,
            ];
            $describedby = $this->resolve_field_helper_text($field, $params) !== '' ? $helperid : '';
            if ($describedby !== '') {
                $attrs['aria-describedby'] = $describedby;
            }
            if ($readonly) {
                $attrs['readonly'] = 'readonly';
            }

            return html_writer::empty_tag('input', $attrs);
        }

        $attrs = [
            'type' => 'text',
            'name' => $inputname,
            'id' => $inputname,
            'value' => s($current),
            'class' => $baseclass,
            'data-ss-field-id' => $fieldid,
            'data-ss-field-type' => $type,
            'data-ss-step-id' => $stepid,
            'aria-label' => $label,
        ];
        $describedby = $this->resolve_field_helper_text($field, $params) !== '' ? $helperid : '';
        if ($describedby !== '') {
            $attrs['aria-describedby'] = $describedby;
        }

        if ($readonly) {
            $attrs['readonly'] = 'readonly';
        }

        if ($type === 'numberbox') {
            $attrs['inputmode'] = 'decimal';
        } else if ($type === 'digitbox') {
            $attrs['inputmode'] = 'numeric';
            $attrs['maxlength'] = isset($field['maxlength']) ? (string)((int)$field['maxlength']) : '1';
        }

        return html_writer::empty_tag('input', $attrs);
    }

    private function render_field_helper_text(array $field, array $params, string $helperid): string {
        $text = $this->resolve_field_helper_text($field, $params);
        if ($text === '') {
            return '';
        }

        return html_writer::tag('small', s($text), ['class' => 'form-text text-muted', 'id' => $helperid]);
    }

    private function resolve_field_helper_text(array $field, array $params): string {
        $type = (string)($field['type'] ?? 'text');
        $role = (string)($field['role'] ?? '');

        if ($type === 'graphplot') {
            $tol = isset($field['tolerance']) ? (string)$field['tolerance'] : (string)($params['tolerance'] ?? '0.1');
            return get_string('graphplothelper', 'qtype_structuredsteps', $tol);
        }

        if ($type === 'tokenbank') {
            return get_string('tokenbankhelper', 'qtype_structuredsteps');
        }

        if ($type === 'unitpicker') {
            return get_string('unitpickerhelper', 'qtype_structuredsteps');
        }

        if ($type === 'mathfield') {
            return get_string('mathfieldhelper', 'qtype_structuredsteps');
        }

        if ($role === 'explanation') {
            $min = isset($params['explanation_min_length']) ? (int)$params['explanation_min_length'] : 20;
            return get_string('explanationhelper', 'qtype_structuredsteps', $min);
        }

        return '';
    }

    /**
     * Resolve optional author-defined hint text for runtime hint panel module.
     */
    private function resolve_field_hint_text(array $field, array $params): string {
        if (!empty($field['hint']) && is_scalar($field['hint'])) {
            return trim((string)$field['hint']);
        }

        if (!empty($field['meta']) && is_array($field['meta']) && !empty($field['meta']['hint']) && is_scalar($field['meta']['hint'])) {
            return trim((string)$field['meta']['hint']);
        }

        $fieldid = !empty($field['id']) ? (string)$field['id'] : '';
        if ($fieldid !== '' && !empty($params['hints']) && is_array($params['hints']) && !empty($params['hints'][$fieldid])) {
            return trim((string)$params['hints'][$fieldid]);
        }

        return '';
    }

    /**
     * @param string $expectedunit
     * @return array<int,string>
     */
    private function resolve_unit_options(string $expectedunit): array {
        $base = ['g', 'kg', 'mol', 'cm3', 'dm3', 'm/s', 'm', 'units'];
        if ($expectedunit !== '' && !in_array($expectedunit, $base, true)) {
            array_unshift($base, $expectedunit);
        }

        return $base;
    }

    /**
     * @param array $field
     * @param array $params
     * @return array<int,string>
     */
    private function resolve_token_options(array $field, array $params): array {
        $tokens = [];
        if (!empty($field['options']) && is_array($field['options'])) {
            foreach ($field['options'] as $value) {
                if (is_scalar($value) || $value === null) {
                    $tokens[] = trim((string)$value);
                }
            }
        }

        if (empty($tokens) && !empty($params['token_options']) && is_array($params['token_options'])) {
            foreach ($params['token_options'] as $value) {
                if (is_scalar($value) || $value === null) {
                    $tokens[] = trim((string)$value);
                }
            }
        }

        $tokens = array_values(array_filter(array_unique($tokens), static function(string $v): bool {
            return $v !== '';
        }));

        return empty($tokens) ? ['Option A', 'Option B', 'Option C'] : $tokens;
    }

    private function resolve_field_label(array $field, string $fallback): string {
        if (!empty($field['label']) && is_string($field['label'])) {
            return $field['label'];
        }

        return $fallback;
    }

    /**
     * Normalise math delimiters for Moodle 5.x / MathJax 3 compatibility.
     *
     * MathJax 3 (as configured in Moodle 5.x) only processes \[...\] for display math
     * and \(...\) for inline math. The legacy $$ delimiter is not enabled by default.
     * Convert $$ markers to inline \(...\) so math renders inline inside flowing text.
     *
     * @param string $text HTML or plain text that may contain $$ ... $$ markers.
     * @return string Text with $$ markers converted to \(...\).
     */
    private static function normalise_math_delimiters(string $text): string {
        return preg_replace('/\$\$(.+?)\$\$/s', '\($1\)', $text);
    }

    private function group_fields_by_step(array $fields): array {
        $grouped = [];

        foreach ($fields as $field) {
            if (!is_array($field) || empty($field['id'])) {
                continue;
            }

            $stepid = isset($field['step_id']) && trim((string)$field['step_id']) !== ''
                ? (string)$field['step_id']
                : '_unassigned';

            if (!isset($grouped[$stepid])) {
                $grouped[$stepid] = [];
            }
            $grouped[$stepid][] = $field;
        }

        return $grouped;
    }

    private function current_response(question_attempt $qa, qtype_structuredsteps_question $question, array $fields): array {
        $response = ['answer' => (string)$qa->get_last_qt_var('answer')];

        foreach ($fields as $field) {
            if (!is_array($field) || empty($field['id'])) {
                continue;
            }
            $fieldvar = $question->get_field_var_name((string)$field['id']);
            $response[$fieldvar] = (string)$qa->get_last_qt_var($fieldvar);
        }

        return $response;
    }

    private function resolve_grading_details(
        qtype_structuredsteps_question $question,
        question_display_options $options,
        array $currentresponse,
        array $fields
    ): ?array {
        if (empty($fields)) {
            return null;
        }

        if (!$options->correctness && !$options->feedback) {
            return null;
        }

        if (!$question->is_complete_response($currentresponse)) {
            return null;
        }

        $grader = new \qtype_structuredsteps\local\grader();
        return $grader->grade_with_details(
            $question->normalise_question_response_for_grader($currentresponse),
            $question->modeljson
        );
    }
}
