<?php

declare(strict_types=1);

namespace Thinwrap\Llm\DTO\Chat;

/**
 * A base64-encoded image part. `base64` is the raw payload with NO `data:` prefix;
 * `mediaType` is e.g. `image/png`, `image/jpeg`.
 */
final readonly class ImagePart implements ContentPart
{
    public string $type;

    public function __construct(
        public string $base64,
        public string $mediaType,
    ) {
        $this->type = 'image';
    }
}
