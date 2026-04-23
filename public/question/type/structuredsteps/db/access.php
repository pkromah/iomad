<?php
// This file is part of Moodle - http://moodle.org/

/**
 * Capability definitions for qtype_structuredsteps.
 *
 * @package   qtype_structuredsteps
 */

defined('MOODLE_INTERNAL') || die();

$capabilities = [
    'qtype/structuredsteps:manageconverter' => [
        'riskbitmask' => RISK_DATALOSS | RISK_PERSONAL,
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [
            'manager' => CAP_ALLOW,
        ],
    ],
];
