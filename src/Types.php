<?php

declare(strict_types=1);

namespace SecurHost;

/**
 * What one request cost, read from the X-SecurHost-* response headers.
 *
 * **Money is a string here, not a float.** PHP floats are IEEE 754 doubles, so
 * `0.1 + 0.2 !== 0.3` and a per-request cost of `0.0000000001` rounds to
 * something else entirely. The gateway sends exact decimals; parsing them into
 * a double would quietly lose precision on the single value a customer is
 * checking. Use bcmath or a Money library on these strings if you need to add
 * them up.
 */
final class Cost
{
    public function __construct(
        public readonly string $amount = '0',
        public readonly string $original = '0',
        public readonly string $saved = '0',
        public readonly string $model = '',
        public readonly string $modelRequested = '',
        public readonly bool $rerouted = false,
        /** Distinguishes "this request was free" from "the gateway never said". */
        public readonly bool $reported = false,
    ) {
    }

    /** @param array<string, string> $headers lower-cased header names */
    public static function fromHeaders(array $headers): self
    {
        $amount = $headers['x-securhost-cost'] ?? null;
        $model = $headers['x-securhost-model'] ?? '';
        $requested = $headers['x-securhost-model-requested'] ?? '';

        return new self(
            amount: $amount ?? '0',
            original: $headers['x-securhost-cost-original'] ?? '0',
            saved: $headers['x-securhost-saved'] ?? '0',
            model: $model,
            modelRequested: $requested,
            rerouted: $model !== '' && $requested !== '' && $model !== $requested,
            reported: $amount !== null,
        );
    }
}

/** Token counts for one request. Integers, so plain ints are correct. */
final class Usage
{
    public function __construct(
        public readonly int $promptTokens = 0,
        public readonly int $completionTokens = 0,
        public readonly int $totalTokens = 0,
    ) {
    }

    public static function fromEnvelope(array $body): self
    {
        $usage = $body['usage'] ?? [];
        $prompt = (int) ($usage['prompt_tokens'] ?? 0);
        $completion = (int) ($usage['completion_tokens'] ?? 0);

        return new self(
            promptTokens: $prompt,
            completionTokens: $completion,
            totalTokens: (int) ($usage['total_tokens'] ?? $prompt + $completion),
        );
    }
}

/**
 * One completion.
 *
 * `raw` is the byte-for-byte OpenAI envelope, untouched. Anything this class
 * does not expose is still reachable there, and a gateway change that adds a
 * field does not need an SDK release before you can read it.
 */
final class ChatResponse
{
    public function __construct(
        public readonly array $raw,
        public readonly Usage $usage,
        public readonly Cost $cost,
        public readonly string $requestId = '',
    ) {
    }

    /** The assistant's text, or '' for a tool call. Never throws. */
    public function outputText(): string
    {
        return (string) ($this->raw['choices'][0]['message']['content'] ?? '');
    }

    /** @return list<array> */
    public function toolCalls(): array
    {
        return $this->raw['choices'][0]['message']['tool_calls'] ?? [];
    }

    public function finishReason(): string
    {
        return (string) ($this->raw['choices'][0]['finish_reason'] ?? '');
    }
}

/** One embedding call. */
final class EmbeddingResponse
{
    public function __construct(
        public readonly array $raw,
        public readonly Usage $usage,
        public readonly Cost $cost,
        public readonly string $requestId = '',
    ) {
    }

    /** @return list<list<float>> */
    public function vectors(): array
    {
        return array_map(
            static fn (array $item): array => $item['embedding'] ?? [],
            $this->raw['data'] ?? [],
        );
    }
}
