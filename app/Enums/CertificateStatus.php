<?php

namespace App\Enums;

enum CertificateStatus: string
{
    case Unknown = 'unknown';
    case Valid = 'valid';
    case Expiring = 'expiring';
    case Expired = 'expired';
    case Revoked = 'revoked';
    case Failed = 'failed';
    case None = 'none';
}
