<?php
// This file is part of Moodle - http://moodle.org/

/**
 * Strings for qtype_structuredsteps.
 *
 * @package   qtype_structuredsteps
 */

$string['pluginname'] = 'Structured Steps';
$string['pluginname_help'] = 'A step-based question type that stores question structure as JSON and applies deterministic grading.';
$string['pluginname_link'] = 'question/type/structuredsteps';
$string['pluginnameadding'] = 'Adding a Structured Steps question';
$string['pluginnameediting'] = 'Editing a Structured Steps question';
$string['pluginnamesummary'] = 'A process-oriented, step-based question type for structured marking.';

$string['engine'] = 'Engine';
$string['engine_help'] = 'Select the engine that interprets the model JSON for rendering and grading.';
$string['modeljson'] = 'Model JSON';
$string['modeljson_help'] = 'Versioned JSON model used by renderer and grader.';
$string['modeljsonauthoringhint'] = 'Authoring MVP: keep this JSON valid and aligned with the selected engine. If left empty, a valid starter model is generated automatically on save.';
$string['invalidmodeljson'] = 'Model JSON is invalid: {$a}';
$string['emptyusesstartermodel'] = 'Model JSON is empty. A starter model for engine "{$a}" will be generated on save.';
$string['missinganswer'] = 'Please enter an answer.';
$string['correctansweris'] = 'Correct answer is: {$a}';
$string['step'] = 'Step';
$string['stepmeta'] = 'Type: {$a}';
$string['unassignedfields'] = 'Additional fields';
$string['privacy:preference:engine'] = 'Default engine preference for structuredsteps question editing.';
$string['fieldcorrect'] = 'Correct ({$a->awarded}/{$a->max})';
$string['fieldincorrect'] = 'Check this field ({$a->awarded}/{$a->max})';
$string['stepscore'] = 'Score: {$a->awarded}/{$a->max}';
$string['graphplothelper'] = 'Plot required points within tolerance ({$a}) and line data on mobile-friendly canvas.';
$string['tokenbankhelper'] = 'Type to select a keyword/phrase from expected tokens.';
$string['unitpickerhelper'] = 'Select the correct unit from the list.';
$string['mathfieldhelper'] = 'Enter your equation or symbolic expression.';
$string['explanationhelper'] = 'Provide a clear explanation (minimum {$a} characters).';
$string['n8n_enabled'] = 'Enable n8n conversion integration';
$string['n8n_enabled_desc'] = 'When enabled, structuredsteps can send conversion payloads to a configured n8n webhook.';
$string['n8n_endpoint'] = 'n8n webhook endpoint';
$string['n8n_endpoint_desc'] = 'HTTPS webhook URL for conversion requests.';
$string['n8n_token'] = 'n8n API token';
$string['n8n_token_desc'] = 'Optional bearer token used in Authorization headers.';
$string['n8n_timeout'] = 'n8n request timeout (seconds)';
$string['n8n_timeout_desc'] = 'HTTP timeout for webhook requests. Must be a positive integer.';
$string['converteradmin'] = 'Bulk converter';
$string['converteradminintro'] = 'Queue and process deterministic conversion jobs from legacy questions into StructuredSteps proposals.';
$string['convertercreatejob'] = 'Create conversion job';
$string['converterjobcreated'] = 'Conversion job #{$a} created.';
$string['converterjobprocessed'] = 'Job #{$a->id} processed. Scanned: {$a->processed}, review: {$a->review}, failed: {$a->failed}.';
$string['converterjobretry'] = 'Job #{$a} marked for retry.';
$string['converterjobs'] = 'Conversion jobs';
$string['converterjoblogs'] = 'Conversion logs for job #{$a}';
$string['converterviewlogs'] = 'View logs';
$string['converterrunjob'] = 'Run job';
$string['converterretryjob'] = 'Retry';
$string['convertersourceqtype'] = 'Source question type filter';
$string['convertercategoryid'] = 'Category ID filter';
$string['convertertenantid'] = 'Tenant ID';
$string['converterconfidence'] = 'Confidence threshold';
$string['converterdryrun'] = 'Dry run only';
$string['converterprocessed'] = 'Processed';
$string['converterreview'] = 'Review';
$string['converterfailed'] = 'Failed';
$string['hinttoggle'] = 'Hint';
$string['inputdock_prev'] = 'Prev';
$string['inputdock_next'] = 'Next';
$string['inputdock_backspace'] = 'Backspace';
$string['inputdock_clear'] = 'Clear';
$string['inputdock_done'] = 'Done';
// QSS-037: descriptive aria-labels for dock buttons (WCAG 2.1 §4.1.2 Name, Role, Value).
// QSS-040: screen-reader step counter, e.g. "Step 2 of 5".
$string['stepcounter'] = 'Step {$a->num} of {$a->total}';
$string['inputdock_region'] = 'Step navigation';
$string['inputdock_prev_aria'] = 'Go to previous step';
$string['inputdock_next_aria'] = 'Go to next step';
$string['inputdock_backspace_aria'] = 'Remove last character from current field';
$string['inputdock_clear_aria'] = 'Clear current answer';
$string['inputdock_done_aria'] = 'Confirm answer for this step';
$string['n8nendpointinsecure'] = 'n8n endpoint must use HTTPS.';
$string['privacy:metadata:qtype_structuredsteps_analytics'] = 'Stores per-attempt structuredsteps analytics rows for reporting.';
$string['privacy:metadata:qtype_structuredsteps_analytics:questionid'] = 'Question ID tied to the attempt fact.';
$string['privacy:metadata:qtype_structuredsteps_analytics:attemptid'] = 'Attempt ID tied to the analytics fact.';
$string['privacy:metadata:qtype_structuredsteps_analytics:userid'] = 'User ID associated with the attempt fact.';
$string['privacy:metadata:qtype_structuredsteps_analytics:engine'] = 'Engine used for grading this attempt.';
$string['privacy:metadata:qtype_structuredsteps_analytics:step_error_summary'] = 'JSON summary of step-level outcomes and error counts.';
$string['privacy:metadata:qtype_structuredsteps_analytics:dominant_error'] = 'Most frequent error type for this analytics row.';
$string['privacy:metadata:qtype_structuredsteps_analytics:total_score'] = 'Awarded score for the attempt.';
$string['privacy:metadata:qtype_structuredsteps_analytics:max_score'] = 'Maximum score for the attempt.';
$string['privacy:metadata:qtype_structuredsteps_analytics:tenantid'] = 'Tenant identifier for multitenant scoping.';
$string['privacy:metadata:qtype_structuredsteps_analytics:timecreated'] = 'Unix timestamp when the analytics row was created.';
$string['privacy:metadata:qtype_structuredsteps_cjob'] = 'Stores converter job orchestration metadata.';
$string['privacy:metadata:qtype_structuredsteps_cjob:requestedby'] = 'User who requested the conversion job.';
$string['privacy:metadata:qtype_structuredsteps_cjob:approvedby'] = 'User who approved the conversion job.';
$string['privacy:metadata:qtype_structuredsteps_cjob:payloadjson'] = 'Serialized converter job payload.';
$string['privacy:metadata:qtype_structuredsteps_cjob:reportjson'] = 'Serialized converter job report summary.';
$string['privacy:metadata:qtype_structuredsteps_cjob:timecreated'] = 'Unix timestamp when the conversion job was created.';
$string['privacy:metadata:qtype_structuredsteps_cjob:timemodified'] = 'Unix timestamp when the conversion job was last modified.';
$string['privacy:metadata:qtype_structuredsteps_clog'] = 'Stores converter per-question audit log entries.';
$string['privacy:metadata:qtype_structuredsteps_clog:createdby'] = 'User who created the log entry.';
$string['privacy:metadata:qtype_structuredsteps_clog:oldquestionid'] = 'Original legacy question ID.';
$string['privacy:metadata:qtype_structuredsteps_clog:newquestionid'] = 'Created structuredsteps question ID, if generated.';
$string['privacy:metadata:qtype_structuredsteps_clog:rawproposal'] = 'Raw converter proposal payload for diagnostics.';
$string['privacy:metadata:qtype_structuredsteps_clog:timecreated'] = 'Unix timestamp when the log entry was created.';
