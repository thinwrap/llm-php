<?php

declare(strict_types=1);

namespace Thinwrap\Llm\Util;

/**
 * Merges a connector-built `{body, headers, query}` bundle with the consumer's
 * `_passthrough`. Consumer values win on key collision (shallow, top-level —
 * parity with the TS `Object.assign(body, passthrough.body)`). Keys are
 * forwarded verbatim: no casing transformation.
 */
final class Passthrough
{
    /**
     * @param array{body?:array<string,mixed>,headers?:array<string,string>,query?:array<string,string>} $base
     * @param array<string,mixed>|null $passthrough
     *
     * @return array{body:array<string,mixed>,headers:array<string,string>,query:array<string,string>}
     */
    public static function merge(#[\SensitiveParameter] array $base, #[\SensitiveParameter] ?array $passthrough): array
    {
        $body = $base['body'] ?? [];
        $headers = $base['headers'] ?? [];
        $query = $base['query'] ?? [];

        if ($passthrough !== null) {
            if (isset($passthrough['body']) && is_array($passthrough['body'])) {
                $body = array_merge($body, $passthrough['body']);
            }
            if (isset($passthrough['headers']) && is_array($passthrough['headers'])) {
                $headers = array_merge($headers, $passthrough['headers']);
            }
            if (isset($passthrough['query']) && is_array($passthrough['query'])) {
                $query = array_merge($query, $passthrough['query']);
            }
        }

        return ['body' => $body, 'headers' => $headers, 'query' => $query];
    }
}
