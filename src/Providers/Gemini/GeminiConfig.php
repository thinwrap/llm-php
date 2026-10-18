<?php

declare(strict_types=1);

namespace Thinwrap\Llm\Providers\Gemini;

use Thinwrap\Llm\Util\RedactsSecrets;

final readonly class GeminiConfig
{
    use RedactsSecrets;

    /**
     * @param array<string,string>|null $headers
     */
    public function __construct(
        #[\SensitiveParameter]
        public string $apiKey,
        public ?string $baseUrl = null,
        public ?int $defaultMaxTokens = null,
        #[\SensitiveParameter]
        public ?array $headers = null,
    ) {}
}
