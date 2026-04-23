<?php
// This file is part of Moodle - http://moodle.org/

/**
 * Cache definitions for qtype_structuredsteps.
 *
 * @package   qtype_structuredsteps
 */

defined('MOODLE_INTERNAL') || die();

$definitions = [
    'modeldecode' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => true,
        'staticacceleration' => true,
        'staticaccelerationsize' => 256,
        'ttl' => 3600,
    ],
    'analytics_export' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => false,
        'staticacceleration' => true,
        'staticaccelerationsize' => 128,
        'ttl' => 300,
    ],
];
