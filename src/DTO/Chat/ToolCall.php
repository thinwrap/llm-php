<?php

declare(strict_types=1);

namespace Thinwrap\Llm\DTO\Chat;

final readonly class ToolCall
{
    public string $type;

    public function __construct(
        public string $id,
        public ToolCallFunction $function,
    ) {
        $this->type = 'function';
    }
}
