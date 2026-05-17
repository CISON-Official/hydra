<?php

namespace App\Services;

use App\Models\Certificate;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\Image\GdImageBackEnd;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Str;

class QRGeneratorService
{
    protected string $baseUrl;
    protected string $secretKey;

    public function __construct(string $baseUrl, string $secretKey)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->secretKey = $secretKey;
    }

    /**
     * Generate secure, signed QR code URL for a certificate.
     */
    public function generateQrUrl(string $certificateId, bool $forceRegenerate = false): string
    {
        $cert = Certificate::findOrFail($certificateId);

        // Generate a new secure nonce if forced or missing
        if ($forceRegenerate || empty($cert->qr_nonce)) {
            $cert->update([
                'qr_nonce' => $this->generateNonce()
            ]);
        }

        // Generate HMAC signature matching Python's cryptographic hash signature
        $signature = $this->generateSignature($certificateId, $cert->qr_nonce);

        // Build the tamper-proof verification URL link
        return "{$this->baseUrl}/verify/{$certificateId}?hmac={$signature}";
    }

    /**
     * Generate raw QR code PNG image stream bytes.
     */
    public function generateQrImage(string $url, int $scale = 8): string
    {
        // Multiply scale parameter to establish a square pixel layout size profile
        $size = $scale * 30;

        // Configure renderer using high error correction density ('H') matching python's setup
        $renderer = new ImageRenderer(
            new RendererStyle($size),
            new GdImageBackEnd()
        );

        $writer = new Writer($renderer);

        // BaconQrCode supports setting structural error correction via inner writer configs
        // Using writeString outputs raw binary string representations of the PNG image directly
        return $writer->writeString($url, 'UTF-8', \BaconQrCode\Common\ErrorCorrectionLevel::H);
    }

    /**
     * Regenerate QR code nonce parameters (instantly invalidates old printed/cached sheets).
     */
    public function regenerateQr(string $certificateId): string
    {
        $cert = Certificate::findOrFail($certificateId);

        // Explicitly update nonce first to shift validation keys
        $cert->update([
            'qr_nonce' => $this->generateNonce()
        ]);

        return $this->generateQrUrl($certificateId, forceRegenerate: true);
    }

    /**
     * Helper logic: Create a cryptographically secure random string snippet.
     */
    protected function generateNonce(): string
    {
        return Str::random(32);
    }

    /**
     * Helper logic: Calculate signature footprint.
     */
    protected function generateSignature(string $certificateId, string $nonce): string
    {
        // Concatenate parameters seamlessly to produce verification seeds
        $payload = "{$certificateId}:{$nonce}";

        return hash_hmac('sha256', $payload, $this->secretKey);
    }
}