<?php

declare(strict_types=1);

namespace Thinwrap\Llm\DTO\Chat;

/**
 * Normalized response-format directive. Build with the named constructors;
 * `jsonSchema` carries the `{name, schema}` pair (the normalized field name is
 * `jsonSchema`, mapped to the OpenAI wire `json_schema` by the connector).
 */
final readonly class ResponseFormat
{
    /**
     * @param array<string,mixed>|null $schema
     */
    private function __construct(
        public string $type,
        public ?string $name = null,
        public ?array $schema = null,
    ) {}

    public static function text(): self
    {
        return new self('text');
    }

    public static function jsonObject(): self
    {
        return new self('json_object');
    }

    /**
     * @param array<string,mixed> $schema
     */
    public static function jsonSchema(string $name, array $schema): self
    {
        return new self('json_schema', $name, $schema);
    }
}
