<?php

declare(strict_types=1);

namespace Thinwrap\Llm\Contract;

use Thinwrap\Llm\DTO\Embeddings\EmbeddingsInput;
use Thinwrap\Llm\DTO\Embeddings\EmbeddingsResult;

interface EmbeddingsConnectorInterface
{
    public function create(EmbeddingsInput $input): EmbeddingsResult;

    public function getId(): string;
}
