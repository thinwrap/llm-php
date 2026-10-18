<?php

declare(strict_types=1);

namespace Thinwrap\Llm\Providers\Shared;

/**
 * The HTTP status an `error` object stands for when it arrives inside a 2xx
 * body or a mid-stream frame, where the real HTTP status says nothing about it.
 * Shared by chat and embeddings so both classify such errors the same way.
 *
 * @internal
 */
final class EmbeddedErrorStatus
{
    /** OpenAI-style error `type` / `code` names and the status each stands for. */
    private const NAMES = [
        'invalid_request_error' => 400,
        'context_length_exceeded' => 400,
        'authentication_error' => 401,
        'invalid_api_key' => 401,
        'permission_error' => 403,
        'not_found_error' => 404,
        'rate_limit_error' => 429,
        'rate_limit_exceeded' => 429,
        'insufficient_quota' => 429,
        'server_error' => 500,
        'api_error' => 500,
        'overloaded_error' => 529,
    ];

    /**
     * The error's `code` when it is an HTTP error status (a number or a numeric
     * string, as OpenRouter sends), else the status its `code` / `type` name
     * stands for; null when it carries neither.
     */
    public static function of(mixed $error): ?int
    {
        if (!is_array($error)) {
            return null;
        }
        $code = $error['code'] ?? null;
        if (is_string($code) && preg_match('/^\d{3}$/', $code) === 1) {
            $code = (int) $code;
        }
        if (is_int($code) && $code >= 400 && $code <= 599) {
            return $code;
        }
        foreach ([$error['code'] ?? null, $error['type'] ?? null] as $name) {
            if (is_string($name) && isset(self::NAMES[$name])) {
                return self::NAMES[$name];
            }
        }

        return null;
    }
}
