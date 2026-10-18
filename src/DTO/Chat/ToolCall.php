<?php

declare(strict_types=1);

namespace Thinwrap\Llm\DTO\Chat;

/**
 * One function call made by the model.
 *
 * `providerMetadata` is an opaque slot keyed by provider id (`gemini` carries
 * the call's `thoughtSignature`). Copy it into the next request unchanged: a
 * connector reads only its own key, so a history survives a provider switch.
 */
final readonly class ToolCall
{
    public string $type;

    /**
     * @param array<string,mixed>|null $providerMetadata
     */
    public function __construct(
        public string $id,
        public ToolCallFunction $function,
        public ?array $providerMetadata = null,
    ) {
        $this->type = 'function';
    }
}
