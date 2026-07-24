<?php

declare(strict_types=1);

namespace Thinwrap\Llm\DTO\Chat;

use Thinwrap\Llm\Enum\FinishReason;

final readonly class ChatStreamDelta
{
    /**
     * @param mixed $raw the verbatim vendor stream chunk
     */
    public function __construct(
        public ?string $contentDelta = null,
        public ?ToolCallDelta $toolCallDelta = null,
        public ?FinishReason $finishReason = null,
        public ?Usage $usage = null,
        public mixed $raw = null,
    ) {}
}
