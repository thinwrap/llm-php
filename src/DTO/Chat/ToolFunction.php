<?php

declare(strict_types=1);

namespace Thinwrap\Llm\DTO\Chat;

/**
 * A function tool the model may call. `parameters` is a JSON Schema object.
 */
final readonly class ToolFunction
{
    /**
     * @param array<string,mixed>|null $parameters
     */
    public function __construct(
        public string $name,
        public ?string $description = null,
        public ?array $parameters = null,
    ) {}
}
