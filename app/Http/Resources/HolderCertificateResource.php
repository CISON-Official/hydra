<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class HolderCertificateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'certificate_title' => $this->certificate_title,
            'issuer_id' => $this->issuer_id,
            'issued_at' => $this->created_at->toIso8601String(),
            'expires_at' => $this->expires_at->toIso8601String(),
            'is_valid' => is_null($this->revoked_at) && $this->expires_at->isAfter(now()),
            'qr_code_available' => !is_null($this->qr_nonce),
        ];
    }
}