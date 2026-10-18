<?php

declare(strict_types=1);

namespace Thinwrap\Llm\Providers\Shared;

use Thinwrap\Llm\Util\RedactsSecrets;

/**
 * Config shared by all 15 first-class OpenAI-compatible providers. `apiKey` is
 * optional (self-hosted vLLM/Ollama/LM Studio may need none); `baseUrl` is
 * required for providers with no default (azure-openai, cloudflare) and to point
 * a self-host somewhere other than localhost.
 */
final readonly class OpenAiCompatConfig
{
    use RedactsSecrets;

    /**
     * @param array<string,string>|null $headers extra headers merged onto every request
     */
    public function __construct(
        #[\SensitiveParameter]
        public ?string $apiKey = null,
        public ?string $baseUrl = null,
        #[\SensitiveParameter]
        public ?array $headers = null,
    ) {}
}
