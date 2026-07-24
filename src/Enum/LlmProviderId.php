<?php

declare(strict_types=1);

namespace Thinwrap\Llm\Enum;

/**
 * Provider ids implemented so far — the 15 first-class OpenAI-compatible
 * providers (one shared connector) plus the native-adapter providers
 * (Anthropic, Bedrock, Gemini). Byte-identical to the TS `LLM_PROVIDER_IDS`.
 */
enum LlmProviderId: string
{
    // First-class OpenAI-compatible (15)
    case OpenAI = 'openai';
    case AzureOpenAI = 'azure-openai';
    case OpenRouter = 'openrouter';
    case Groq = 'groq';
    case Together = 'together';
    case Fireworks = 'fireworks';
    case DeepSeek = 'deepseek';
    case XAI = 'xai';
    case Mistral = 'mistral';
    case Perplexity = 'perplexity';
    case DeepInfra = 'deepinfra';
    case Cloudflare = 'cloudflare';
    case VLLM = 'vllm';
    case Ollama = 'ollama';
    case LMStudio = 'lmstudio';

    // Native adapters (3)
    case Anthropic = 'anthropic';
    case Bedrock = 'bedrock';
    case Gemini = 'gemini';
}
