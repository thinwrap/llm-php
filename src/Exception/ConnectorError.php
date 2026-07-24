<?php

declare(strict_types=1);

namespace Thinwrap\Llm\Exception;

use Thinwrap\Llm\Enum\ProviderCode;

/**
 * Unified error type for every connector failure — the identical shape across
 * all providers is part of the product.
 *
 * There is NO top-level structured `retryAfterSeconds` field: the wrapper never
 * retries. Retry hints ride inside {@see $cause}, whose canonical shape mirrors
 * the TS sibling:
 *
 *   ['raw' => mixed, 'retryAfter' => string|int|null, 'retryAfterSeconds' => int|null]
 *
 * The parsed seconds count is also woven into {@see $providerMessage}.
 */
final class ConnectorError extends \RuntimeException
{
    public function __construct(
        public readonly ProviderCode $providerCode,
        public readonly ?string $providerMessage = null,
        public readonly mixed $cause = null,
        public readonly ?int $statusCode = null,
    ) {
        parent::__construct($providerMessage ?? 'Connector error');
    }
}
