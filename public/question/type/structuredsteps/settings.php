<?php
// This file is part of Moodle - http://moodle.org/

/**
 * Settings for qtype_structuredsteps.
 *
 * @package   qtype_structuredsteps
 */

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {
    $settings->add(new admin_setting_configcheckbox(
        'qtype_structuredsteps/n8n_enabled',
        get_string('n8n_enabled', 'qtype_structuredsteps'),
        get_string('n8n_enabled_desc', 'qtype_structuredsteps'),
        0
    ));

    $settings->add(new admin_setting_configtext(
        'qtype_structuredsteps/n8n_endpoint',
        get_string('n8n_endpoint', 'qtype_structuredsteps'),
        get_string('n8n_endpoint_desc', 'qtype_structuredsteps'),
        '',
        PARAM_URL
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'qtype_structuredsteps/n8n_token',
        get_string('n8n_token', 'qtype_structuredsteps'),
        get_string('n8n_token_desc', 'qtype_structuredsteps'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'qtype_structuredsteps/n8n_timeout',
        get_string('n8n_timeout', 'qtype_structuredsteps'),
        get_string('n8n_timeout_desc', 'qtype_structuredsteps'),
        '10',
        PARAM_INT
    ));
}

$ADMIN->add('qtypesettings', new admin_externalpage(
    'qtype_structuredsteps_converter',
    get_string('converteradmin', 'qtype_structuredsteps'),
    new moodle_url('/question/type/structuredsteps/convert/index.php'),
    'qtype/structuredsteps:manageconverter'
));
