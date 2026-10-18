<?php

declare(strict_types=1);

namespace Thinwrap\Llm\Providers\Bedrock;

use Thinwrap\Llm\Util\RedactsSecrets;

/**
 * AWS Bedrock credentials. `headers` participate in the SigV4 signature.
 */
final readonly class BedrockConfig
{
    use RedactsSecrets;

    /**
     * @param array<string,string>|null $headers
     */
    public function __construct(
        public string $region,
        #[\SensitiveParameter]
        public string $accessKeyId,
        #[\SensitiveParameter]
        public string $secretAccessKey,
        #[\SensitiveParameter]
        public ?string $sessionToken = null,
        public ?string $baseUrl = null,
        public ?int $defaultMaxTokens = null,
        #[\SensitiveParameter]
        public ?array $headers = null,
    ) {}
}
