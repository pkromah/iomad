<?php
// This file is part of Moodle - http://moodle.org/

namespace qtype_structuredsteps\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Deterministic baseline grader for qtype_structuredsteps.
 */
class grader {
    /** @var array<string,array<string,mixed>|null> */
    private static array $decodedcache = [];

    /**
     * Compatibility wrapper used by question class.
     *
     * @param array $response
     * @param string $modeljson
     * @return float fraction 0..1
     */
    public function grade_response(array $response, string $modeljson): float {
        $result = $this->grade_with_details($response, $modeljson);
        return $result['fraction'];
    }

    /**
     * Grade response and return detailed contract.
     *
     * @param array $response
     * @param string $modeljson
     * @return array
     */
    public function grade_with_details(array $response, string $modeljson): array {
        $model = $this->decode_model($modeljson);
        if ($model === null) {
            return $this->empty_result();
        }

        $engine = isset($model['engine']) ? (string)$model['engine'] : '';
        if ($engine !== '' && engine_registry::is_supported($engine)) {
            $engineinstance = engine_factory::create($engine);
            if ($engineinstance === null) {
                $result = $this->empty_result();
                $result['errors'] = ['engine_unavailable' => $engine];
                return $result;
            }

            $validation = $engineinstance->validate_response($response, $model);
            if (empty($validation['is_valid'])) {
                $result = $this->empty_result();
                $result['errors'] = isset($validation['errors']) && is_array($validation['errors'])
                    ? $validation['errors']
                    : ['invalid_response'];
                return $result;
            }

            $engineresult = $engineinstance->grade($response, $model);
            return $this->normalise_result($engineresult);
        }

        return $this->fallback_grade($response, $modeljson);
    }

    /**
     * Extract expected response from model JSON.
     *
     * @param string $modeljson
     * @return string|null
     */
    public function get_expected_response(string $modeljson): ?string {
        $model = $this->decode_model($modeljson);
        if ($model === null) {
            return null;
        }

        if (isset($model['grading']['expected_response']) && is_string($model['grading']['expected_response'])) {
            return $model['grading']['expected_response'];
        }

        if (isset($model['expected_response']) && is_string($model['expected_response'])) {
            return $model['expected_response'];
        }

        return null;
    }

    /**
     * Fallback grading path for models without a resolvable engine.
     *
     * @param array $response
     * @param string $modeljson
     * @return array
     */
    private function fallback_grade(array $response, string $modeljson): array {
        $expected = $this->get_expected_response($modeljson);
        $answer = trim((string)($response['answer'] ?? ''));
        $correct = ($expected !== null && trim($expected) !== '' && $answer === trim($expected));

        return [
            'score' => $correct ? 1.0 : 0.0,
            'max_score' => 1.0,
            'fraction' => $correct ? 1.0 : 0.0,
            'field_results' => [],
            'step_results' => [],
            'errors' => [],
        ];
    }

    /**
     * @param string $modeljson
     * @return array<string,mixed>|null
     */
    private function decode_model(string $modeljson): ?array {
        $key = sha1($modeljson);
        if (array_key_exists($key, self::$decodedcache)) {
            return self::$decodedcache[$key];
        }

        $muc = $this->model_cache_store();
        if ($muc !== null) {
            $cached = $muc->get($key);
            if (is_array($cached)) {
                self::$decodedcache[$key] = $cached;
                return $cached;
            }
        }

        try {
            $model = json_decode($modeljson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            self::$decodedcache[$key] = null;
            return null;
        }

        if (!is_array($model)) {
            self::$decodedcache[$key] = null;
            return null;
        }

        self::$decodedcache[$key] = $model;
        if ($muc !== null) {
            $muc->set($key, $model);
        }

        return $model;
    }

    /**
     * Resolve MUC cache store for decoded models. Return null when unavailable.
     *
     * @return \cache_application|null
     */
    private function model_cache_store(): ?\cache_application {
        try {
            return \cache::make('qtype_structuredsteps', 'modeldecode');
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @return array{score:float,max_score:float,fraction:float,field_results:array,step_results:array,errors:array}
     */
    private function empty_result(): array {
        return [
            'score' => 0.0,
            'max_score' => 0.0,
            'fraction' => 0.0,
            'field_results' => [],
            'step_results' => [],
            'errors' => ['invalid_model'],
        ];
    }

    /**
     * Ensure engine result conforms to grading contract.
     *
     * @param array $result
     * @return array
     */
    private function normalise_result(array $result): array {
        $score = isset($result['score']) ? (float)$result['score'] : 0.0;
        $maxscore = isset($result['max_score']) ? (float)$result['max_score'] : 0.0;
        $fraction = isset($result['fraction']) ? (float)$result['fraction'] : 0.0;
        if ($maxscore > 0.0 && !isset($result['fraction'])) {
            $fraction = $score / $maxscore;
        }

        return [
            'score' => $score,
            'max_score' => $maxscore,
            'fraction' => max(0.0, min(1.0, $fraction)),
            'field_results' => isset($result['field_results']) && is_array($result['field_results'])
                ? $result['field_results']
                : [],
            'step_results' => isset($result['step_results']) && is_array($result['step_results'])
                ? $result['step_results']
                : [],
            'errors' => isset($result['errors']) && is_array($result['errors'])
                ? $result['errors']
                : [],
        ];
    }
}
