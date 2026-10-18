<?php

declare(strict_types=1);

namespace Thinwrap\Llm\DTO\Chat;

use Thinwrap\Llm\Enum\ChatRole;

final readonly class AssistantMessage
{
    /**
     * @param list<ToolCall>|null      $toolCalls
     * @param array<string,mixed>|null $providerMetadata opaque, keyed by provider id (`anthropic` carries the
     *                                                   verbatim content blocks, thinking included) — pass it
     *                                                   on the {@see ChatMessage} that replays this turn
     */
    public function __construct(
        public ?string $content,
        public ?array $toolCalls = null,
        public ChatRole $role = ChatRole::Assistant,
        public ?array $providerMetadata = null,
    ) {}
}
