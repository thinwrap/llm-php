<?php

declare(strict_types=1);

namespace Thinwrap\Llm\Enum;

enum ReasoningEffort: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
}
