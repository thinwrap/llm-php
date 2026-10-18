<?php

declare(strict_types=1);

namespace Thinwrap\Llm\Providers\Anthropic;

use Thinwrap\Llm\Util\RedactsSecrets;

final readonly class AnthropicConfig
{
    use RedactsSecrets;

    /**
     * @param array<string,string>|null $headers
     */
    public function __construct(
        #[\SensitiveParameter]
        public string $apiKey,
        public ?string $baseUrl = null,
        public ?string $anthropicVersion = null,
        public ?int $defaultMaxTokens = null,
        #[\SensitiveParameter]
        public ?array $headers = null,
    ) {}
}
