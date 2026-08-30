<?php

declare(strict_types=1);

namespace SecurHost;

/**
 * PHP client for the SecurHost Gateway.
 *
 * The gateway is OpenAI-compatible on purpose, so the honest first question is
 * why this exists. A PHP application can already point any OpenAI client at
 * the base URL and everything works. This exists for what those clients throw
 * away: the gateway reports what each request cost, what it would have cost on
 * the model that was asked for, and which model actually served it. Those
 * travel as `X-SecurHost-*` headers, because putting them in the body would break
 * the byte-for-byte compatibility the whole product rests on.
 *
 * Zero runtime dependencies beyond ext-curl and ext-json. A client library
 * that drags in a HTTP stack forces its major version on every application
 * that installs it, and this needs about forty lines of curl.
 */
class SecurHostClient
{
    public const VERSION = '0.1.2';
    public const DEFAULT_BASE_URL = 'https://securhost.com';

    private readonly string $baseUrl;
    private readonly Transport $transport;

    public function __construct(
        private readonly string $apiKey,
        string $baseUrl = self::DEFAULT_BASE_URL,
        private readonly int $maxRetries = 3,
        ?Transport $transport = null,
        /**
         * Injectable so a test proving a retry happened does not spend three
         * real seconds doing it. Defaults to usleep.
         *
         * @var null|callable(float): void
         */
        private $sleeper = null,
    ) {
        if ($apiKey === '') {
            throw new \InvalidArgumentException('An API key is required.');
        }

        $this->baseUrl = rtrim($baseUrl, '/');
        $this->transport = $transport ?? new CurlTransport();
    }

    // ---------------------------------------------------------------- chat --

    /**
     * One completion.
     *
     * @param list<array{role: string, content: mixed}> $messages
     * @param array<string, mixed> $options model, requestType, maxTokens,
     *        temperature, tools, noCache, plus anything the gateway accepts
     */
    public function chat(array $messages, array $options = []): ChatResponse
    {
        $response = $this->request('POST', '/v1/chat/completions', $this->chatPayload($messages, $options));
        $body = $response->json();

        return new ChatResponse(
            raw: $body,
            usage: Usage::fromEnvelope($body),
            cost: Cost::fromHeaders($response->headers),
            requestId: $response->header('X-SecurHost-Request-Id') ?? '',
        );
    }

    /**
     * Stream a completion, calling $onDelta with each content fragment.
     *
     * Not retried. By the time a stream fails it has already handed the caller
     * tokens, and retrying would repeat them.
     */
    public function stream(array $messages, callable $onDelta, array $options = []): void
    {
        $payload = $this->chatPayload($messages, $options);
        $payload['stream'] = true;

        $this->transport->stream(
            'POST',
            $this->baseUrl . '/v1/chat/completions',
            $this->headers(),
            json_encode($payload, JSON_THROW_ON_ERROR),
            static function (string $line) use ($onDelta): void {
                if (!str_starts_with($line, 'data:')) {
                    return;
                }

                $data = trim(substr($line, 5));
                if ($data === '' || $data === '[DONE]') {
                    return;
                }

                $event = json_decode($data, true);
                $delta = $event['choices'][0]['delta']['content'] ?? null;

                if (is_string($delta) && $delta !== '') {
                    $onDelta($delta);
                }
            },
        );
    }

    /** @param list<string>|string $input */
    public function embeddings(array|string $input, string $model = 'text-embedding-3-small'): EmbeddingResponse
    {
        $response = $this->request('POST', '/v1/embeddings', ['model' => $model, 'input' => $input]);
        $body = $response->json();

        return new EmbeddingResponse(
            raw: $body,
            usage: Usage::fromEnvelope($body),
            cost: Cost::fromHeaders($response->headers),
            requestId: $response->header('X-SecurHost-Request-Id') ?? '',
        );
    }

    public function models(): array
    {
        return $this->get('/v1/models')['data'] ?? [];
    }

    // --------------------------------------------------------------- usage --

    public function usage(): array
    {
        return $this->get('/v1/usage');
    }

    public function usageDaily(): array
    {
        return $this->get('/v1/usage/daily');
    }

    /** What routing has saved this workspace. The number the gateway is bought for. */
    public function savings(): array
    {
        return $this->get('/v1/usage/savings');
    }

    // ---------------------------------------------------------- job agents --

    /**
     * Deploy a job agent. It arrives stopped and sandboxed.
     *
     * There is deliberately no `promote` here. The moment an agent starts
     * sending real mail on a customer's behalf stays a human act in the
     * console, on the screen that shows its guardrails.
     */
    public function createJobAgent(
        string $name,
        array $roleBrief = [],
        int $autonomyLevel = 0,
        int $dailyActionCap = 50,
    ): array {
        return $this->post('/v1/job-agents', [
            'name' => $name,
            'role_brief' => $roleBrief === [] ? new \stdClass() : $roleBrief,
            'autonomy_level' => $autonomyLevel,
            'daily_action_cap' => $dailyActionCap,
        ]);
    }

    public function jobAgents(): array
    {
        return $this->get('/v1/job-agents')['data'] ?? [];
    }

    public function jobAgent(int $agentId): array
    {
        return $this->get("/v1/job-agents/{$agentId}");
    }

    public function pauseJobAgent(int $agentId, string $reason = ''): array
    {
        return $this->post("/v1/job-agents/{$agentId}/pause", $reason === '' ? [] : ['reason' => $reason]);
    }

    public function resumeJobAgent(int $agentId): array
    {
        return $this->post("/v1/job-agents/{$agentId}/resume", []);
    }

    public function jobAgentActivity(int $agentId, int $limit = 50): array
    {
        return $this->get("/v1/job-agents/{$agentId}/activity?limit={$limit}")['data'] ?? [];
    }

    // ---------------------------------------------------- keys / webhooks ---

    public function keys(): array
    {
        return $this->get('/v1/keys')['data'] ?? [];
    }

    /** The raw key comes back once and never again. */
    public function createKey(string $name, array $permissions = [], ?int $monthlyTokenLimit = null): array
    {
        $payload = ['name' => $name, 'permissions' => $permissions];
        if ($monthlyTokenLimit !== null) {
            $payload['monthly_token_limit'] = $monthlyTokenLimit;
        }

        return $this->post('/v1/keys', $payload);
    }

    public function revokeKey(int $keyId): array
    {
        return $this->request('DELETE', "/v1/keys/{$keyId}")->json();
    }

    public function webhooks(): array
    {
        return $this->get('/v1/webhooks')['data'] ?? [];
    }

    /** The signing secret comes back once. Store it before you discard this. */
    public function registerWebhook(string $url, array $events, string $description = ''): array
    {
        return $this->post('/v1/webhooks', [
            'url' => $url,
            'events' => $events,
            'description' => $description,
        ]);
    }

    public function deleteWebhook(int $endpointId): array
    {
        return $this->request('DELETE', "/v1/webhooks/{$endpointId}")->json();
    }

    public function logs(int $limit = 50, string $action = ''): array
    {
        $query = "?limit={$limit}" . ($action === '' ? '' : '&action=' . rawurlencode($action));

        return $this->get('/v1/logs' . $query)['data'] ?? [];
    }

    // ------------------------------------------------------------- billing --

    public function invoices(): array
    {
        return $this->get('/v1/billing/invoices')['data'] ?? [];
    }

    public function wallet(): array
    {
        return $this->get('/v1/billing/wallet');
    }

    /**
     * Start a top-up. `$amount` is a decimal string, in rupees.
     *
     * Returns a payment page, not a receipt. Nothing is credited until the
     * payment provider confirms it out of band, so a caller that treats this
     * as money received will be wrong for as long as the customer takes to
     * reach for a card.
     */
    public function topUp(string $amount, string $callbackUrl = ''): array
    {
        $payload = ['amount' => $amount];
        if ($callbackUrl !== '') {
            $payload['callback_url'] = $callbackUrl;
        }

        return $this->post('/v1/billing/topup', $payload);
    }

    // -------------------------------------------------------------- guts ----

    private function chatPayload(array $messages, array $options): array
    {
        $payload = ['model' => $options['model'] ?? 'gpt-4o', 'messages' => $messages];

        if (isset($options['requestType'])) {
            $payload['securhost_request_type'] = $options['requestType'];
        }
        if (isset($options['maxTokens'])) {
            $payload['max_tokens'] = $options['maxTokens'];
        }
        if (isset($options['temperature'])) {
            $payload['temperature'] = $options['temperature'];
        }
        if (isset($options['tools'])) {
            $payload['tools'] = $options['tools'];
        }
        if (!empty($options['noCache'])) {
            $payload['securhost_no_cache'] = true;
        }

        foreach ($options as $key => $value) {
            if (!in_array($key, ['model', 'requestType', 'maxTokens', 'temperature', 'tools', 'noCache'], true)) {
                $payload[$key] = $value;
            }
        }

        return $payload;
    }

    private function get(string $path): array
    {
        return $this->request('GET', $path)->json();
    }

    private function post(string $path, array $payload): array
    {
        return $this->request('POST', $path, $payload)->json();
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return [
            'Authorization' => "Bearer {$this->apiKey}",
            'Content-Type' => 'application/json',
            'User-Agent' => 'securhost-ai-php/' . self::VERSION,
        ];
    }

    /**
     * Retry only what is worth retrying: a connection that never landed, a
     * 429, and 5xx. Backoff is exponential with jitter, so a fleet that hit
     * the same limit together does not retry in lockstep. Retry-After wins
     * where the server sent one, because it knows when the window opens.
     */
    private function request(string $method, string $path, ?array $payload = null): Response
    {
        $url = $this->baseUrl . $path;
        $body = $payload === null ? null : json_encode($payload, JSON_THROW_ON_ERROR);
        $last = null;

        for ($attempt = 0; $attempt <= $this->maxRetries; $attempt++) {
            try {
                $response = $this->transport->send($method, $url, $this->headers(), $body);
            } catch (SecurHostConnectionError $exception) {
                $last = $exception;
                if ($attempt < $this->maxRetries) {
                    $this->sleep($this->backoff($attempt));
                    continue;
                }
                throw $exception;
            }

            if ($response->status < 400) {
                return $response;
            }

            $error = $this->asError($response);

            if ($error->isRetryable() && $attempt < $this->maxRetries) {
                $wait = $error instanceof SecurHostRateLimitError && $error->retryAfter !== null
                    ? (float) $error->retryAfter
                    : $this->backoff($attempt);
                $this->sleep($wait);
                $last = $error;
                continue;
            }

            throw $error;
        }

        throw $last ?? new SecurHostConnectionError('Request failed with no response');
    }

    private function asError(Response $response): SecurHostError
    {
        $body = $response->json();
        $error = $body['error'] ?? [];
        $message = $error['message'] ?? "Request failed with {$response->status}";

        $arguments = [
            'status' => $response->status,
            'errorCode' => (string) ($error['code'] ?? ''),
            'type' => (string) ($error['type'] ?? ''),
            'requestId' => $response->header('X-SecurHost-Request-Id') ?? '',
            'body' => $body,
        ];

        if ($response->status === 401) {
            return new SecurHostAuthError($message, ...$arguments);
        }

        if ($response->status === 429) {
            $rateLimited = new SecurHostRateLimitError($message, ...$arguments);
            $header = $response->header('Retry-After');
            if ($header !== null && is_numeric($header)) {
                $rateLimited->retryAfter = (int) $header;
            }

            return $rateLimited;
        }

        return new SecurHostApiError($message, ...$arguments);
    }

    private function backoff(int $attempt): float
    {
        return min(2 ** $attempt, 8) * (0.5 + mt_rand() / mt_getrandmax() / 2);
    }

    private function sleep(float $seconds): void
    {
        if ($this->sleeper !== null) {
            ($this->sleeper)($seconds);

            return;
        }

        if ($seconds > 0) {
            usleep((int) ($seconds * 1_000_000));
        }
    }
}
