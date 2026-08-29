<?php

declare(strict_types=1);

/**
 * The PHP SDK's tests.
 *
 * No PHPUnit. This package has zero runtime dependencies on purpose, and a
 * test suite that needs a composer install to run is one that does not run on
 * a machine with no network — which is exactly where somebody debugging a
 * failed deploy is standing. `php tests/run.php` and nothing else.
 */

require __DIR__ . '/../src/Errors.php';
require __DIR__ . '/../src/Types.php';
require __DIR__ . '/../src/Transport.php';
require __DIR__ . '/../src/Webhooks.php';
require __DIR__ . '/../src/SecurHostClient.php';

use SecurHost\ChatResponse;
use SecurHost\Cost;
use SecurHost\SecurHostApiError;
use SecurHost\SecurHostAuthError;
use SecurHost\SecurHostClient;
use SecurHost\SecurHostConnectionError;
use SecurHost\SecurHostRateLimitError;
use SecurHost\Response;
use SecurHost\Transport;
use SecurHost\Webhooks;

// --------------------------------------------------------------------------- //
// A tiny harness
// --------------------------------------------------------------------------- //

$passed = 0;
$failed = 0;

function test(string $name, callable $body): void
{
    global $passed, $failed;

    try {
        $body();
        $passed++;
        echo "  ok   {$name}\n";
    } catch (\Throwable $error) {
        $failed++;
        echo "  FAIL {$name}\n       {$error->getMessage()}\n";
    }
}

function assertSame_(mixed $expected, mixed $actual, string $what = ''): void
{
    if ($expected !== $actual) {
        $e = var_export($expected, true);
        $a = var_export($actual, true);
        throw new \RuntimeException(trim("{$what} expected {$e}, got {$a}"));
    }
}

function assertTrue_(bool $value, string $what = ''): void
{
    if (!$value) {
        throw new \RuntimeException($what ?: 'expected true');
    }
}

function assertThrows(string $class, callable $body): \Throwable
{
    try {
        $body();
    } catch (\Throwable $error) {
        if (!($error instanceof $class)) {
            throw new \RuntimeException(sprintf('expected %s, got %s', $class, get_class($error)));
        }

        return $error;
    }

    throw new \RuntimeException("expected {$class}, nothing was thrown");
}

// --------------------------------------------------------------------------- //
// A transport that replays scripted responses and records what it was asked
// --------------------------------------------------------------------------- //

final class StubTransport implements Transport
{
    /** @var list<array{method: string, url: string, headers: array, body: ?string}> */
    public array $calls = [];

    /** @var list<Response|\Throwable> */
    private array $queue;

    public array $streamLines = [];

    public function __construct(array $queue)
    {
        $this->queue = $queue;
    }

    public function send(string $method, string $url, array $headers, ?string $body): Response
    {
        $this->calls[] = compact('method', 'url', 'headers', 'body');

        // The last scripted response repeats, so a test about retries does not
        // have to script one entry per attempt.
        $next = count($this->queue) > 1 ? array_shift($this->queue) : $this->queue[0];

        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }

    public function stream(string $method, string $url, array $headers, ?string $body, callable $onLine): void
    {
        $this->calls[] = compact('method', 'url', 'headers', 'body');

        foreach ($this->streamLines as $line) {
            $onLine($line);
        }
    }
}

const CHAT_BODY = [
    'choices' => [['message' => ['content' => 'Hello there'], 'finish_reason' => 'stop']],
    'usage' => ['prompt_tokens' => 9, 'completion_tokens' => 3, 'total_tokens' => 12],
    'model' => 'cheap-model',
];

const COST_HEADERS = [
    'x-securhost-cost' => '0.001260',
    'x-securhost-cost-original' => '0.003060',
    'x-securhost-saved' => '0.001800',
    'x-securhost-model' => 'cheap-model',
    'x-securhost-model-requested' => 'expensive-model',
    'x-securhost-request-id' => 'req_abc123',
];

function ok(array $body = CHAT_BODY, array $headers = COST_HEADERS, int $status = 200): Response
{
    return new Response($status, $headers, json_encode($body));
}

function client(StubTransport $transport, int $maxRetries = 3): SecurHostClient
{
    // The no-op sleeper is what keeps the retry tests instant.
    return new SecurHostClient('nxs_test', 'https://api.test', $maxRetries, $transport, static fn () => null);
}

// --------------------------------------------------------------------------- //

echo "\nCost headers\n";

test('a reply carries the cost headers as exact strings', function () {
    $transport = new StubTransport([ok()]);
    $reply = client($transport)->chat([['role' => 'user', 'content' => 'Hi']]);

    assertSame_('Hello there', $reply->outputText());
    assertSame_('0.001260', $reply->cost->amount);
    assertSame_('0.001800', $reply->cost->saved);
    assertSame_('cheap-model', $reply->cost->model);
    assertTrue_($reply->cost->rerouted, 'rerouted');
    assertTrue_($reply->cost->reported, 'reported');
    assertSame_('req_abc123', $reply->requestId);
    assertSame_(12, $reply->usage->totalTokens);
});

test('money stays a string so a double cannot round it', function () {
    $headers = COST_HEADERS;
    $headers['x-securhost-cost'] = '0.0000000001';
    $transport = new StubTransport([ok(CHAT_BODY, $headers)]);

    $reply = client($transport)->chat([['role' => 'user', 'content' => 'Hi']]);

    assertTrue_(is_string($reply->cost->amount), 'amount is a string');
    assertSame_('0.0000000001', $reply->cost->amount);
});

test('a gateway that sends no cost headers says so rather than claiming free', function () {
    $transport = new StubTransport([ok(CHAT_BODY, [])]);
    $reply = client($transport)->chat([['role' => 'user', 'content' => 'Hi']]);

    assertSame_(false, $reply->cost->reported);
    assertSame_('0', $reply->cost->amount);
});

test('the same model in and out is not a reroute', function () {
    $headers = COST_HEADERS;
    $headers['x-securhost-model-requested'] = 'cheap-model';
    $transport = new StubTransport([ok(CHAT_BODY, $headers)]);

    $reply = client($transport)->chat([['role' => 'user', 'content' => 'Hi']]);

    assertSame_(false, $reply->cost->rerouted);
});

echo "\nRequest shape\n";

test('the raw OpenAI envelope is passed through untouched', function () {
    $transport = new StubTransport([ok()]);
    $reply = client($transport)->chat([['role' => 'user', 'content' => 'Hi']]);

    assertSame_(CHAT_BODY, $reply->raw);
});

test('requestType is sent as the namespaced extension', function () {
    $transport = new StubTransport([ok()]);
    client($transport)->chat([['role' => 'user', 'content' => 'Hi']], ['requestType' => 'summarization']);

    $sent = json_decode($transport->calls[0]['body'], true);
    assertSame_('summarization', $sent['securhost_request_type']);
});

test('an unrecognised option is passed through, not dropped', function () {
    $transport = new StubTransport([ok()]);
    client($transport)->chat([['role' => 'user', 'content' => 'Hi']], ['seed' => 42]);

    $sent = json_decode($transport->calls[0]['body'], true);
    assertSame_(42, $sent['seed']);
});

test('the bearer token and user agent are on every request', function () {
    $transport = new StubTransport([ok()]);
    client($transport)->chat([['role' => 'user', 'content' => 'Hi']]);

    $headers = $transport->calls[0]['headers'];
    assertSame_('Bearer nxs_test', $headers['Authorization']);
    assertTrue_(str_starts_with($headers['User-Agent'], 'securhost-ai-php/'), 'user agent');
});

test('a missing api key is refused before any request', function () {
    assertThrows(\InvalidArgumentException::class, static fn () => new SecurHostClient(''));
});

echo "\nErrors and retries\n";

test('401 raises an auth error and is not retryable', function () {
    $transport = new StubTransport([
        new Response(401, [], json_encode(['error' => ['message' => 'Invalid API key.', 'code' => 'invalid_api_key']])),
    ]);

    $error = assertThrows(SecurHostAuthError::class, static fn () => client($transport, 0)
        ->chat([['role' => 'user', 'content' => 'Hi']]));

    assertSame_(401, $error->status);
    assertSame_('invalid_api_key', $error->errorCode);
    assertSame_(false, $error->isRetryable());
});

test('403 keeps the code so a caller need not match on prose', function () {
    $transport = new StubTransport([
        new Response(403, [], json_encode(['error' => ['message' => 'no', 'code' => 'insufficient_permission']])),
    ]);

    $error = assertThrows(SecurHostApiError::class, static fn () => client($transport, 0)->jobAgents());

    assertSame_('insufficient_permission', $error->errorCode);
});

test('429 is retried and then succeeds', function () {
    $transport = new StubTransport([
        new Response(429, ['retry-after' => '0'], json_encode(['error' => ['message' => 'slow down']])),
        ok(),
    ]);

    $reply = client($transport, 2)->chat([['role' => 'user', 'content' => 'Hi']]);

    assertSame_('Hello there', $reply->outputText());
    assertSame_(2, count($transport->calls));
});

test('429 carries retryAfter from the server', function () {
    $transport = new StubTransport([
        new Response(429, ['retry-after' => '7'], json_encode(['error' => ['message' => 'slow']])),
    ]);

    $error = assertThrows(SecurHostRateLimitError::class, static fn () => client($transport, 0)
        ->chat([['role' => 'user', 'content' => 'Hi']]));

    assertSame_(7, $error->retryAfter);
});

test('a 400 is never retried', function () {
    $transport = new StubTransport([new Response(400, [], json_encode(['error' => ['message' => 'bad']]))]);

    assertThrows(SecurHostApiError::class, static fn () => client($transport, 3)->chat([]));
    assertSame_(1, count($transport->calls), 'a 400 will not become a 200 by asking again');
});

test('5xx is retried', function () {
    $transport = new StubTransport([new Response(502, [], '{}'), ok()]);

    client($transport, 2)->chat([['role' => 'user', 'content' => 'Hi']]);

    assertSame_(2, count($transport->calls));
});

test('a connection that never landed is retried', function () {
    $transport = new StubTransport([new SecurHostConnectionError('down'), ok()]);

    client($transport, 2)->chat([['role' => 'user', 'content' => 'Hi']]);

    assertSame_(2, count($transport->calls));
});

test('a non-JSON error body still produces a typed error', function () {
    $transport = new StubTransport([new Response(500, [], '<html>gateway timeout</html>')]);

    $error = assertThrows(SecurHostApiError::class, static fn () => client($transport, 0)
        ->chat([['role' => 'user', 'content' => 'Hi']]));

    assertSame_(500, $error->status);
});

echo "\nStreaming\n";

test('content deltas arrive in order and [DONE] is not one of them', function () {
    $transport = new StubTransport([ok()]);
    $transport->streamLines = [
        'data: ' . json_encode(['choices' => [['delta' => ['content' => 'Hel']]]]),
        '',
        'data: ' . json_encode(['choices' => [['delta' => ['content' => 'lo']]]]),
        'data: [DONE]',
    ];

    $chunks = [];
    client($transport)->stream([['role' => 'user', 'content' => 'Hi']], static function (string $c) use (&$chunks) {
        $chunks[] = $c;
    });

    assertSame_(['Hel', 'lo'], $chunks);
});

test('a malformed frame is skipped rather than fatal', function () {
    $transport = new StubTransport([ok()]);
    $transport->streamLines = [
        'data: {not json',
        'data: ' . json_encode(['choices' => [['delta' => ['content' => 'fine']]]]),
    ];

    $chunks = [];
    client($transport)->stream([['role' => 'user', 'content' => 'Hi']], static function (string $c) use (&$chunks) {
        $chunks[] = $c;
    });

    assertSame_(['fine'], $chunks);
});

echo "\nResources\n";

test('job agents map onto the REST shape', function () {
    $transport = new StubTransport([
        new Response(201, [], json_encode(['id' => 1, 'name' => 'Outbound', 'sandbox' => true, 'is_active' => false])),
    ]);

    $agent = client($transport)->createJobAgent('Outbound', ['objective' => 'Find prospects']);

    $sent = json_decode($transport->calls[0]['body'], true);
    assertSame_('Outbound', $sent['name']);
    assertSame_(['objective' => 'Find prospects'], $sent['role_brief']);
    assertSame_(true, $agent['sandbox']);
    assertSame_(false, $agent['is_active']);
});

test('an agent with no brief sends an object, not an empty array', function () {
    // json_encode turns [] into `[]`, and the gateway expects an object here.
    $transport = new StubTransport([new Response(201, [], '{}')]);

    client($transport)->createJobAgent('Outbound');

    assertTrue_(str_contains($transport->calls[0]['body'], '"role_brief":{}'), 'role_brief is an object');
});

test('a top-up returns a payment page, not a balance', function () {
    $transport = new StubTransport([
        new Response(201, [], json_encode([
            'status' => 'created',
            'payment_url' => 'https://rzp.io/l/x',
            'amount' => '2000.00',
        ])),
    ]);

    $intent = client($transport)->topUp('2000.00');

    assertSame_('created', $intent['status']);
    assertSame_('https://rzp.io/l/x', $intent['payment_url']);
    assertTrue_(!array_key_exists('balance', $intent), 'a link is not money received');
    assertSame_('2000.00', json_decode($transport->calls[0]['body'], true)['amount']);
});

test('list endpoints unwrap the data envelope', function () {
    $transport = new StubTransport([
        new Response(200, [], json_encode(['data' => [['id' => 1], ['id' => 2]]])),
    ]);

    assertSame_(2, count(client($transport)->keys()));
});

test('a delete reaches the right verb and path', function () {
    $transport = new StubTransport([new Response(200, [], json_encode(['id' => 7, 'is_active' => false]))]);

    client($transport)->revokeKey(7);

    assertSame_('DELETE', $transport->calls[0]['method']);
    assertSame_('https://api.test/v1/keys/7', $transport->calls[0]['url']);
});

echo "\nWebhook verification\n";

const SECRET = 'whsec_test_secret';

function sign(string $body, string $timestamp): string
{
    return hash_hmac('sha256', $timestamp . '.' . $body, SECRET);
}

test('a genuine delivery verifies', function () {
    $body = '{"event":"invoice.issued"}';
    $ts = (string) time();

    assertSame_(true, Webhooks::verify(SECRET, $body, $ts, sign($body, $ts)));
});

test('a tampered body is refused', function () {
    $ts = (string) time();
    $signature = sign('{"amount":10}', $ts);

    assertSame_(false, Webhooks::verify(SECRET, '{"amount":10000}', $ts, $signature));
});

test('a stale payload is refused even though the signature is valid', function () {
    $body = '{"event":"invoice.issued"}';
    $old = (string) (time() - 4000);

    assertSame_(false, Webhooks::verify(SECRET, $body, $old, sign($body, $old)));
});

test('a payload stamped in the future is refused too', function () {
    $body = '{"event":"invoice.issued"}';
    $ahead = (string) (time() + 4000);

    assertSame_(false, Webhooks::verify(SECRET, $body, $ahead, sign($body, $ahead)));
});

test('a wrong secret is refused', function () {
    $body = '{"event":"invoice.issued"}';
    $ts = (string) time();

    assertSame_(false, Webhooks::verify('whsec_someone_elses', $body, $ts, sign($body, $ts)));
});

test('a signature of the wrong length is refused without throwing', function () {
    assertSame_(false, Webhooks::verify(SECRET, '{}', (string) time(), 'short'));
});

test('a non-numeric timestamp is refused', function () {
    assertSame_(false, Webhooks::verify(SECRET, '{}', 'yesterday', str_repeat('a', 64)));
});

test('an empty secret never verifies', function () {
    $body = '{}';
    $ts = (string) time();

    assertSame_(false, Webhooks::verify('', $body, $ts, sign($body, $ts)));
});

// --------------------------------------------------------------------------- //

echo "\n{$passed} passed, {$failed} failed\n\n";
exit($failed === 0 ? 0 : 1);
