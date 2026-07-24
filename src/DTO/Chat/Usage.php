<?php

declare(strict_types=1);

namespace Thinwrap\Llm\DTO\Chat;

final readonly class Usage
{
    public function __construct(
        public int $inputTokens,
        public int $outputTokens,
        public int $totalTokens,
    ) {}
}
