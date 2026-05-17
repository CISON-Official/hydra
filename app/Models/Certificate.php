<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Certificate extends Model
{
    use HasUuids;

    protected $fillable = [
        'current_version',
        'file_hash',
        's3_key',
        'file_size_bytes',
        'expires_at',
        'revoked_at',
        'updated_at',
        'last_updated_by',
        'update_reason'
    ];

    protected $casts = [
        'current_version' => 'integer',
        'file_size_bytes' => 'integer',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function versions(): HasMany
    {
        return $this->hasMany(CertificateVersion::class, 'certificate_id');
    }
}