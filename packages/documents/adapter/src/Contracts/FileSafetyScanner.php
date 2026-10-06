<?php

declare(strict_types=1);

namespace Karband\DocumentsLaravel\Contracts;

/** Required host implementation: scan actual temporary bytes, throw on malware/error/unavailable scanner. */
interface FileSafetyScanner
{
    public function assertSafe(string $temporaryPath, string $detectedMimeType): void;
}
