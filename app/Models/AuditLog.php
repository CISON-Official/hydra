<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    use HasUuids;

    // Disable standard updated_at column since audit logs are append-only logs
    public $timestamps = ["created_at"];
    const UPDATED_AT = null;

    protected $fillable = [
        'action',
        'actor_role',
        'actor_id',
        'certificate_id',
        'ip_address',
        'user_agent',
        'success',
        'details',
    ];

    protected $casts = [
        'success' => 'boolean',
        'details' => 'array', // Automatically handles json_encode/json_decode
    ];
}