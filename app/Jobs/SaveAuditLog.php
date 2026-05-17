<?php

namespace App\Jobs;

use App\Models\AuditLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Exception;

class SaveAuditLog implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected array $attributes;

    /**
     * Pass the structured log parameters into the background worker state context.
     */
    public function __construct(array $attributes)
    {
        $this->attributes = $attributes;
    }

    /**
     * The background worker executor logic thread.
     */
    public function handle(): void
    {
        try {
            AuditLog::create($this->attributes);
        } catch (Exception $e) {
            // Mitigate main threat vector: log worker error without interrupting system services
            Log::error("Failed to save background audit log: " . $e->getMessage(), [
                'attributes' => $this->attributes
            ]);
        }
    }
}