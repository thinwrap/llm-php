<?php

declare(strict_types=1);

namespace Thinwrap\Llm\DTO\Embeddings;

final readonly class EmbeddingsResult
{
    /**
     * @param list<list<float>> $embeddings one float vector per input, in input order
     * @param mixed             $raw        the verbatim vendor response body
     */
    public function __construct(
        public array $embeddings,
        public EmbeddingsUsage $usage,
        public string $model,
        public mixed $raw,
    ) {}
}
