<?php

declare(strict_types=1);

namespace Thinwrap\Llm;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Thinwrap\Llm\Contract\ChatConnectorInterface;
use Thinwrap\Llm\DTO\Chat\ChatInput;
use Thinwrap\Llm\DTO\Chat\ChatResult;
use Thinwrap\Llm\Enum\LlmProviderId;
use Thinwrap\Llm\Enum\ProviderCode;
use Thinwrap\Llm\Exception\ConnectorError;
use Thinwrap\Llm\Providers\Anthropic\AnthropicConfig;
use Thinwrap\Llm\Providers\Anthropic\AnthropicConnector;
use Thinwrap\Llm\Providers\Bedrock\BedrockConfig;
use Thinwrap\Llm\Providers\Bedrock\BedrockConnector;
use Thinwrap\Llm\Providers\Gemini\GeminiConfig;
use Thinwrap\Llm\Providers\Gemini\GeminiConnector;
use Thinwrap\Llm\Providers\Shared\OpenAiCompatConfig;
use Thinwrap\Llm\Providers\Shared\OpenAiCompatConnector;
use Thinwrap\Llm\Providers\Shared\Specs;

/**
 * The chat-completion facade. Construct by provider id + config — dispatch goes
 * to the shared OpenAI-compatible connector or to a native adapter. Switching
 * vendor is a change of provider id + `model` only.
 */
final class Chat implements ChatConnectorInterface
{
    private readonly ChatConnectorInterface $connector;

    public function __construct(
        public readonly ?LlmProviderId $providerId,
        #[\SensitiveParameter]
        OpenAiCompatConfig|AnthropicConfig|BedrockConfig|GeminiConfig|null $config = null,
        ?ClientInterface $client = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        ?ChatConnectorInterface $connector = null,
    ) {
        if ($connector !== null) {
            $this->connector = $connector;

            return;
        }

        if ($providerId === null || $config === null) {
            throw new ConnectorError(
                providerCode: ProviderCode::InvalidRequest,
                providerMessage: 'Chat facade requires a provider id and config when no connector is injected',
            );
        }

        $this->connector = match ($providerId) {
            LlmProviderId::Anthropic => $config instanceof AnthropicConfig
                ? new AnthropicConnector($config, $client, $requestFactory, $streamFactory)
                : throw self::mismatch($providerId, 'AnthropicConfig'),
            LlmProviderId::Bedrock => $config instanceof BedrockConfig
                ? new BedrockConnector($config, $client, $requestFactory, $streamFactory)
                : throw self::mismatch($providerId, 'BedrockConfig'),
            LlmProviderId::Gemini => $config instanceof GeminiConfig
                ? new GeminiConnector($config, $client, $requestFactory, $streamFactory)
                : throw self::mismatch($providerId, 'GeminiConfig'),
            default => $this->buildOpenAiCompat($providerId, $config, $client, $requestFactory, $streamFactory),
        };
    }

    public static function fromConnector(ChatConnectorInterface $connector): self
    {
        return new self(null, connector: $connector);
    }

    public function complete(ChatInput $input): ChatResult
    {
        return $this->connector->complete($input);
    }

    public function stream(ChatInput $input): \Generator
    {
        return $this->connector->stream($input);
    }

    public function getId(): string
    {
        return $this->connector->getId();
    }

    private function buildOpenAiCompat(
        LlmProviderId $providerId,
        #[\SensitiveParameter]
        OpenAiCompatConfig|AnthropicConfig|BedrockConfig|GeminiConfig $config,
        ?ClientInterface $client,
        ?RequestFactoryInterface $requestFactory,
        ?StreamFactoryInterface $streamFactory,
    ): ChatConnectorInterface {
        if (!$config instanceof OpenAiCompatConfig) {
            throw self::mismatch($providerId, 'OpenAiCompatConfig');
        }
        $spec = Specs::get($providerId->value);
        if ($spec === null) {
            throw new ConnectorError(
                providerCode: ProviderCode::InvalidRequest,
                providerMessage: "Unsupported LLM provider: {$providerId->value}",
            );
        }

        return new OpenAiCompatConnector($spec, $config, $client, $requestFactory, $streamFactory);
    }

    private static function mismatch(LlmProviderId $providerId, string $expected): ConnectorError
    {
        return new ConnectorError(
            providerCode: ProviderCode::InvalidRequest,
            providerMessage: "Provider {$providerId->value} requires {$expected}",
        );
    }
}
