<?php
// This file is part of Moodle - http://moodle.org/

/**
 * Tests for n8n_agent_client.
 *
 * @package   qtype_structuredsteps
 */

defined('MOODLE_INTERNAL') || die();

/**
 * n8n client tests.
 */
class qtype_structuredsteps_n8n_agent_client_test extends basic_testcase {
    public function test_build_conversion_payload_contains_required_keys(): void {
        $client = new \qtype_structuredsteps\local\n8n_agent_client(['enabled' => false]);
        $payload = $client->build_conversion_payload(['id' => 123], ['tenant' => 'global']);

        $this->assertSame('qtype_structuredsteps', $payload['source']['plugin']);
        $this->assertSame('pclplus.n8n.request.v1', $payload['source']['schema']);
        $this->assertSame(123, $payload['legacy_question']['id']);
        $this->assertSame('global', $payload['context']['tenant']);
        $this->assertNotEmpty($payload['source']['generated_at']);
    }

    public function test_request_conversion_dry_run_does_not_invoke_sender(): void {
        $calls = 0;
        $sender = function() use (&$calls): array {
            $calls++;
            return [];
        };

        $client = new \qtype_structuredsteps\local\n8n_agent_client(['enabled' => true], $sender);
        $result = $client->request_conversion(['id' => 1], [], true);

        $this->assertSame('dry_run', $result['status']);
        $this->assertSame(0, $calls);
    }

    public function test_request_conversion_disabled_returns_disabled(): void {
        $client = new \qtype_structuredsteps\local\n8n_agent_client([
            'enabled' => false,
            'endpoint' => 'https://example.invalid/webhook',
            'timeout' => 5,
        ]);

        $result = $client->request_conversion(['id' => 2]);
        $this->assertSame('disabled', $result['status']);
    }

    public function test_request_conversion_requires_endpoint_when_enabled(): void {
        $client = new \qtype_structuredsteps\local\n8n_agent_client([
            'enabled' => true,
            'endpoint' => '',
            'timeout' => 5,
        ]);

        $result = $client->request_conversion(['id' => 3]);
        $this->assertSame('error', $result['status']);
        $this->assertSame('n8nendpointmissing', $result['errorcode']);
    }

    public function test_request_conversion_rejects_insecure_endpoint(): void {
        $client = new \qtype_structuredsteps\local\n8n_agent_client([
            'enabled' => true,
            'endpoint' => 'http://example.invalid/webhook',
            'timeout' => 5,
        ]);

        $result = $client->request_conversion(['id' => 6]);
        $this->assertSame('error', $result['status']);
        $this->assertSame('n8nendpointinsecure', $result['errorcode']);
    }

    public function test_request_conversion_success_parses_json_response(): void {
        $sender = function(string $endpoint, string $body, array $headers, int $timeout): array {
            return [
                'httpcode' => 202,
                'body' => '{"job_id":"abc123","status":"queued"}',
                'error' => '',
            ];
        };

        $client = new \qtype_structuredsteps\local\n8n_agent_client([
            'enabled' => true,
            'endpoint' => 'https://example.invalid/webhook',
            'token' => 'secret',
            'timeout' => 10,
        ], $sender);

        $result = $client->request_conversion(['id' => 4]);

        $this->assertSame('queued', $result['status']);
        $this->assertSame(202, $result['httpcode']);
        $this->assertSame('abc123', $result['response']['job_id']);
    }

    public function test_request_conversion_http_error_returns_normalised_error(): void {
        $sender = function(string $endpoint, string $body, array $headers, int $timeout): array {
            return [
                'httpcode' => 500,
                'body' => 'internal error',
                'error' => '',
            ];
        };

        $client = new \qtype_structuredsteps\local\n8n_agent_client([
            'enabled' => true,
            'endpoint' => 'https://example.invalid/webhook',
            'timeout' => 10,
        ], $sender);

        $result = $client->request_conversion(['id' => 5]);

        $this->assertSame('error', $result['status']);
        $this->assertSame('n8nhttperror', $result['errorcode']);
        $this->assertSame(500, $result['httpcode']);
    }

    public function test_timeout_is_bounded_to_safe_range(): void {
        $low = new \qtype_structuredsteps\local\n8n_agent_client([
            'enabled' => true,
            'endpoint' => 'https://example.invalid/webhook',
            'timeout' => 0,
        ]);
        $this->assertSame(10, $low->get_timeout());

        $high = new \qtype_structuredsteps\local\n8n_agent_client([
            'enabled' => true,
            'endpoint' => 'https://example.invalid/webhook',
            'timeout' => 999,
        ]);
        $this->assertSame(60, $high->get_timeout());
    }
}
