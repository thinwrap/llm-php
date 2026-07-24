<?php

declare(strict_types=1);

namespace Thinwrap\Llm\Enum;

enum FinishReason: string
{
    case Stop = 'stop';
    case Length = 'length';
    case ToolCalls = 'tool_calls';
    case ContentFilter = 'content_filter';
    case Unknown = 'unknown';
}
