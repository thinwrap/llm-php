<?php

declare(strict_types=1);

namespace Thinwrap\Llm\DTO\Chat;

use Thinwrap\Llm\Enum\ChatRole;

final readonly class ChatMessage
{
    /**
     * @param string|list<ContentPart>|null $content
     * @param list<ToolCall>|null           $toolCalls  assistant turns that invoked tools
     * @param string|null                   $toolCallId required on `role: tool` — id of the call being answered
     */
    public function __construct(
        public ChatRole $role,
        public string|array|null $content,
        public ?array $toolCalls = null,
        public ?string $toolCallId = null,
        public ?string $name = null,
    ) {}
}
