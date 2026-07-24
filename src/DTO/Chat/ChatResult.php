<?php

declare(strict_types=1);

namespace Thinwrap\Llm\DTO\Chat;

use Thinwrap\Llm\Enum\FinishReason;

final readonly class ChatResult
{
    /**
     * @param mixed $raw the verbatim vendor response body
     */
    public function __construct(
        public AssistantMessage $message,
        public FinishReason $finishReason,
        public Usage $usage,
        public string $model,
        public mixed $raw,
    ) {}
}
