<?php
// This file is part of Moodle - http://moodle.org/

namespace qtype_structuredsteps\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Shared diagnostic taxonomy for engine error typing.
 */
class diagnostic_taxonomy {
    /** @var array<string,int> */
    private const SEVERITY = [
        'conceptual_error' => 6,
        'structural_error' => 5,
        'computational_error' => 4,
        'communication_error' => 3,
        'procedural_error' => 2,
        'formatting_error' => 1,
    ];

    /**
     * Resolve dominant error type from a list of error types.
     *
     * @param array<int,string> $errors
     * @return string
     */
    public static function resolve_dominant_error_type(array $errors): string {
        if (empty($errors)) {
            return 'none';
        }

        $best = 'none';
        $bestrank = -1;
        foreach ($errors as $error) {
            $rank = self::SEVERITY[$error] ?? 0;
            if ($rank > $bestrank) {
                $best = $error;
                $bestrank = $rank;
            }
        }

        return $best;
    }
}
