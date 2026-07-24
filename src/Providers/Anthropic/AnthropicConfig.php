<?php

declare(strict_types=1);

namespace Thinwrap\Llm\Providers\Anthropic;

final readonly class AnthropicConfig
{
    /**
     * @param array<string,string>|null $headers
     */
    public function __construct(
        public string $apiKey,
        public ?string $baseUrl = null,
        public ?string $anthropicVersion = null,
        public ?int $defaultMaxTokens = null,
        public ?array $headers = null,
    ) {}
}
