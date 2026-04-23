<?php
// This file is part of Moodle - http://moodle.org/

/**
 * Tests for deterministic converter core service.
 *
 * @package   qtype_structuredsteps
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Converter service tests.
 */
class qtype_structuredsteps_converter_service_test extends basic_testcase {
    public function test_extract_cloze_tokens_parses_core_components(): void {
        $service = new \qtype_structuredsteps\local\converter\service();
        $tokens = $service->extract_cloze_tokens('{1:NUMERICAL:=318:0.1} and {2:SHORTANSWER:=Debit}');

        $this->assertCount(2, $tokens);
        $this->assertSame('NUMERICAL', $tokens[0]['type']);
        $this->assertSame('318:0.1', $tokens[0]['answer']);
        $this->assertSame(0.1, $tokens[0]['tolerance']);
        $this->assertSame('SHORTANSWER', $tokens[1]['type']);
    }

    public function test_propose_conversion_detects_long_multiplication_from_pattern(): void {
        $service = new \qtype_structuredsteps\local\converter\service();
        $proposal = $service->propose_conversion([
            'id' => 101,
            'qtype' => 'cloze',
            'questiontext' => '<p>Calculate 53 x 26 using long multiplication.</p>{1:NUMERICAL:=318}{1:NUMERICAL:=1060}',
        ]);

        $this->assertSame('long_multiplication', $proposal['engine']);
        $this->assertGreaterThanOrEqual(60, $proposal['confidence']);
        $this->assertNotEmpty($proposal['model_json']);
        $this->assertNull($proposal['validation_error']);
    }

    public function test_propose_conversion_detects_division_bracket_pattern(): void {
        $service = new \qtype_structuredsteps\local\converter\service();
        $proposal = $service->propose_conversion([
            'id' => 102,
            'qtype' => 'cloze',
            'questiontext' => '<p>Solve 12)784 with working.</p>{1:NUMERICAL:=6}',
        ]);

        $this->assertSame('long_division', $proposal['engine']);
        $this->assertContains('division_pattern', $proposal['detected_patterns']);
        $this->assertNotEmpty($proposal['model_json']);
    }

    public function test_low_confidence_question_triggers_ai_fallback(): void {
        $service = new \qtype_structuredsteps\local\converter\service();
        $proposal = $service->propose_conversion([
            'id' => 103,
            'qtype' => 'cloze',
            'questiontext' => '<p>Do this thing maybe somehow.</p>',
        ]);

        $this->assertSame('low_confidence', $proposal['status']);
        $this->assertTrue($proposal['needs_ai']);
        $this->assertSame(103, $proposal['ai_payload']['questionid']);
    }

    public function test_ai_payload_contains_required_schema_keys(): void {
        $service = new \qtype_structuredsteps\local\converter\service();
        $proposal = $service->propose_conversion([
            'id' => 104,
            'qtype' => 'essay',
            'questiontext' => '<p>Answer, evidence, explanation.</p>',
        ]);

        $payload = $proposal['ai_payload'];
        $this->assertArrayHasKey('questionid', $payload);
        $this->assertArrayHasKey('questiontext', $payload);
        $this->assertArrayHasKey('cloze_tokens', $payload);
        $this->assertArrayHasKey('suggested_engines', $payload);
        $this->assertArrayHasKey('confidence', $payload);
        $this->assertArrayHasKey('schema_version', $payload);
    }
}
