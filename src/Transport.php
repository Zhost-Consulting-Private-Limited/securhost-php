<?php

declare(strict_types=1);

namespace SecurHost;

/** One HTTP response, reduced to what this client needs. */
final class Response
{
    public function __construct(
        public readonly int $status,
        /** @var array<string, string> lower-cased header names */
        public readonly array $headers,
        public readonly string $body,
    ) {
    }

    public function json(): array
    {
        $decoded = json_decode($this->body, true);

        return is_array($decoded) ? $decoded : [];
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}

/**
 * The seam that makes this client testable.
 *
 * A test that drives a real socket is testing the network; a test that
 * replaces this interface is testing the client. It is public rather than
 * internal because it is also how a caller supplies a proxy, a corporate CA
 * bundle, or their framework's own HTTP stack.
 */
interface Transport
{
    /** @param array<string, string> $headers */
    public function send(string $method, string $url, array $headers, ?string $body): Response;

    /**
     * Stream a response line by line, calling $onLine for each.
     *
     * Separate from send() because buffering a stream defeats the point of
     * asking for one: the first token should reach the caller while the rest
     * is still arriving.
     */
    public function stream(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        callable $onLine,
    ): void;
}

/**
 * curl, because it is in every PHP install worth deploying to.
 *
 * Deliberately not Guzzle: a client library that drags in a HTTP stack forces
 * its major version on every application that installs it, and this needs
 * about forty lines of curl.
 */
final class CurlTransport implements Transport
{
    public function __construct(
        private readonly float $timeout = 60.0,
        private readonly float $connectTimeout = 10.0,
    ) {
    }

    public function send(string $method, string $url, array $headers, ?string $body): Response
    {
        $handle = $this->handle($method, $url, $headers, $body);

        $responseHeaders = [];
        curl_setopt($handle, CURLOPT_HEADERFUNCTION, function ($_, string $line) use (&$responseHeaders): int {
            $length = strlen($line);
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
            }

            return $length;
        });
        curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);

        $raw = curl_exec($handle);

        if ($raw === false) {
            $error = curl_error($handle);
            curl_close($handle);

            throw new SecurHostConnectionError("Could not reach {$url}: {$error}");
        }

        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        return new Response($status, $responseHeaders, (string) $raw);
    }

    public function stream(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        callable $onLine,
    ): void {
        $handle = $this->handle($method, $url, $headers, $body);
        $buffer = '';

        curl_setopt($handle, CURLOPT_WRITEFUNCTION, function ($_, string $chunk) use (&$buffer, $onLine): int {
            $length = strlen($chunk);
            $buffer .= $chunk;

            // Split on newlines and keep the tail: an SSE frame can arrive
            // split across two TCP reads, and handing half a line to the
            // parser drops the event rather than delaying it.
            while (($break = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $break);
                $buffer = substr($buffer, $break + 1);
                $onLine($line);
            }

            return $length;
        });

        $ok = curl_exec($handle);
        $error = curl_error($handle);
        curl_close($handle);

        if ($ok === false) {
            throw new SecurHostConnectionError("Could not reach {$url}: {$error}");
        }

        if ($buffer !== '') {
            $onLine($buffer);
        }
    }

    private function handle(string $method, string $url, array $headers, ?string $body)
    {
        $handle = curl_init($url);

        curl_setopt($handle, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($handle, CURLOPT_TIMEOUT, (int) $this->timeout);
        curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, (int) $this->connectTimeout);
        // Explicit, not inherited from php.ini. A client library that silently
        // accepts an unverified certificate because a host disabled it
        // globally is worse than one that fails.
        curl_setopt($handle, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($handle, CURLOPT_SSL_VERIFYHOST, 2);

        $formatted = [];
        foreach ($headers as $name => $value) {
            $formatted[] = "{$name}: {$value}";
        }
        curl_setopt($handle, CURLOPT_HTTPHEADER, $formatted);

        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        return $handle;
    }
}
