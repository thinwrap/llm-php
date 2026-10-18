<?php

declare(strict_types=1);

namespace Thinwrap\Llm\Util;

/**
 * `var_dump()` / `print_r()` of a config — or of a connector or facade holding
 * one, as error trackers and debug logs do — would print every credential:
 * `#[\SensitiveParameter]` protects stack-trace arguments only. Every
 * constructor parameter marked `#[\SensitiveParameter]` is shown as
 * `[redacted]`, plus any property a class returns from
 * `redactedProperties()` (derived secrets). `var_export()` does not consult
 * `__debugInfo()`, so it still shows the values.
 *
 * @internal
 */
trait RedactsSecrets
{
    /**
     * @return array<string,mixed>
     */
    public function __debugInfo(): array
    {
        $out = get_object_vars($this);
        $redact = [];
        foreach ((new \ReflectionClass($this))->getConstructor()?->getParameters() ?? [] as $parameter) {
            if ($parameter->getAttributes(\SensitiveParameter::class) !== []) {
                $redact[] = $parameter->getName();
            }
        }
        foreach (array_merge($redact, $this->redactedProperties()) as $name) {
            if (array_key_exists($name, $out) && $out[$name] !== null) {
                $out[$name] = '[redacted]';
            }
        }

        return $out;
    }

    /**
     * Properties to redact beyond the `#[\SensitiveParameter]` constructor
     * parameters.
     *
     * @return list<string>
     */
    protected function redactedProperties(): array
    {
        return [];
    }
}
