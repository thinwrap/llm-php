<?php

declare(strict_types=1);

namespace Thinwrap\Llm\Contract;

use Thinwrap\Llm\DTO\Chat\ChatInput;
use Thinwrap\Llm\DTO\Chat\ChatResult;
use Thinwrap\Llm\DTO\Chat\ChatStreamDelta;

interface ChatConnectorInterface
{
    public function complete(ChatInput $input): ChatResult;

    /**
     * @return \Generator<int,ChatStreamDelta>
     */
    public function stream(ChatInput $input): \Generator;

    public function getId(): string;
}
