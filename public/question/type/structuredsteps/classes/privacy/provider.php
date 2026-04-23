<?php
// This file is part of Moodle - http://moodle.org/

namespace qtype_structuredsteps\privacy;

defined('MOODLE_INTERNAL') || die();

use context;
use context_system;
use core_privacy\local\metadata\collection;
use core_privacy\local\metadata\provider as metadata_provider;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\core_userlist_provider;
use core_privacy\local\request\plugin\provider as plugin_provider;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider for qtype_structuredsteps.
 */
class provider implements metadata_provider, plugin_provider, core_userlist_provider {
    /**
     * @param collection $items
     * @return collection
     */
    public static function get_metadata(collection $items): collection {
        $items->add_database_table('qtype_structuredsteps_analytics', [
            'questionid' => 'privacy:metadata:qtype_structuredsteps_analytics:questionid',
            'attemptid' => 'privacy:metadata:qtype_structuredsteps_analytics:attemptid',
            'userid' => 'privacy:metadata:qtype_structuredsteps_analytics:userid',
            'engine' => 'privacy:metadata:qtype_structuredsteps_analytics:engine',
            'step_error_summary' => 'privacy:metadata:qtype_structuredsteps_analytics:step_error_summary',
            'dominant_error' => 'privacy:metadata:qtype_structuredsteps_analytics:dominant_error',
            'total_score' => 'privacy:metadata:qtype_structuredsteps_analytics:total_score',
            'max_score' => 'privacy:metadata:qtype_structuredsteps_analytics:max_score',
            'tenantid' => 'privacy:metadata:qtype_structuredsteps_analytics:tenantid',
            'timecreated' => 'privacy:metadata:qtype_structuredsteps_analytics:timecreated',
        ], 'privacy:metadata:qtype_structuredsteps_analytics');

        $items->add_database_table('qtype_structuredsteps_cjob', [
            'requestedby' => 'privacy:metadata:qtype_structuredsteps_cjob:requestedby',
            'approvedby' => 'privacy:metadata:qtype_structuredsteps_cjob:approvedby',
            'payloadjson' => 'privacy:metadata:qtype_structuredsteps_cjob:payloadjson',
            'reportjson' => 'privacy:metadata:qtype_structuredsteps_cjob:reportjson',
            'timecreated' => 'privacy:metadata:qtype_structuredsteps_cjob:timecreated',
            'timemodified' => 'privacy:metadata:qtype_structuredsteps_cjob:timemodified',
        ], 'privacy:metadata:qtype_structuredsteps_cjob');

        $items->add_database_table('qtype_structuredsteps_clog', [
            'createdby' => 'privacy:metadata:qtype_structuredsteps_clog:createdby',
            'oldquestionid' => 'privacy:metadata:qtype_structuredsteps_clog:oldquestionid',
            'newquestionid' => 'privacy:metadata:qtype_structuredsteps_clog:newquestionid',
            'rawproposal' => 'privacy:metadata:qtype_structuredsteps_clog:rawproposal',
            'timecreated' => 'privacy:metadata:qtype_structuredsteps_clog:timecreated',
        ], 'privacy:metadata:qtype_structuredsteps_clog');

        return $items;
    }

    /**
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        global $DB;

        $contextlist = new contextlist();

        $params = [
            'userid' => $userid,
            'contextid' => context_system::instance()->id,
        ];

        $sql = "SELECT DISTINCT :contextid
                  FROM {
                    qtype_structuredsteps_analytics
                  }
                 WHERE userid = :userid
                 UNION
                SELECT DISTINCT :contextid
                  FROM {qtype_structuredsteps_cjob}
                 WHERE requestedby = :userid OR approvedby = :userid
                 UNION
                SELECT DISTINCT :contextid
                  FROM {qtype_structuredsteps_clog}
                 WHERE createdby = :userid";

        $contextlist->add_from_sql($sql, $params);
        return $contextlist;
    }

    /**
     * @param approved_contextlist $contextlist
     * @return void
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if ((int)$context->contextlevel !== CONTEXT_SYSTEM) {
                continue;
            }

            $writer = writer::with_context($context);

            $analytics = $DB->get_records('qtype_structuredsteps_analytics', ['userid' => $userid], 'timecreated ASC');
            if (!empty($analytics)) {
                $writer->export_data(['analytics'], array_values($analytics));
            }

            $jobs = $DB->get_records_select('qtype_structuredsteps_cjob', 'requestedby = :u OR approvedby = :u', ['u' => $userid], 'timecreated ASC');
            if (!empty($jobs)) {
                $writer->export_data(['converter_jobs'], array_values($jobs));
            }

            $logs = $DB->get_records('qtype_structuredsteps_clog', ['createdby' => $userid], 'timecreated ASC');
            if (!empty($logs)) {
                $writer->export_data(['converter_logs'], array_values($logs));
            }
        }
    }

    /**
     * @param context $context
     * @return void
     */
    public static function delete_data_for_all_users_in_context(context $context): void {
        global $DB;

        if ((int)$context->contextlevel !== CONTEXT_SYSTEM) {
            return;
        }

        $DB->delete_records('qtype_structuredsteps_analytics');
        $DB->execute('UPDATE {qtype_structuredsteps_cjob} SET requestedby = 0 WHERE requestedby > 0');
        $DB->execute('UPDATE {qtype_structuredsteps_cjob} SET approvedby = 0 WHERE approvedby > 0');
        $DB->execute('UPDATE {qtype_structuredsteps_clog} SET createdby = 0 WHERE createdby > 0');
    }

    /**
     * @param approved_contextlist $contextlist
     * @return void
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;

        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if ((int)$context->contextlevel !== CONTEXT_SYSTEM) {
                continue;
            }

            $DB->delete_records('qtype_structuredsteps_analytics', ['userid' => $userid]);
            $DB->set_field('qtype_structuredsteps_cjob', 'requestedby', 0, ['requestedby' => $userid]);
            $DB->set_field('qtype_structuredsteps_cjob', 'approvedby', 0, ['approvedby' => $userid]);
            $DB->set_field('qtype_structuredsteps_clog', 'createdby', 0, ['createdby' => $userid]);
        }
    }

    /**
     * @param userlist $userlist
     * @return void
     */
    public static function get_users_in_context(userlist $userlist): void {
        if ((int)$userlist->get_context()->contextlevel !== CONTEXT_SYSTEM) {
            return;
        }

        $userlist->add_from_sql('userid', 'SELECT DISTINCT userid FROM {qtype_structuredsteps_analytics} WHERE userid > 0', []);
        $userlist->add_from_sql('userid', 'SELECT DISTINCT requestedby AS userid FROM {qtype_structuredsteps_cjob} WHERE requestedby > 0', []);
        $userlist->add_from_sql('userid', 'SELECT DISTINCT approvedby AS userid FROM {qtype_structuredsteps_cjob} WHERE approvedby > 0', []);
        $userlist->add_from_sql('userid', 'SELECT DISTINCT createdby AS userid FROM {qtype_structuredsteps_clog} WHERE createdby > 0', []);
    }

    /**
     * @param approved_userlist $userlist
     * @return void
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;

        if ((int)$userlist->get_context()->contextlevel !== CONTEXT_SYSTEM) {
            return;
        }

        $userids = $userlist->get_userids();
        if (empty($userids)) {
            return;
        }

        [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $DB->delete_records_select('qtype_structuredsteps_analytics', 'userid ' . $insql, $inparams);
        $DB->execute('UPDATE {qtype_structuredsteps_cjob} SET requestedby = 0 WHERE requestedby ' . $insql, $inparams);
        $DB->execute('UPDATE {qtype_structuredsteps_cjob} SET approvedby = 0 WHERE approvedby ' . $insql, $inparams);
        $DB->execute('UPDATE {qtype_structuredsteps_clog} SET createdby = 0 WHERE createdby ' . $insql, $inparams);
    }
}
