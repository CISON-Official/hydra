<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Contracts\Filesystem\Filesystem;
use Aws\S3\Exception\S3Exception;
use Exception;

class S3StorageService
{
    protected Filesystem $disk;

    public function __class()
    {
        // Interacts explicitly with the 's3' disk configured in config/filesystems.php
        $this->disk = Storage::disk('s3');
    }

    /**
     * Upload file to S3 and return array matching [etag, file_size]
     */
    public function uploadFile(string|resource $fileData, string $key, string $contentType = 'application/pdf'): array
    {
        try {
            // Using put() with options lets you pass specific headers/metadata
            $options = [
                'ContentType' => $contentType,
                'Metadata' => [
                    'uploaded_at' => now()->toIso8601String()
                ]
            ];

            $this->disk->put($key, $fileData, $options);

            // Fetch metadata to match Python return specifications
            $size = $this->disk->size($key);
            
            // Get low-level ETag via driver metadata extraction
            $s3Client = $this->disk->getClient();
            $meta = $s3Client->headObject([
                'Bucket' => config('filesystems.disks.s3.bucket'),
                'Key' => $key
            ]);
            
            $etag = trim($meta['ETag'] ?? '', '"');

            return [$etag, $size];
        } catch (Exception $e) {
            logger()->error("S3 Upload Failed: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Retrieve file contents from S3 entirely into memory
     */
    public function getFile(string $key): string
    {
        if (!$this->disk->exists($key)) {
            throw new Exception("File not found at key: {$key}", 404);
        }

        return $this->disk->get($key);
    }

    /**
     * Delete file from S3
     */
    public function deleteFile(string $key): bool
    {
        return $this->disk->delete($key);
    }

    /**
     * Check if file exists
     */
    public function fileExists(string $key): bool
    {
        return $this->disk->exists($key);
    }

    /**
     * Stream file resource directly.
     * This replaces your AsyncGenerator and returns a low-level PHP stream resource pointer.
     * * @return resource|false
     */
    public function getFileStream(string $key)
    {
        try {
            return $this->disk->readStream($key);
        } catch (S3Exception $e) {
            logger()->error("S3 Streaming failed: " . $e->getMessage());
            throw $e;
        }
    }
}