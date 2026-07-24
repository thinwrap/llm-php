<?php

declare(strict_types=1);

namespace Thinwrap\Llm\DTO\Chat;

use Thinwrap\Llm\Enum\ChatRole;

final readonly class AssistantMessage
{
    /**
     * @param list<ToolCall>|null $toolCalls
     */
    public function __construct(
        public ?string $content,
        public ?array $toolCalls = null,
        public ChatRole $role = ChatRole::Assistant,
    ) {}
}
