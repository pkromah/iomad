<?php
// This file is part of Moodle - http://moodle.org/

require_once(__DIR__ . '/../../../config.php');

$context = context_system::instance();
require_login();
require_capability('qtype/structuredsteps:manageconverter', $context);

$url = new moodle_url('/question/type/structuredsteps/convert/index.php');
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('converteradmin', 'qtype_structuredsteps'));
$PAGE->set_heading(get_string('converteradmin', 'qtype_structuredsteps'));

$action = optional_param('action', '', PARAM_ALPHA);
$jobid = optional_param('jobid', 0, PARAM_INT);

$orchestrator = new \qtype_structuredsteps\local\converter\job_orchestrator();
$message = '';
$messagetype = 'notifymessage';

if ($action !== '') {
    require_sesskey();
    if ($action === 'createjob') {
        $config = [
            'sourceqtype' => optional_param('sourceqtype', '', PARAM_ALPHANUMEXT),
            'categoryid' => optional_param('categoryid', 0, PARAM_INT),
            'confidence_threshold' => optional_param('confidence_threshold', 85, PARAM_INT),
            'dryrun' => optional_param('dryrun', 0, PARAM_INT) ? 1 : 0,
            'tenantid' => optional_param('tenantid', 0, PARAM_INT),
            'requestedby' => $USER->id,
        ];
        $newjobid = $orchestrator->queue_job($config);
        $message = get_string('converterjobcreated', 'qtype_structuredsteps', $newjobid);
    } else if ($action === 'runjob' && $jobid > 0) {
        $result = $orchestrator->process_job($jobid);
        $message = get_string('converterjobprocessed', 'qtype_structuredsteps',
            (object)[
                'id' => $result['jobid'],
                'processed' => $result['totalscanned'],
                'review' => $result['totalreview'],
                'failed' => $result['totalfailed'],
            ]
        );
    } else if ($action === 'retryjob' && $jobid > 0) {
        $orchestrator->retry_job($jobid);
        $message = get_string('converterjobretry', 'qtype_structuredsteps', $jobid);
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('converteradmin', 'qtype_structuredsteps'));
echo html_writer::tag('p', s(get_string('converteradminintro', 'qtype_structuredsteps')));

if ($message !== '') {
    echo $OUTPUT->notification($message, $messagetype);
}

$createurl = new moodle_url($url, ['action' => 'createjob', 'sesskey' => sesskey()]);
$form = '';
$form .= html_writer::start_tag('form', ['method' => 'post', 'action' => $createurl]);
$form .= html_writer::start_div('mb-3');
$form .= html_writer::label(get_string('convertersourceqtype', 'qtype_structuredsteps'), 'id_sourceqtype');
$form .= html_writer::empty_tag('input', ['type' => 'text', 'name' => 'sourceqtype', 'id' => 'id_sourceqtype', 'class' => 'form-control', 'placeholder' => 'cloze']);
$form .= html_writer::end_div();
$form .= html_writer::start_div('mb-3');
$form .= html_writer::label(get_string('convertercategoryid', 'qtype_structuredsteps'), 'id_categoryid');
$form .= html_writer::empty_tag('input', ['type' => 'number', 'name' => 'categoryid', 'id' => 'id_categoryid', 'class' => 'form-control', 'value' => '0']);
$form .= html_writer::end_div();
$form .= html_writer::start_div('mb-3');
$form .= html_writer::label(get_string('convertertenantid', 'qtype_structuredsteps'), 'id_tenantid');
$form .= html_writer::empty_tag('input', ['type' => 'number', 'name' => 'tenantid', 'id' => 'id_tenantid', 'class' => 'form-control', 'value' => '0']);
$form .= html_writer::end_div();
$form .= html_writer::start_div('mb-3');
$form .= html_writer::label(get_string('converterconfidence', 'qtype_structuredsteps'), 'id_confidence');
$form .= html_writer::empty_tag('input', ['type' => 'number', 'name' => 'confidence_threshold', 'id' => 'id_confidence', 'class' => 'form-control', 'value' => '85']);
$form .= html_writer::end_div();
$form .= html_writer::start_div('form-check mb-3');
$form .= html_writer::checkbox('dryrun', '1', true, get_string('converterdryrun', 'qtype_structuredsteps'), ['class' => 'form-check-input']);
$form .= html_writer::end_div();
$form .= html_writer::empty_tag('input', ['type' => 'submit', 'class' => 'btn btn-primary', 'value' => get_string('convertercreatejob', 'qtype_structuredsteps')]);
$form .= html_writer::end_tag('form');

echo html_writer::div($form, 'card card-body mb-4');

$jobs = $orchestrator->list_jobs(50);
$table = new html_table();
$table->head = [
    get_string('id'),
    get_string('status'),
    get_string('convertersourceqtype', 'qtype_structuredsteps'),
    get_string('converterdryrun', 'qtype_structuredsteps'),
    get_string('converterprocessed', 'qtype_structuredsteps'),
    get_string('converterreview', 'qtype_structuredsteps'),
    get_string('converterfailed', 'qtype_structuredsteps'),
    get_string('actions'),
];

foreach ($jobs as $job) {
    $runurl = new moodle_url($url, ['action' => 'runjob', 'jobid' => $job->id, 'sesskey' => sesskey()]);
    $retryurl = new moodle_url($url, ['action' => 'retryjob', 'jobid' => $job->id, 'sesskey' => sesskey()]);
    $viewurl = new moodle_url($url, ['jobid' => $job->id]);
    $actions = html_writer::link($runurl, get_string('converterrunjob', 'qtype_structuredsteps'), ['class' => 'btn btn-sm btn-secondary mr-2']) . ' ' .
        html_writer::link($retryurl, get_string('converterretryjob', 'qtype_structuredsteps'), ['class' => 'btn btn-sm btn-outline-secondary mr-2']) . ' ' .
        html_writer::link($viewurl, get_string('converterviewlogs', 'qtype_structuredsteps'), ['class' => 'btn btn-sm btn-link']);

    $table->data[] = [
        (int)$job->id,
        s($job->status),
        s($job->sourceqtype),
        ((int)$job->dryrun === 1 ? get_string('yes') : get_string('no')),
        (int)$job->totalscanned,
        (int)$job->totalreview,
        (int)$job->totalfailed,
        $actions,
    ];
}

echo html_writer::tag('h4', s(get_string('converterjobs', 'qtype_structuredsteps')));
echo html_writer::table($table);

if ($jobid > 0) {
    $logs = $orchestrator->list_job_logs($jobid, 200);
    echo html_writer::tag('h4', s(get_string('converterjoblogs', 'qtype_structuredsteps', $jobid)), ['class' => 'mt-4']);

    $logtable = new html_table();
    $logtable->head = [
        get_string('question'),
        get_string('engine', 'qtype_structuredsteps'),
        get_string('converterconfidence', 'qtype_structuredsteps'),
        get_string('status'),
        get_string('error'),
    ];
    foreach ($logs as $log) {
        $logtable->data[] = [
            (int)$log->oldquestionid,
            s($log->engine),
            (int)$log->confidence,
            s($log->status),
            s($log->errorcode),
        ];
    }
    echo html_writer::table($logtable);
}

echo $OUTPUT->footer();
