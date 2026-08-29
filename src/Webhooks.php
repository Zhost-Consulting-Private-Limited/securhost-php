<?php

declare(strict_types=1);

namespace SecurHost;

final class Webhooks
{
    /** Deliveries older than this are refused even with a valid signature. */
    public const TOLERANCE_SECONDS = 300;

    /**
     * Whether a delivery is genuine.
     *
     * Three things have to hold, and dropping any one of them makes this
     * function worse than useless — it would then bless forged deliveries.
     *
     * 1. **`$body` must be the bytes as received.** Re-encoding a decoded
     *    array changes key order, whitespace and unicode escaping, and the
     *    signature will never match. This is the single most common reason a
     *    genuine delivery appears to fail verification. In Laravel that is
     *    `$request->getContent()`; in Symfony, `$request->getContent()`; in
     *    plain PHP, `file_get_contents('php://input')`.
     * 2. **The comparison is constant-time.** `===` on a signature leaks its
     *    correctness through timing, one byte at a time, and a webhook
     *    endpoint is something an attacker can call as often as they like.
     * 3. **The timestamp is checked.** Without it a valid signature is valid
     *    forever, and anyone who captured one delivery can replay it.
     */
    public static function verify(
        string $secret,
        string $body,
        string $timestamp,
        string $signature,
        int $toleranceSeconds = self::TOLERANCE_SECONDS,
        ?int $now = null,
    ): bool {
        if ($secret === '' || $signature === '' || $timestamp === '') {
            return false;
        }

        if (!is_numeric($timestamp)) {
            return false;
        }

        $sent = (int) $timestamp;
        $current = $now ?? time();

        // Both directions. A delivery stamped an hour in the future is as
        // wrong as one stamped an hour in the past, and only checking the
        // past leaves a forged future timestamp valid indefinitely.
        if (abs($current - $sent) > $toleranceSeconds) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $body, $secret);

        return hash_equals($expected, $signature);
    }
}
