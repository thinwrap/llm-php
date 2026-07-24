<?php

declare(strict_types=1);

namespace Thinwrap\Llm\DTO\Chat;

final readonly class TextPart implements ContentPart
{
    public string $type;

    public function __construct(public string $text)
    {
        $this->type = 'text';
    }
}
