<?php

declare(strict_types=1);

namespace Thinwrap\Llm\DTO\Embeddings;

final readonly class EmbeddingsUsage
{
    public function __construct(
        public int $inputTokens,
        public int $totalTokens,
    ) {}
}
