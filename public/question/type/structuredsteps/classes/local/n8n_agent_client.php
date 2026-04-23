<?php
// This file is part of Moodle - http://moodle.org/

namespace qtype_structuredsteps\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Client for optional n8n conversion workflows.
 */
class n8n_agent_client {
    /** @var array<string,mixed> */
    private array $config;

    /** @var callable */
    private $sender;

    /**
     * @param array<string,mixed>|null $config
     * @param callable|null $sender
     */
    public function __construct(?array $config = null, ?callable $sender = null) {
        $this->config = $config ?? $this->load_config();
        $this->sender = $sender ?? [$this, 'default_sender'];
    }

    /**
     * Build canonical payload for downstream conversion agents.
     *
     * @param array<string,mixed> $legacyquestion
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public function build_conversion_payload(array $legacyquestion, array $context = []): array {
        return [
            'source' => [
                'plugin' => 'qtype_structuredsteps',
                'schema' => 'pclplus.n8n.request.v1',
                'generated_at' => gmdate('c'),
            ],
            'legacy_question' => $legacyquestion,
            'context' => $context,
        ];
    }

    /**
     * Request conversion via n8n webhook, or run dry-run payload generation.
     *
     * @param array<string,mixed> $legacyquestion
     * @param array<string,mixed> $context
     * @param bool $dryrun
     * @return array<string,mixed>
     */
    public function request_conversion(array $legacyquestion, array $context = [], bool $dryrun = false): array {
        $payload = $this->build_conversion_payload($legacyquestion, $context);

        if ($dryrun) {
            return [
                'status' => 'dry_run',
                'payload' => $payload,
            ];
        }

        if (!$this->is_enabled()) {
            return [
                'status' => 'disabled',
                'payload' => $payload,
            ];
        }

        $endpoint = $this->get_endpoint();
        if ($endpoint === '') {
            return [
                'status' => 'error',
                'errorcode' => 'n8nendpointmissing',
                'payload' => $payload,
            ];
        }
        if (!$this->is_secure_endpoint($endpoint)) {
            return [
                'status' => 'error',
                'errorcode' => 'n8nendpointinsecure',
                'payload' => $payload,
            ];
        }

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        $token = $this->get_token();
        if ($token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $timeout = $this->get_timeout();

        /** @var callable $sender */
        $sender = $this->sender;
        $raw = $sender($endpoint, $body, $headers, $timeout);

        return $this->normalise_response($raw, $payload);
    }

    /**
     * @return bool
     */
    public function is_enabled(): bool {
        return !empty($this->config['enabled']);
    }

    /**
     * @return string
     */
    public function get_endpoint(): string {
        return trim((string)($this->config['endpoint'] ?? ''));
    }

    /**
     * @return string
     */
    public function get_token(): string {
        return trim((string)($this->config['token'] ?? ''));
    }

    /**
     * @return int
     */
    public function get_timeout(): int {
        $timeout = (int)($this->config['timeout'] ?? 10);
        if ($timeout < 1) {
            return 10;
        }
        if ($timeout > 60) {
            return 60;
        }
        return $timeout;
    }

    /**
     * @return array<string,mixed>
     */
    private function load_config(): array {
        return [
            'enabled' => (int)get_config('qtype_structuredsteps', 'n8n_enabled') === 1,
            'endpoint' => (string)get_config('qtype_structuredsteps', 'n8n_endpoint'),
            'token' => (string)get_config('qtype_structuredsteps', 'n8n_token'),
            'timeout' => (int)get_config('qtype_structuredsteps', 'n8n_timeout'),
        ];
    }

    /**
     * @param string $endpoint
     * @param string $body
     * @param array<int,string> $headers
     * @param int $timeout
     * @return array<string,mixed>
     */
    private function default_sender(string $endpoint, string $body, array $headers, int $timeout): array {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        $curl = new \curl(['CURLOPT_TIMEOUT' => $timeout]);
        $responsebody = (string)$curl->post($endpoint, $body, ['CURLOPT_HTTPHEADER' => $headers]);

        return [
            'httpcode' => (int)$curl->get_info()['http_code'],
            'body' => $responsebody,
            'error' => $curl->error,
        ];
    }

    /**
     * @param array<string,mixed> $raw
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function normalise_response(array $raw, array $payload): array {
        $httpcode = isset($raw['httpcode']) ? (int)$raw['httpcode'] : 0;
        $error = trim((string)($raw['error'] ?? ''));
        $body = (string)($raw['body'] ?? '');

        $decoded = null;
        if ($body !== '') {
            try {
                $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                $decoded = null;
            }
        }

        if ($error !== '') {
            return [
                'status' => 'error',
                'errorcode' => 'n8nrequestfailed',
                'message' => $error,
                'httpcode' => $httpcode,
                'payload' => $payload,
            ];
        }

        if ($httpcode < 200 || $httpcode >= 300) {
            return [
                'status' => 'error',
                'errorcode' => 'n8nhttperror',
                'httpcode' => $httpcode,
                'response' => $decoded ?? $body,
                'payload' => $payload,
            ];
        }

        return [
            'status' => 'queued',
            'httpcode' => $httpcode,
            'response' => $decoded ?? $body,
            'payload' => $payload,
        ];
    }

    /**
     * Validate webhook endpoint constraints.
     *
     * @param string $endpoint
     * @return bool
     */
    private function is_secure_endpoint(string $endpoint): bool {
        $parts = parse_url($endpoint);
        if (!is_array($parts)) {
            return false;
        }

        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = (string)($parts['host'] ?? '');

        return $scheme === 'https' && $host !== '';
    }
}
