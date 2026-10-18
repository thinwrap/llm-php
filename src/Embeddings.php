<?php

declare(strict_types=1);

namespace Thinwrap\Llm;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Thinwrap\Llm\Contract\EmbeddingsConnectorInterface;
use Thinwrap\Llm\DTO\Embeddings\EmbeddingsInput;
use Thinwrap\Llm\DTO\Embeddings\EmbeddingsResult;
use Thinwrap\Llm\Enum\LlmProviderId;
use Thinwrap\Llm\Enum\ProviderCode;
use Thinwrap\Llm\Exception\ConnectorError;
use Thinwrap\Llm\Providers\Shared\OpenAiCompatConfig;
use Thinwrap\Llm\Providers\Shared\OpenAiCompatEmbeddingsConnector;
use Thinwrap\Llm\Providers\Shared\Specs;

/**
 * The embeddings facade — only the OpenAI-compatible providers that expose an
 * `/embeddings` surface (a deliberate subset of the chat provider list).
 */
final class Embeddings implements EmbeddingsConnectorInterface
{
    /** @var list<string> */
    public const SUPPORTED = [
        'openai', 'azure-openai', 'openrouter', 'together', 'fireworks',
        'mistral', 'deepinfra', 'cloudflare', 'vllm', 'ollama', 'lmstudio',
    ];

    private readonly EmbeddingsConnectorInterface $connector;

    public function __construct(
        public readonly ?LlmProviderId $providerId,
        #[\SensitiveParameter]
        ?OpenAiCompatConfig $config = null,
        ?ClientInterface $client = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        ?EmbeddingsConnectorInterface $connector = null,
    ) {
        if ($connector !== null) {
            $this->connector = $connector;

            return;
        }

        if ($providerId === null || $config === null) {
            throw new ConnectorError(
                providerCode: ProviderCode::InvalidRequest,
                providerMessage: 'Embeddings facade requires a provider id and config when no connector is injected',
            );
        }

        if (!in_array($providerId->value, self::SUPPORTED, true)) {
            throw new ConnectorError(
                providerCode: ProviderCode::InvalidRequest,
                providerMessage: "Provider '{$providerId->value}' has no OpenAI-compatible embeddings surface",
            );
        }

        $spec = Specs::get($providerId->value);
        if ($spec === null) {
            throw new ConnectorError(
                providerCode: ProviderCode::InvalidRequest,
                providerMessage: "Unsupported LLM provider: {$providerId->value}",
            );
        }

        $this->connector = new OpenAiCompatEmbeddingsConnector($spec, $config, $client, $requestFactory, $streamFactory);
    }

    public static function fromConnector(EmbeddingsConnectorInterface $connector): self
    {
        return new self(null, connector: $connector);
    }

    public function create(EmbeddingsInput $input): EmbeddingsResult
    {
        return $this->connector->create($input);
    }

    public function getId(): string
    {
        return $this->connector->getId();
    }
}
