<?php

declare(strict_types=1);

namespace Thinwrap\Llm\DTO\Chat;

/**
 * The function invoked by a tool call. `arguments` is a JSON-encoded string
 * (NOT a decoded array), mirroring the OpenAI wire shape.
 */
final readonly class ToolCallFunction
{
    public function __construct(
        public string $name,
        public string $arguments,
    ) {}
}
