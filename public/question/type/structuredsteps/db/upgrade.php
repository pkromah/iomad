<?php
// This file is part of Moodle - http://moodle.org/

/**
 * Upgrade for qtype_structuredsteps.
 *
 * @param int $oldversion old version
 * @return bool
 */
function xmldb_qtype_structuredsteps_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026022202) {
        $table = new xmldb_table('qtype_structuredsteps_analytics');

        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('questionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('attemptid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('engine', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, 'long_multiplication');
        $table->add_field('step_error_summary', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('dominant_error', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, 'none');
        $table->add_field('total_score', XMLDB_TYPE_NUMBER, '12', '5', XMLDB_NOTNULL, null, '0');
        $table->add_field('max_score', XMLDB_TYPE_NUMBER, '12', '5', XMLDB_NOTNULL, null, '0');
        $table->add_field('tenantid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);

        $table->add_index('idx_qssa_tenant_time', XMLDB_INDEX_NOTUNIQUE, ['tenantid', 'timecreated']);
        $table->add_index('idx_qssa_tenant_question_time', XMLDB_INDEX_NOTUNIQUE, ['tenantid', 'questionid', 'timecreated']);
        $table->add_index('idx_qssa_tenant_engine_time', XMLDB_INDEX_NOTUNIQUE, ['tenantid', 'engine', 'timecreated']);
        $table->add_index('idx_qssa_attempt', XMLDB_INDEX_NOTUNIQUE, ['attemptid']);
        $table->add_index('idx_qssa_user_time', XMLDB_INDEX_NOTUNIQUE, ['userid', 'timecreated']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026022202, 'qtype', 'structuredsteps');
    }

    if ($oldversion < 2026022203) {
        $table = new xmldb_table('qtype_structuredsteps_cjob');

        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('tenantid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('categoryid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('sourceqtype', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, 'all');
        $table->add_field('status', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, 'queued');
        $table->add_field('confidence_threshold', XMLDB_TYPE_INTEGER, '3', null, XMLDB_NOTNULL, null, '85');
        $table->add_field('dryrun', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('requestedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('approvedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('runid', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, 'pending');
        $table->add_field('totalscanned', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('totalconverted', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('totalreview', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('totalfailed', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('aiinvoked', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('errorcode', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, 'none');
        $table->add_field('errormessage', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('payloadjson', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('reportjson', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timestarted', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timefinished', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('retrycount', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);

        $table->add_index('idx_qsscjob_status_time', XMLDB_INDEX_NOTUNIQUE, ['status', 'timemodified']);
        $table->add_index('idx_qsscjob_tenant_time', XMLDB_INDEX_NOTUNIQUE, ['tenantid', 'timemodified']);
        $table->add_index('idx_qsscjob_category', XMLDB_INDEX_NOTUNIQUE, ['categoryid']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        $table = new xmldb_table('qtype_structuredsteps_clog');

        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('jobid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('oldquestionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('newquestionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('tenantid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('coursecatid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('engine', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, 'unknown');
        $table->add_field('confidence', XMLDB_TYPE_INTEGER, '3', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('finalconfidence', XMLDB_TYPE_INTEGER, '3', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('status', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, 'manual_review');
        $table->add_field('aiengaged', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('explanation', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('errorcode', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, 'none');
        $table->add_field('rawproposal', XMLDB_TYPE_TEXT, null, null, null, null, null);

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);

        $table->add_index('idx_qssclog_job', XMLDB_INDEX_NOTUNIQUE, ['jobid']);
        $table->add_index('idx_qssclog_status_time', XMLDB_INDEX_NOTUNIQUE, ['status', 'timecreated']);
        $table->add_index('idx_qssclog_tenant_time', XMLDB_INDEX_NOTUNIQUE, ['tenantid', 'timecreated']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026022203, 'qtype', 'structuredsteps');
    }

    return true;
}
