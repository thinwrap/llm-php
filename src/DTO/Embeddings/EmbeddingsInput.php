<?php

declare(strict_types=1);

namespace Thinwrap\Llm\DTO\Embeddings;

final readonly class EmbeddingsInput
{
    /**
     * @param string|list<string>                                                                        $input
     * @param array{body?:array<string,mixed>,headers?:array<string,string>,query?:array<string,string>}|null $_passthrough
     */
    public function __construct(
        public string $model,
        public string|array $input,
        public ?int $dimensions = null,
        public ?array $_passthrough = null,
    ) {}
}
