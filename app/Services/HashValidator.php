<?php

namespace App\Services;

use InvalidArgumentException;
use RuntimeException;

class HashValidator
{
    protected string $algorithm;

    public function __construct(string $algorithm = 'sha256')
    {
        // Verify the system supports the selected algorithm
        if (!in_array(strtolower($algorithm), hash_algos(), true)) {
            throw new InvalidArgumentException("Unsupported hashing algorithm: {$algorithm}");
        }
        $this->algorithm = strtolower($algorithm);
    }

    /**
     * Compute hash from raw string/bytes
     */
    public function computeHash(string $data): string
    {
        return hash($this->algorithm, $data);
    }

    /**
     * Compute hash of a file efficiently by streaming it in chunks.
     * This replaces your async Python implementation cleanly without eating RAM.
     */
    public function computeHashForFile(string $filePath, int $chunkSize = 8192): string
    {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            throw new RuntimeException("File not found or unreadable: {$filePath}");
        }

        $context = hash_init($this->algorithm);
        $stream = fopen($filePath, 'rb');

        while (!feof($stream)) {
            $chunk = fread($stream, $chunkSize);
            hash_update($context, $chunk);
        }

        fclose($stream);

        return hash_final($context);
    }

    /**
     * Compute hash in chunks for a memory string
     */
    public function computeHashChunked(string $data, int $chunkSize = 8192): string
    {
        $context = hash_init($this->algorithm);
        $length = strlen($data);

        for ($i = 0; $i < $length; $i += $chunkSize) {
            $chunk = substr($data, $i, $chunkSize);
            hash_update($context, $chunk);
        }

        return hash_final($context);
    }

    /**
     * Validate raw string data integrity against expected hash
     */
    public function validateIntegrity(string $data, string $expectedHash): bool
    {
        return hash_equals($expectedHash, $this->computeHash($data));
    }

    /**
     * Validate file integrity using chunked streams
     */
    public function validateFileIntegrity(string $filePath, string $expectedHash): bool
    {
        return hash_equals($expectedHash, $this->computeHashForFile($filePath));
    }

    /**
     * Generate Merkle proof for a data chunk
     * * @return array{0: string, 1: array<string>} Returns [root_hash, proof_hashes]
     */
    public function generateMerkleProof(string $data, int $leafIndex, int $totalLeaves): array
    {
        $leaves = [];
        $dataLength = strlen($data);
        $chunkSize = $totalLeaves > 1 ? intdiv($dataLength, $totalLeaves) : $dataLength;

        for ($i = 0; $i < $totalLeaves; $i++) {
            $start = $i * $chunkSize;
            $end = ($i === $totalLeaves - 1) ? $dataLength : $start + $chunkSize;
            $chunk = substr($data, $start, $end - $start);
            $leaves[] = $this->computeHash($chunk);
        }

        $tree = [$leaves];
        while (count(end($tree)) > 1) {
            $level = [];
            $lastLevel = end($tree);
            $count = count($lastLevel);

            for ($i = 0; $i < $count; $i += 2) {
                if ($i + 1 < $count) {
                    $combined = $lastLevel[$i] . $lastLevel[$i + 1];
                } else {
                    $combined = $lastLevel[$i] . $lastLevel[$i];
                }
                $level[] = $this->computeHash($combined);
            }
            $tree[] = $level;
        }

        $rootHash = $tree[count($tree) - 1][0];
        $proof = [];
        $currentIndex = $leafIndex;
        $treeHeight = count($tree);

        for ($level = 0; $level < $treeHeight - 1; $level++) {
            $siblingIndex = ($currentIndex % 2 === 0) ? $currentIndex + 1 : $currentIndex - 1;
            if ($siblingIndex < count($tree[$level])) {
                $proof[] = $tree[$level][$siblingIndex];
            }
            $currentIndex = intdiv($currentIndex, 2);
        }

        return [$rootHash, $proof];
    }

    /**
     * Verify Merkle proof for a data chunk
     */
    public function verifyMerkleProof(string $data, int $leafIndex, array $proof, string $expectedRoot, int $totalLeaves): bool
    {
        $dataLength = strlen($data);
        $chunkSize = $totalLeaves > 1 ? intdiv($dataLength, $totalLeaves) : $dataLength;

        $start = $leafIndex * $chunkSize;
        $end = ($leafIndex === $totalLeaves - 1) ? $dataLength : $start + $chunkSize;
        $chunk = substr($data, $start, $end - $start);
        $currentHash = $this->computeHash($chunk);

        $currentIndex = $leafIndex;
        foreach ($proof as $siblingHash) {
            if ($currentIndex % 2 === 0) {
                $combined = $currentHash . $siblingHash;
            } else {
                $combined = $siblingHash . $currentHash;
            }
            $currentHash = $this->computeHash($combined);
            $currentIndex = intdiv($currentIndex, 2);
        }

        return hash_equals($expectedRoot, $currentHash);
    }
}