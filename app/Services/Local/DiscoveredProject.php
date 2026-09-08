<?php

namespace App\Services\Local;

/** One project the scanner found, before anything has been probed or stored. */
final class DiscoveredProject
{
    public function __construct(
        public readonly string $path,
        public readonly string $root,
        public readonly int $depth,
        public readonly ProjectSignature $signature,
        public readonly ?int $modifiedAt = null,
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'root' => $this->root,
            'depth' => $this->depth,
            'modified_at' => $this->modifiedAt,
        ] + $this->signature->toArray();
    }
}
