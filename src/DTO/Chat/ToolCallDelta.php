<?php

declare(strict_types=1);

namespace Thinwrap\Llm\DTO\Chat;

final readonly class ToolCallDelta
{
    /**
     * @param array<string,mixed>|null $providerMetadata opaque, keyed by provider id — the
     *                                                   {@see ToolCall::$providerMetadata} for this call
     */
    public function __construct(
        public int $index,
        public ?string $id = null,
        public ?string $functionName = null,
        public ?string $argumentsDelta = null,
        public ?array $providerMetadata = null,
    ) {}
}
