<?php
// This file is part of Moodle - http://moodle.org/

/**
 * Bounded rollback test for CLOZE conversion jobs.
 *
 * Covers TASK-PSQC-013 acceptance criteria:
 * - --rollback <job_id> removes imported questions from mdl_question.
 * - Deletes qtype_structuredsteps_options rows.
 * - Deletes qtype_structuredsteps_clog entries for the job.
 * - Returns success=true, deleted_count matches import count.
 * - A second rollback of the same job returns deleted_count=0 (idempotent).
 * - Rollback does not touch questions from a different job_id.
 *
 * Uses advanced_testcase to run against the Moodle test DB (bht_* prefix).
 * All records created are cleaned up by the test harness via resetAfterTest().
 *
 * @package qtype_structuredsteps
 */

defined('MOODLE_INTERNAL') || die();

/**
 * CLOZE rollback bounded tests.
 */
class qtype_structuredsteps_cloze_rollback_test extends advanced_testcase {

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * Insert a minimal question + QBE + question_versions + options + clog row,
     * simulating what import_question() does for a CLOZE CLI job.
     *
     * Returns the new question id.
     */
    private function insert_fake_import(string $job_id, string $q_name, int $categoryid): int {
        global $DB;

        $now = time();

        // question row
        $q = new \stdClass();
        $q->parent               = 0;
        $q->name                 = $q_name;
        $q->questiontext         = '<p>Test question.</p>';
        $q->questiontextformat   = FORMAT_HTML;
        $q->generalfeedback      = '';
        $q->generalfeedbackformat = FORMAT_HTML;
        $q->defaultmark          = 1.0;
        $q->penalty              = 0.0;
        $q->qtype                = 'structuredsteps';
        $q->length               = 1;
        $q->stamp                = 'teststamp_' . uniqid();
        $q->timecreated          = $now;
        $q->timemodified         = $now;
        $q->createdby            = 0;
        $q->modifiedby           = 0;
        $newid = (int)$DB->insert_record('question', $q);

        // question_bank_entries
        $qbe = new \stdClass();
        $qbe->questioncategoryid = $categoryid;
        $qbe->idnumber           = null;
        $qbe->ownerid            = 0;
        $qbe->id = (int)$DB->insert_record('question_bank_entries', $qbe);

        // question_versions
        $qv = new \stdClass();
        $qv->questionbankentryid = $qbe->id;
        $qv->questionid          = $newid;
        $qv->version             = 1;
        $qv->status              = \core_question\local\bank\question_version_status::QUESTION_STATUS_READY;
        $DB->insert_record('question_versions', $qv);

        // structuredsteps_options
        $opts = new \stdClass();
        $opts->questionid     = $newid;
        $opts->schema_version = '1.0';
        $opts->engine         = 'AlgorithmicWorkingEngine';
        $opts->engine_version = '1.0';
        $opts->model_json     = '{}';
        $opts->timecreated    = $now;
        $opts->timemodified   = $now;
        $DB->insert_record('qtype_structuredsteps_options', $opts);

        // clog (what job_orchestrator::import_question writes)
        $log = new \stdClass();
        $log->jobid          = 0;
        $log->oldquestionid  = 0;
        $log->newquestionid  = $newid;
        $log->tenantid       = 0;
        $log->coursecatid    = $categoryid;
        $log->engine         = 'AlgorithmicWorkingEngine';
        $log->confidence     = 65;
        $log->finalconfidence = 65;
        $log->status         = 'imported';
        $log->aiengaged      = 0;
        $log->explanation    = 'CLI import job_id=' . $job_id;
        $log->createdby      = 0;
        $log->timecreated    = $now;
        $log->errorcode      = '';
        $log->rawproposal    = '{}';
        $DB->insert_record('qtype_structuredsteps_clog', $log);

        return $newid;
    }

    /** Get or create a minimal question category for the test. */
    private function get_test_categoryid(): int {
        global $DB;
        $ctx = \context_system::instance();
        $cat = $DB->get_record('question_categories',
            ['contextid' => $ctx->id, 'parent' => 0], 'id', IGNORE_MULTIPLE);
        if ($cat) {
            return (int)$cat->id;
        }
        // Create a minimal one.
        $c = new \stdClass();
        $c->name       = 'Test Category';
        $c->contextid  = $ctx->id;
        $c->parent     = 0;
        $c->info       = '';
        $c->infoformat = FORMAT_PLAIN;
        $c->stamp      = make_unique_id_code();
        return (int)$DB->insert_record('question_categories', $c);
    }

    // ── Rollback removes imported questions ───────────────────────────────────────

    public function test_rollback_deletes_imported_questions(): void {
        global $DB;
        $job_id  = 'job_test_' . uniqid();
        $cat_id  = $this->get_test_categoryid();

        $qid1 = $this->insert_fake_import($job_id, 'Time Q1', $cat_id);
        $qid2 = $this->insert_fake_import($job_id, 'Money Q1', $cat_id);

        // Verify rows exist before rollback.
        $this->assertTrue($DB->record_exists('question', ['id' => $qid1, 'qtype' => 'structuredsteps']));
        $this->assertTrue($DB->record_exists('question', ['id' => $qid2, 'qtype' => 'structuredsteps']));

        $orchestrator = new \qtype_structuredsteps\local\converter\job_orchestrator();
        $result = $orchestrator->rollback($job_id);

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['deleted_count']);
        $this->assertSame(0, $result['restored_count']); // XML source — no DB restoration
        $this->assertNull($result['error']);

        // Verify questions are gone.
        $this->assertFalse($DB->record_exists('question', ['id' => $qid1]));
        $this->assertFalse($DB->record_exists('question', ['id' => $qid2]));
    }

    public function test_rollback_deletes_options_rows(): void {
        global $DB;
        $job_id = 'job_test_' . uniqid();
        $cat_id = $this->get_test_categoryid();

        $qid = $this->insert_fake_import($job_id, 'Area Q1', $cat_id);
        $this->assertTrue($DB->record_exists('qtype_structuredsteps_options', ['questionid' => $qid]));

        $orchestrator = new \qtype_structuredsteps\local\converter\job_orchestrator();
        $orchestrator->rollback($job_id);

        $this->assertFalse($DB->record_exists('qtype_structuredsteps_options', ['questionid' => $qid]),
            'Options row must be deleted on rollback');
    }

    public function test_rollback_cleans_clog_entries(): void {
        global $DB;
        $job_id = 'job_test_' . uniqid();
        $cat_id = $this->get_test_categoryid();

        $this->insert_fake_import($job_id, 'Percent Q1', $cat_id);
        $before = $DB->count_records_select('qtype_structuredsteps_clog',
            $DB->sql_like('explanation', ':p'), ['p' => '%job_id=' . $job_id . '%']);
        $this->assertSame(1, $before);

        $orchestrator = new \qtype_structuredsteps\local\converter\job_orchestrator();
        $orchestrator->rollback($job_id);

        $after = $DB->count_records_select('qtype_structuredsteps_clog',
            $DB->sql_like('explanation', ':p'), ['p' => '%job_id=' . $job_id . '%']);
        $this->assertSame(0, $after, 'Clog rows for this job must be removed');
    }

    // ── Idempotency ───────────────────────────────────────────────────────────────

    public function test_rollback_is_idempotent(): void {
        $job_id = 'job_test_' . uniqid();
        $cat_id = $this->get_test_categoryid();

        $this->insert_fake_import($job_id, 'Time Q2', $cat_id);

        $orchestrator = new \qtype_structuredsteps\local\converter\job_orchestrator();
        $r1 = $orchestrator->rollback($job_id);
        $r2 = $orchestrator->rollback($job_id);

        $this->assertSame(1, $r1['deleted_count']);
        $this->assertSame(0, $r2['deleted_count'], 'Second rollback must delete nothing');
        $this->assertTrue($r2['success'], 'Second rollback must still succeed');
    }

    // ── Isolation: does not touch other jobs ──────────────────────────────────────

    public function test_rollback_does_not_touch_other_job(): void {
        global $DB;
        $job_a  = 'job_test_A_' . uniqid();
        $job_b  = 'job_test_B_' . uniqid();
        $cat_id = $this->get_test_categoryid();

        $qid_a = $this->insert_fake_import($job_a, 'Time QA', $cat_id);
        $qid_b = $this->insert_fake_import($job_b, 'Time QB', $cat_id);

        $orchestrator = new \qtype_structuredsteps\local\converter\job_orchestrator();
        $result = $orchestrator->rollback($job_a);

        $this->assertSame(1, $result['deleted_count']);
        $this->assertFalse($DB->record_exists('question', ['id' => $qid_a]),
            'Job A question must be deleted');
        $this->assertTrue($DB->record_exists('question', ['id' => $qid_b]),
            'Job B question must NOT be touched');
    }

    // ── Empty job rollback ────────────────────────────────────────────────────────

    public function test_rollback_nonexistent_job_returns_zero(): void {
        $orchestrator = new \qtype_structuredsteps\local\converter\job_orchestrator();
        $result = $orchestrator->rollback('job_nonexistent_xyz_999');

        $this->assertTrue($result['success']);
        $this->assertSame(0, $result['deleted_count']);
    }
}
