<?php
// This file is part of Moodle - http://moodle.org/

namespace qtype_structuredsteps\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Analytics fact storage and export helpers for qtype_structuredsteps.
 */
class analytics_service {
    /**
     * Build canonical analytics fact row from grading details.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function build_fact_record(array $input): array {
        $steps = (isset($input['step_results']) && is_array($input['step_results'])) ? $input['step_results'] : [];
        $fields = (isset($input['field_results']) && is_array($input['field_results'])) ? $input['field_results'] : [];

        $errorcounts = $this->count_error_types($fields);
        $dominanterror = $this->dominant_error($errorcounts);

        $summary = [
            'step_results' => $steps,
            'error_type_counts' => $errorcounts,
        ];

        return [
            'questionid' => (int)($input['questionid'] ?? 0),
            'attemptid' => (int)($input['attemptid'] ?? 0),
            'userid' => (int)($input['userid'] ?? 0),
            'engine' => (string)($input['engine'] ?? ''),
            'step_error_summary' => json_encode($summary, JSON_UNESCAPED_SLASHES),
            'dominant_error' => $dominanterror,
            'total_score' => (float)($input['total_score'] ?? 0.0),
            'max_score' => (float)($input['max_score'] ?? 0.0),
            'tenantid' => (int)($input['tenantid'] ?? 0),
            'timecreated' => (int)($input['timecreated'] ?? time()),
        ];
    }

    /**
     * Insert analytics fact row.
     *
     * @param array<string,mixed> $input
     * @return int
     */
    public function insert_fact_record(array $input): int {
        global $DB;

        $record = $this->build_fact_record($input);
        $this->validate_record($record);

        $id = (int)$DB->insert_record('qtype_structuredsteps_analytics', (object)$record);
        $this->invalidate_export_cache();

        return $id;
    }

    /**
     * CSV-safe export rows.
     *
     * @param int $tenantid
     * @param array<string,mixed> $filters
     * @param bool $anonymised
     * @param int $limit
     * @param int $offset
     * @return array<int,array<string,mixed>>
     */
    public function export_rows_csv(
        int $tenantid,
        array $filters = [],
        bool $anonymised = false,
        int $limit = 1000,
        int $offset = 0
    ): array {
        $records = $this->fetch_records($tenantid, $filters, $limit, $offset);
        $rows = [];

        foreach ($records as $record) {
            $rows[] = [
                'student_id' => $anonymised ? '' : (string)$record->userid,
                'question_id' => (int)$record->questionid,
                'engine' => (string)$record->engine,
                'dominant_error' => (string)$record->dominant_error,
                'score' => (float)$record->total_score,
                'max_score' => (float)$record->max_score,
                'attempt_id' => (int)$record->attemptid,
                'timecreated' => (int)$record->timecreated,
            ];
        }

        return $rows;
    }

    /**
     * JSON-safe export rows for BI tooling.
     *
     * @param int $tenantid
     * @param array<string,mixed> $filters
     * @param bool $anonymised
     * @param int $limit
     * @param int $offset
     * @return array<int,array<string,mixed>>
     */
    public function export_rows_json(
        int $tenantid,
        array $filters = [],
        bool $anonymised = false,
        int $limit = 1000,
        int $offset = 0
    ): array {
        $records = $this->fetch_records($tenantid, $filters, $limit, $offset);
        $rows = [];

        foreach ($records as $record) {
            $summary = $this->decode_summary((string)$record->step_error_summary);
            $steps = [];

            if (!empty($summary['step_results']) && is_array($summary['step_results'])) {
                foreach ($summary['step_results'] as $stepid => $stepresult) {
                    if (!is_array($stepresult)) {
                        continue;
                    }
                    $steps[] = [
                        'id' => (string)$stepid,
                        'correct' => !empty($stepresult['is_correct']),
                        'awarded_marks' => (float)($stepresult['awarded_marks'] ?? 0.0),
                        'max_marks' => (float)($stepresult['max_marks'] ?? 0.0),
                    ];
                }
            }

            $row = [
                'questionid' => (int)$record->questionid,
                'engine' => (string)$record->engine,
                'dominant_error' => (string)$record->dominant_error,
                'total_score' => (float)$record->total_score,
                'max_score' => (float)$record->max_score,
                'steps' => $steps,
                'timecreated' => (int)$record->timecreated,
            ];

            if (!$anonymised) {
                $row['userid'] = (int)$record->userid;
                $row['attemptid'] = (int)$record->attemptid;
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param int $tenantid
     * @param array<string,mixed> $filters
     * @param int $limit
     * @param int $offset
     * @return array<int,\stdClass>
     */
    private function fetch_records(int $tenantid, array $filters, int $limit, int $offset): array {
        global $DB;

        $cachekey = $this->build_export_cache_key($tenantid, $filters, $limit, $offset);
        $cache = $this->export_cache_store();
        if ($cache !== null) {
            $cached = $cache->get($cachekey);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $where = ['tenantid = :tenantid'];
        $params = ['tenantid' => $tenantid];

        if (!empty($filters['questionid'])) {
            $where[] = 'questionid = :questionid';
            $params['questionid'] = (int)$filters['questionid'];
        }

        if (!empty($filters['engine'])) {
            $where[] = 'engine = :engine';
            $params['engine'] = (string)$filters['engine'];
        }

        if (!empty($filters['from'])) {
            $where[] = 'timecreated >= :timefrom';
            $params['timefrom'] = (int)$filters['from'];
        }

        if (!empty($filters['to'])) {
            $where[] = 'timecreated <= :timeto';
            $params['timeto'] = (int)$filters['to'];
        }

        $sql = 'SELECT id, questionid, attemptid, userid, engine, step_error_summary, dominant_error, total_score, max_score, tenantid, timecreated
                  FROM {qtype_structuredsteps_analytics}
                 WHERE ' . implode(' AND ', $where) . '
              ORDER BY timecreated DESC, id DESC';

        $records = array_values($DB->get_records_sql($sql, $params, max(0, $offset), max(1, $limit)));
        if ($cache !== null) {
            $cache->set($cachekey, $records);
        }

        return $records;
    }

    /**
     * @param array<string,mixed> $record
     * @return void
     */
    private function validate_record(array $record): void {
        if ($record['questionid'] <= 0) {
            throw new \coding_exception('questionid must be a positive integer');
        }
        if ($record['attemptid'] <= 0) {
            throw new \coding_exception('attemptid must be a positive integer');
        }
        if ($record['userid'] <= 0) {
            throw new \coding_exception('userid must be a positive integer');
        }
        if ($record['tenantid'] <= 0) {
            throw new \coding_exception('tenantid must be a positive integer');
        }
        if (trim((string)$record['engine']) === '') {
            throw new \coding_exception('engine is required');
        }
    }

    /**
     * @param string $summaryjson
     * @return array<string,mixed>
     */
    private function decode_summary(string $summaryjson): array {
        if (trim($summaryjson) === '') {
            return [];
        }

        try {
            $decoded = json_decode($summaryjson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string,mixed> $fieldresults
     * @return array<string,int>
     */
    private function count_error_types(array $fieldresults): array {
        $counts = [];

        foreach ($fieldresults as $fieldresult) {
            if (!is_array($fieldresult)) {
                continue;
            }
            $error = trim((string)($fieldresult['error_type'] ?? ''));
            if ($error === '') {
                continue;
            }
            if (!isset($counts[$error])) {
                $counts[$error] = 0;
            }
            $counts[$error]++;
        }

        return $counts;
    }

    /**
     * @param array<string,int> $errorcounts
     * @return string
     */
    private function dominant_error(array $errorcounts): string {
        if (empty($errorcounts)) {
            return 'none';
        }

        arsort($errorcounts);
        $top = array_key_first($errorcounts);
        return $top === null ? 'none' : (string)$top;
    }

    /**
     * @return \cache_application|null
     */
    private function export_cache_store(): ?\cache_application {
        try {
            return \cache::make('qtype_structuredsteps', 'analytics_export');
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Purge analytics export cache after writes to avoid stale reads.
     */
    private function invalidate_export_cache(): void {
        $cache = $this->export_cache_store();
        if ($cache !== null) {
            $cache->purge();
        }
    }

    /**
     * @param int $tenantid
     * @param array<string,mixed> $filters
     * @param int $limit
     * @param int $offset
     * @return string
     */
    private function build_export_cache_key(int $tenantid, array $filters, int $limit, int $offset): string {
        ksort($filters);
        return sha1(json_encode([
            'tenantid' => $tenantid,
            'filters' => $filters,
            'limit' => $limit,
            'offset' => $offset,
        ], JSON_UNESCAPED_SLASHES) ?: '');
    }
}
