<?php

declare(strict_types=1);

namespace Thinwrap\Llm\DTO\Chat;

use Thinwrap\Llm\Enum\ReasoningEffort;

/**
 * The one deliberate sub-90% carve-out on the normalized surface: reasoning
 * effort. Chain-of-thought output itself is never normalized — read it from `raw`.
 */
final readonly class ReasoningOptions
{
    public function __construct(public ReasoningEffort $effort) {}
}
