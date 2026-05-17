<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CertificateVersion extends Model
{
    use HasUuids;

    // We only track historical created_at times for immutable versions
    public $timestamps = ['created_at'];
    const UPDATED_AT = null;

    protected $fillable = [
        'certificate_id',
        'version_number',
        'file_hash',
        's3_key',
        'file_size_bytes',
        'updated_by',
        'update_reason'
    ];

    protected $casts = [
        'version_number' => 'integer',
        'file_size_bytes' => 'integer',
    ];
}