<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One row per issuance attempt, so the LE duplicate limit can be pre-empted. */
class CertificateIssuance extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'succeeded' => 'boolean',
            'attempted_at' => 'datetime',
        ];
    }
}
