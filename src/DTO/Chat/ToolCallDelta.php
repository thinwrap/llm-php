<?php

declare(strict_types=1);

namespace Thinwrap\Llm\DTO\Chat;

final readonly class ToolCallDelta
{
    public function __construct(
        public int $index,
        public ?string $id = null,
        public ?string $functionName = null,
        public ?string $argumentsDelta = null,
    ) {}
}
