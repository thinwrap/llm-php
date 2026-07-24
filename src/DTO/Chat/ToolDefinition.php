<?php

declare(strict_types=1);

namespace Thinwrap\Llm\DTO\Chat;

final readonly class ToolDefinition
{
    public string $type;

    public function __construct(public ToolFunction $function)
    {
        $this->type = 'function';
    }
}
