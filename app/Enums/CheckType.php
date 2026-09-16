<?php

namespace App\Enums;

enum CheckType: string
{
    case Dns = 'dns';
    case Nameserver = 'nameserver';
    case Whois = 'whois';
    case ExternalHttp = 'external_http';
    case Tls = 'tls';

    public function label(): string
    {
        return match ($this) {
            self::Dns => 'DNS resolution',
            self::Nameserver => 'Nameserver integrity',
            self::Whois => 'Registrar status',
            self::ExternalHttp => 'External reachability',
            self::Tls => 'TLS certificate',
        };
    }
}
