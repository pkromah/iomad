<?php
// This file is part of Moodle - http://moodle.org/

namespace qtype_structuredsteps\local\converter;

defined('MOODLE_INTERNAL') || die();

/**
 * Converter job orchestration service.
 */
class job_orchestrator {
    /** @var service */
    private service $converter;

    /**
     * @param service|null $converter
     */
    public function __construct(?service $converter = null) {
        $this->converter = $converter ?? new service();
    }

    /**
     * Queue a conversion job.
     *
     * @param array<string,mixed> $config
     * @return int job id
     */
    public function queue_job(array $config): int {
        global $DB, $USER;

        $record = new \stdClass();
        $record->tenantid = (int)($config['tenantid'] ?? 0);
        $record->categoryid = (int)($config['categoryid'] ?? 0);
        $record->sourceqtype = trim((string)($config['sourceqtype'] ?? ''));
        $record->status = 'queued';
        $record->confidence_threshold = max(0, min(100, (int)($config['confidence_threshold'] ?? 85)));
        $record->dryrun = empty($config['dryrun']) ? 0 : 1;
        $record->requestedby = (int)($config['requestedby'] ?? ($USER->id ?? 0));
        $record->approvedby = 0;
        $record->runid = !empty($config['runid']) ? (string)$config['runid'] : uniqid('qssjob_', true);
        $record->totalscanned = 0;
        $record->totalconverted = 0;
        $record->totalreview = 0;
        $record->totalfailed = 0;
        $record->aiinvoked = 0;
        $record->errorcode = '';
        $record->errormessage = '';
        $record->payloadjson = json_encode($config, JSON_UNESCAPED_SLASHES);
        $record->reportjson = '{}';
        $record->timecreated = time();
        $record->timemodified = $record->timecreated;
        $record->timestarted = 0;
        $record->timefinished = 0;
        $record->retrycount = 0;

        return (int)$DB->insert_record('qtype_structuredsteps_cjob', $record);
    }

    /**
     * Process a queued or retry job.
     *
     * @param int $jobid
     * @param int $limit
     * @return array<string,mixed>
     */
    public function process_job(int $jobid, int $limit = 200): array {
        global $DB;

        $job = $DB->get_record('qtype_structuredsteps_cjob', ['id' => $jobid], '*', MUST_EXIST);
        if (!in_array($job->status, ['queued', 'retry'], true)) {
            return $this->normalise_job_summary($job, 'skipped');
        }

        $job->status = 'running';
        $job->timestarted = time();
        $job->timemodified = $job->timestarted;
        $DB->update_record('qtype_structuredsteps_cjob', $job);

        [$questionsql, $params] = $this->build_question_filter_sql($job);
        $questions = $DB->get_records_select('question', $questionsql, $params, 'id ASC', '*', 0, max(1, $limit));

        $report = [
            'processed' => 0,
            'converted' => 0,
            'review' => 0,
            'failed' => 0,
            'ai_invoked' => 0,
        ];

        foreach ($questions as $question) {
            $legacy = [
                'id' => (int)$question->id,
                'qtype' => (string)$question->qtype,
                'questiontext' => (string)$question->questiontext,
            ];

            try {
                $proposal = $this->converter->propose_conversion($legacy);
                $status = (string)$proposal['status'];
                $confidence = (int)($proposal['confidence'] ?? 0);
                $aiengaged = !empty($proposal['needs_ai']) ? 1 : 0;

                $log = new \stdClass();
                $log->jobid = (int)$job->id;
                $log->oldquestionid = (int)$question->id;
                $log->newquestionid = 0;
                $log->tenantid = (int)$job->tenantid;
                $log->coursecatid = (int)$question->category;
                $log->engine = (string)($proposal['engine'] ?? '');
                $log->confidence = $confidence;
                $log->finalconfidence = $confidence;
                $log->status = $status;
                $log->aiengaged = $aiengaged;
                $log->explanation = implode(', ', (array)($proposal['detected_patterns'] ?? []));
                $log->createdby = (int)$job->requestedby;
                $log->timecreated = time();
                $log->errorcode = (string)($proposal['validation_error'] ?? '');
                $log->rawproposal = json_encode($proposal, JSON_UNESCAPED_SLASHES);
                $DB->insert_record('qtype_structuredsteps_clog', $log);

                $report['processed']++;
                $report['ai_invoked'] += $aiengaged;

                if ($status === 'auto_convert_eligible' && !$job->dryrun && $confidence >= (int)$job->confidence_threshold) {
                    // QSS-020 orchestration scope: queue/log conversion intent; actual creation handled in next phase.
                    $report['converted']++;
                } else if ($status === 'low_confidence' || $status === 'manual_review') {
                    $report['review']++;
                } else {
                    $report['failed']++;
                }
            } catch (\Throwable $e) {
                $report['processed']++;
                $report['failed']++;

                $log = new \stdClass();
                $log->jobid = (int)$job->id;
                $log->oldquestionid = (int)$question->id;
                $log->newquestionid = 0;
                $log->tenantid = (int)$job->tenantid;
                $log->coursecatid = (int)$question->category;
                $log->engine = '';
                $log->confidence = 0;
                $log->finalconfidence = 0;
                $log->status = 'failed';
                $log->aiengaged = 0;
                $log->explanation = '';
                $log->createdby = (int)$job->requestedby;
                $log->timecreated = time();
                $log->errorcode = 'converter_exception';
                $log->rawproposal = json_encode(['message' => $e->getMessage()], JSON_UNESCAPED_SLASHES);
                $DB->insert_record('qtype_structuredsteps_clog', $log);
            }
        }

        $job->status = 'completed';
        $job->totalscanned = (int)$report['processed'];
        $job->totalconverted = (int)$report['converted'];
        $job->totalreview = (int)$report['review'];
        $job->totalfailed = (int)$report['failed'];
        $job->aiinvoked = (int)$report['ai_invoked'];
        $job->reportjson = json_encode($report, JSON_UNESCAPED_SLASHES);
        $job->timefinished = time();
        $job->timemodified = $job->timefinished;
        $DB->update_record('qtype_structuredsteps_cjob', $job);

        return $this->normalise_job_summary($job, 'processed');
    }

    /**
     * Mark a job for retry.
     *
     * @param int $jobid
     * @return void
     */
    public function retry_job(int $jobid): void {
        global $DB;

        $job = $DB->get_record('qtype_structuredsteps_cjob', ['id' => $jobid], '*', MUST_EXIST);
        $job->status = 'retry';
        $job->retrycount = (int)$job->retrycount + 1;
        $job->timemodified = time();
        $DB->update_record('qtype_structuredsteps_cjob', $job);
    }

    /**
     * Import a single converted question into the Moodle question bank.
     *
     * Creates a new qtype_structuredsteps question in the target category and writes
     * a log record. Intended to be called from convert.php after generator validation.
     *
     * @param string $model_json  Validated structuredsteps model JSON.
     * @param array  $parsed_q   Original parsed DDWTOS question (name, raw_text, etc.).
     * @param string $job_id     Caller-generated job ID string (e.g. 'job_20260403_abc123').
     * @param int    $categoryid Target question category ID.
     * @param array<string,string> $asset_mapping  Optional img_* token → @@PLUGINFILE@@ URL map from upload_images.php.
     * @param string               $residual_path  Optional path to write residual.jsonl for unresolved image tokens.
     * @return array{newquestionid:int,status:string,error:string|null}
     */
    public function import_question(
        string $model_json,
        array $parsed_q,
        string $job_id,
        int $categoryid = 0,
        array $asset_mapping = [],
        string $residual_path = ''
    ): array {
        global $DB, $CFG;

        $model = json_decode($model_json, true);
        if (!$model) {
            return ['newquestionid' => 0, 'status' => 'failed', 'error' => 'Invalid model JSON'];
        }

        // Ensure target category exists; fall back to default question category.
        if ($categoryid <= 0) {
            $system_ctx = \context_system::instance();
            $default_cat = $DB->get_record('question_categories',
                ['contextid' => $system_ctx->id, 'parent' => 0], 'id', IGNORE_MULTIPLE);
            $categoryid = $default_cat ? (int)$default_cat->id : 1;
        }

        // --- Idempotency: skip if a question with this name already exists in this category.
        $existing_name = mb_substr((string)($parsed_q['name'] ?? ''), 0, 255);
        if ($existing_name !== '') {
            $existing_sql = "SELECT q.id FROM {question} q
                             JOIN {question_versions} qv ON qv.questionid = q.id
                             JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                             WHERE q.name = :qname
                             AND qbe.questioncategoryid = :catid
                             AND q.qtype = 'structuredsteps'";
            if ($DB->record_exists_sql($existing_sql, ['qname' => $existing_name, 'catid' => $categoryid])) {
                return ['newquestionid' => 0, 'status' => 'skipped_duplicate', 'error' => null];
            }
        }

        // --- Image URL rewriting.
        // Replace img_* tokens in question text with @@PLUGINFILE@@ URLs from asset_mapping.
        // Tokens appear as plain text in <p> tags: <p>img_Name_Year_01</p>
        // After rewriting: <p><img src="@@PLUGINFILE@@/img_Name_Year_01.jpg" /></p>
        $question_text = (string)($parsed_q['raw_text'] ?? '');
        $unresolved_tokens = [];

        if (!empty($asset_mapping) && preg_match('/\bimg_[A-Za-z0-9_]+/', $question_text)) {
            $question_text = preg_replace_callback(
                '/\bimg_([A-Za-z0-9_]+)/',
                function (array $m) use ($asset_mapping, &$unresolved_tokens, $existing_name): string {
                    $token = 'img_' . $m[1];
                    if (isset($asset_mapping[$token])) {
                        $url = htmlspecialchars($asset_mapping[$token], ENT_QUOTES, 'UTF-8');
                        return '<img src="' . $url . '" alt="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '" />';
                    }
                    // Token not in mapping — record for residual list.
                    $unresolved_tokens[] = $token;
                    return $token; // leave as-is
                },
                $question_text
            );

            if (!empty($unresolved_tokens) && $residual_path !== '') {
                $entry = json_encode([
                    'question_name'      => $existing_name,
                    'unresolved_tokens'  => array_unique($unresolved_tokens),
                    'reason'             => 'image_not_in_mapping',
                ], JSON_UNESCAPED_SLASHES) . "\n";
                file_put_contents($residual_path, $entry, FILE_APPEND | LOCK_EX);
            }

            // If any tokens remain unresolved, hold this question in review queue.
            if (!empty($unresolved_tokens)) {
                return [
                    'newquestionid' => 0,
                    'status'        => 'review_queue',
                    'error'         => 'Unresolved image tokens: ' . implode(', ', array_unique($unresolved_tokens)),
                ];
            }
        }

        // Build question record.
        // Note: question.category does not exist in Moodle 4+; category is stored in
        // question_bank_entries.questioncategoryid. We omit the legacy field entirely.
        $now = time();
        $q = new \stdClass();
        $q->parent      = 0;
        $q->name        = mb_substr((string)($parsed_q['name'] ?? 'Converted question'), 0, 255);
        $q->questiontext = $question_text;
        $q->questiontextformat = FORMAT_HTML;
        $q->generalfeedback = '';
        $q->generalfeedbackformat = FORMAT_HTML;
        $q->defaultmark = 1.0;
        $q->penalty     = 0.0;
        $q->qtype       = 'structuredsteps';
        $q->length      = 1;
        $q->stamp       = make_unique_id_code();
        $q->timecreated = $now;
        $q->timemodified = $now;
        $q->createdby   = 0;
        $q->modifiedby  = 0;

        try {
            $newid = (int)$DB->insert_record('question', $q);

            // Create question_bank_entries record (Moodle 4+ question bank).
            $qbe = new \stdClass();
            $qbe->questioncategoryid = $categoryid;
            $qbe->idnumber           = null;
            $qbe->ownerid            = 0;
            $qbe->id = (int)$DB->insert_record('question_bank_entries', $qbe);

            // Create question_versions record linking QBE → question.
            $qv = new \stdClass();
            $qv->questionbankentryid = $qbe->id;
            $qv->questionid          = $newid;
            $qv->version             = 1;
            $qv->status              = \core_question\local\bank\question_version_status::QUESTION_STATUS_READY;
            $DB->insert_record('question_versions', $qv);

            // Write structuredsteps options row.
            $opts = new \stdClass();
            $opts->questionid     = $newid;
            $opts->schema_version = (string)($model['schema_version'] ?? '1.0');
            $opts->engine         = (string)($model['engine'] ?? '');
            $opts->engine_version = '1.0';
            $opts->model_json     = $model_json;
            $opts->timecreated    = $now;
            $opts->timemodified   = $now;
            $DB->insert_record('qtype_structuredsteps_options', $opts);

            // Write conversion log.
            $log = new \stdClass();
            $log->jobid         = 0; // CLI job — no cjob row
            $log->oldquestionid = 0; // source was XML, not a DB question
            $log->newquestionid = $newid;
            $log->tenantid      = 0;
            $log->coursecatid   = $categoryid;
            $log->engine        = (string)($model['engine'] ?? '');
            $log->confidence    = 0;
            $log->finalconfidence = 0;
            $log->status        = 'imported';
            $log->aiengaged     = 0;
            $log->explanation   = 'CLI import job_id=' . $job_id;
            $log->createdby     = 0;
            $log->timecreated   = $now;
            $log->errorcode     = '';
            $log->rawproposal   = $model_json;
            $DB->insert_record('qtype_structuredsteps_clog', $log);

            return ['newquestionid' => $newid, 'status' => 'imported', 'error' => null];

        } catch (\Throwable $e) {
            return ['newquestionid' => 0, 'status' => 'failed', 'error' => $e->getMessage()];
        }
    }

    /**
     * Rollback a previously imported CLI batch by job ID string.
     *
     * Deletes all qtype_structuredsteps questions whose clog explanation references this job_id.
     * The source questions were XML files (not DB records), so no restoration is needed.
     *
     * @param string $job_id
     * @return array{success:bool,deleted_count:int,restored_count:int,error:string|null}
     */
    public function rollback(string $job_id): array {
        global $DB;

        $like = $DB->sql_like('explanation', ':jobpat');
        $logs = $DB->get_records_select('qtype_structuredsteps_clog',
            $like . " AND newquestionid > 0",
            ['jobpat' => '%job_id=' . $DB->sql_like_escape($job_id) . '%']
        );

        $deleted = 0;
        foreach ($logs as $log) {
            $qid = (int)$log->newquestionid;
            if ($qid > 0) {
                // Clean up QBE + question_versions before deleting the question row.
                $qv = $DB->get_record('question_versions', ['questionid' => $qid]);
                if ($qv) {
                    $DB->delete_records('question_versions', ['questionid' => $qid]);
                    $DB->delete_records('question_bank_entries', ['id' => $qv->questionbankentryid]);
                }
                $DB->delete_records('qtype_structuredsteps_options', ['questionid' => $qid]);
                $DB->delete_records('question', ['id' => $qid, 'qtype' => 'structuredsteps']);
                $deleted++;
            }
        }

        $DB->delete_records_select('qtype_structuredsteps_clog',
            $like,
            ['jobpat' => '%job_id=' . $DB->sql_like_escape($job_id) . '%']
        );

        return [
            'success'        => true,
            'deleted_count'  => $deleted,
            'restored_count' => 0, // XML source — no DB restoration needed
            'error'          => null,
        ];
    }

    /**
     * @param int $limit
     * @return array<int,\stdClass>
     */
    public function list_jobs(int $limit = 50): array {
        global $DB;
        return $DB->get_records('qtype_structuredsteps_cjob', null, 'timemodified DESC', '*', 0, max(1, $limit));
    }

    /**
     * @param int $jobid
     * @param int $limit
     * @return array<int,\stdClass>
     */
    public function list_job_logs(int $jobid, int $limit = 200): array {
        global $DB;
        return $DB->get_records('qtype_structuredsteps_clog', ['jobid' => $jobid], 'id DESC', '*', 0, max(1, $limit));
    }

    /**
     * @param \stdClass $job
     * @return array{0:string,1:array<string,mixed>}
     */
    private function build_question_filter_sql(\stdClass $job): array {
        $where = [];
        $params = [];

        if (trim((string)$job->sourceqtype) !== '') {
            $where[] = 'qtype = :qtype';
            $params['qtype'] = (string)$job->sourceqtype;
        }
        if ((int)$job->categoryid > 0) {
            $where[] = 'category = :categoryid';
            $params['categoryid'] = (int)$job->categoryid;
        }

        if (empty($where)) {
            return ['1=1', []];
        }

        return [implode(' AND ', $where), $params];
    }

    /**
     * @param \stdClass $job
     * @param string $mode
     * @return array<string,mixed>
     */
    private function normalise_job_summary(\stdClass $job, string $mode): array {
        return [
            'mode' => $mode,
            'jobid' => (int)$job->id,
            'status' => (string)$job->status,
            'totalscanned' => (int)$job->totalscanned,
            'totalconverted' => (int)$job->totalconverted,
            'totalreview' => (int)$job->totalreview,
            'totalfailed' => (int)$job->totalfailed,
            'aiinvoked' => (int)$job->aiinvoked,
        ];
    }
}
