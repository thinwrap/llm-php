<?php

declare(strict_types=1);

namespace Thinwrap\Llm\DTO\Chat;

/**
 * The normalized chat-completion request. Identical across every provider —
 * switching vendor is a change of provider id + `model` only.
 *
 * `toolChoice` is already OpenAI-shaped and forwarded verbatim: pass
 * `'auto'` / `'none'` / `'required'`, or the array form
 * `['type' => 'function', 'function' => ['name' => 'get_weather']]`.
 */
final readonly class ChatInput
{
    /**
     * @param list<ChatMessage>                                                                          $messages
     * @param list<ToolDefinition>|null                                                                  $tools
     * @param string|array{type:string,function:array{name:string}}|null                                 $toolChoice
     * @param string|list<string>|null                                                                   $stop
     * @param array{body?:array<string,mixed>,headers?:array<string,string>,query?:array<string,string>}|null $_passthrough
     */
    public function __construct(
        public string $model,
        public array $messages,
        public ?array $tools = null,
        public string|array|null $toolChoice = null,
        public ?ResponseFormat $responseFormat = null,
        public ?float $temperature = null,
        public ?float $topP = null,
        public ?int $maxOutputTokens = null,
        public string|array|null $stop = null,
        public ?ReasoningOptions $reasoning = null,
        public ?array $_passthrough = null,
    ) {}
}
