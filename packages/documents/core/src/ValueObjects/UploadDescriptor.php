<?php

declare(strict_types=1);

namespace Karband\Documents\ValueObjects;

final readonly class UploadDescriptor
{
    public function __construct(public string $mimeType, public int $bytes, public string $sha256)
    {
        if ($bytes < 1 || !preg_match('/^[a-f0-9]{64}$/D', $sha256)) {
            throw new \InvalidArgumentException('Invalid upload size or checksum.');
        }
    }
}
