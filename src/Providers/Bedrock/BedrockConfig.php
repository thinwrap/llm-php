<?php

declare(strict_types=1);

namespace Thinwrap\Llm\Providers\Bedrock;

/**
 * AWS Bedrock credentials. `headers` participate in the SigV4 signature.
 */
final readonly class BedrockConfig
{
    /**
     * @param array<string,string>|null $headers
     */
    public function __construct(
        public string $region,
        public string $accessKeyId,
        public string $secretAccessKey,
        public ?string $sessionToken = null,
        public ?string $baseUrl = null,
        public ?int $defaultMaxTokens = null,
        public ?array $headers = null,
    ) {}
}
