<?php

declare(strict_types=1);

namespace Thinwrap\Llm\Enum;

/**
 * Canonical, normalized error categories surfaced on every {@see \Thinwrap\Llm\Exception\ConnectorError}.
 * Byte-identical to the TS `ProviderCode` union for the llm scope.
 */
enum ProviderCode: string
{
    case RateLimited = 'rate_limited';
    case AuthFailed = 'auth_failed';
    case ProviderUnavailable = 'provider_unavailable';
    case InvalidRequest = 'invalid_request';
    case ContextLengthExceeded = 'context_length_exceeded';
    case ContentFiltered = 'content_filtered';
    case Unknown = 'unknown';
}
