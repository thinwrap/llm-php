<?php

declare(strict_types=1);

namespace Thinwrap\Llm\Providers\Gemini;

final readonly class GeminiConfig
{
    /**
     * @param array<string,string>|null $headers
     */
    public function __construct(
        public string $apiKey,
        public ?string $baseUrl = null,
        public ?int $defaultMaxTokens = null,
        public ?array $headers = null,
    ) {}
}
