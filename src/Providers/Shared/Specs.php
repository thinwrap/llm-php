<?php

declare(strict_types=1);

namespace Thinwrap\Llm\Providers\Shared;

/**
 * The registry of the 15 first-class OpenAI-compatible providers. Byte-identical
 * to the TS `SPECS` object.
 */
final class Specs
{
    /** @var array<string,OpenAiCompatSpec>|null */
    private static ?array $specs = null;

    /**
     * @return array<string,OpenAiCompatSpec>
     */
    public static function all(): array
    {
        if (self::$specs !== null) {
            return self::$specs;
        }

        $s = [];
        $s['openai'] = new OpenAiCompatSpec(id: 'openai', defaultBaseUrl: 'https://api.openai.com/v1');
        $s['azure-openai'] = new OpenAiCompatSpec(id: 'azure-openai', defaultBaseUrl: null, authHeaderName: 'api-key', authHeaderPrefix: '');
        $s['openrouter'] = new OpenAiCompatSpec(id: 'openrouter', defaultBaseUrl: 'https://openrouter.ai/api/v1');
        $s['groq'] = new OpenAiCompatSpec(id: 'groq', defaultBaseUrl: 'https://api.groq.com/openai/v1', streamUsagePath: 'x_groq');
        $s['together'] = new OpenAiCompatSpec(id: 'together', defaultBaseUrl: 'https://api.together.ai/v1');
        $s['fireworks'] = new OpenAiCompatSpec(id: 'fireworks', defaultBaseUrl: 'https://api.fireworks.ai/inference/v1');
        $s['deepseek'] = new OpenAiCompatSpec(id: 'deepseek', defaultBaseUrl: 'https://api.deepseek.com/v1');
        $s['xai'] = new OpenAiCompatSpec(id: 'xai', defaultBaseUrl: 'https://api.x.ai/v1');
        $s['mistral'] = new OpenAiCompatSpec(id: 'mistral', defaultBaseUrl: 'https://api.mistral.ai/v1', strictParams: true);
        $s['perplexity'] = new OpenAiCompatSpec(id: 'perplexity', defaultBaseUrl: 'https://api.perplexity.ai', supportsTools: false);
        $s['deepinfra'] = new OpenAiCompatSpec(id: 'deepinfra', defaultBaseUrl: 'https://api.deepinfra.com/v1/openai');
        $s['cloudflare'] = new OpenAiCompatSpec(id: 'cloudflare', defaultBaseUrl: null);
        $s['vllm'] = new OpenAiCompatSpec(id: 'vllm', defaultBaseUrl: 'http://localhost:8000/v1');
        $s['ollama'] = new OpenAiCompatSpec(id: 'ollama', defaultBaseUrl: 'http://localhost:11434/v1');
        $s['lmstudio'] = new OpenAiCompatSpec(id: 'lmstudio', defaultBaseUrl: 'http://localhost:1234/v1');

        return self::$specs = $s;
    }

    public static function get(string $id): ?OpenAiCompatSpec
    {
        return self::all()[$id] ?? null;
    }
}
