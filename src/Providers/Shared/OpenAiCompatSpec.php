<?php

declare(strict_types=1);

namespace Thinwrap\Llm\Providers\Shared;

/**
 * One row of the {@see Specs} registry — everything the shared connector needs
 * to talk to a given OpenAI-compatible provider. Adding a provider is a row here,
 * not a new class.
 */
final readonly class OpenAiCompatSpec
{
    public function __construct(
        public string $id,
        /** No trailing slash; null ⇒ the consumer must supply `baseUrl` in config. */
        public ?string $defaultBaseUrl,
        /** Auth header name — 'Authorization' (bearer) for all but azure-openai ('api-key'). */
        public string $authHeaderName = 'Authorization',
        /** Prefix before the key — 'Bearer ' for bearer auth, '' for api-key. */
        public string $authHeaderPrefix = 'Bearer ',
        /** false ⇒ provider has no tool support (Perplexity Sonar). */
        public bool $supportsTools = true,
        /** Vendor field for the max-output-tokens cap. */
        public string $maxTokensField = 'max_tokens',
        /** 'x_groq' ⇒ streaming usage lives under `x_groq.usage` (Groq). */
        public ?string $streamUsagePath = null,
        /** true ⇒ provider rejects unknown params (Mistral 422) — skip stream_options. */
        public bool $strictParams = false,
    ) {}

    /**
     * @return array<string,string>
     */
    public function buildAuthHeaders(OpenAiCompatConfig $config): array
    {
        if ($config->apiKey === null || $config->apiKey === '') {
            return [];
        }

        return [$this->authHeaderName => $this->authHeaderPrefix . $config->apiKey];
    }
}
