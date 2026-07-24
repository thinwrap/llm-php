<?php

declare(strict_types=1);

namespace Thinwrap\Llm\Providers\Shared;

/**
 * Config shared by all 15 first-class OpenAI-compatible providers. `apiKey` is
 * optional (self-hosted vLLM/Ollama/LM Studio may need none); `baseUrl` is
 * required for providers with no default (azure-openai, cloudflare) and to point
 * a self-host somewhere other than localhost.
 */
final readonly class OpenAiCompatConfig
{
    /**
     * @param array<string,string>|null $headers extra headers merged onto every request
     */
    public function __construct(
        public ?string $apiKey = null,
        public ?string $baseUrl = null,
        public ?array $headers = null,
    ) {}
}
