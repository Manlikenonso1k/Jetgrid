<?php

namespace App\Services\Reachability;

final class DnsAnswer
{
    /** @param list<string> $records */
    public function __construct(
        public readonly string $resolver,
        public readonly int $rcode,
        public readonly array $records = [],
        public readonly ?string $error = null,
    ) {}

    public const RCODES = [
        0 => 'NOERROR',
        1 => 'FORMERR',
        2 => 'SERVFAIL',
        3 => 'NXDOMAIN',
        4 => 'NOTIMP',
        5 => 'REFUSED',
    ];

    public static function failed(string $resolver, string $error): self
    {
        return new self($resolver, -1, [], $error);
    }

    public function rcodeName(): string
    {
        return self::RCODES[$this->rcode] ?? ($this->rcode === -1 ? 'LOOKUP_FAILED' : "RCODE{$this->rcode}");
    }

    /** The query reached the resolver and it answered authoritatively-well. */
    public function answered(): bool
    {
        return $this->rcode === 0;
    }

    public function isEmpty(): bool
    {
        return $this->records === [];
    }
}
