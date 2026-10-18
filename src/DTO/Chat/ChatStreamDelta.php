<?php

declare(strict_types=1);

namespace Thinwrap\Llm\DTO\Chat;

use Thinwrap\Llm\Enum\FinishReason;

final readonly class ChatStreamDelta
{
    /**
     * @param mixed                    $raw              the verbatim vendor stream chunk
     * @param array<string,mixed>|null $providerMetadata set on the terminal delta only — the
     *                                                   {@see AssistantMessage::$providerMetadata} of the streamed turn
     */
    public function __construct(
        public ?string $contentDelta = null,
        public ?ToolCallDelta $toolCallDelta = null,
        public ?FinishReason $finishReason = null,
        public ?Usage $usage = null,
        public mixed $raw = null,
        public ?array $providerMetadata = null,
    ) {}
}
