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
$string['invalidmodeljson'] = 'Model JSON is invalid: {$a}';
$string['missinganswer'] = 'Please enter an answer.';
$string['correctansweris'] = 'Correct answer is: {$a}';
$string['privacy:preference:engine'] = 'Default engine preference for structuredsteps question editing.';
