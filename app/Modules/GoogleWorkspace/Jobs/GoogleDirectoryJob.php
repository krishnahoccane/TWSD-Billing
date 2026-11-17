<?php

namespace App\Modules\GoogleWorkspace\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Base for directory-wide synchronisation jobs. One run of each type at a
 * time; retried with backoff; a failed run is recorded in google_sync_logs
 * by the importer and stays in failed_jobs.
 */
abstract class GoogleDirectoryJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $timeout = 600;

    public int $uniqueFor = 900;

    public function __construct(public ?int $triggeredBy = null)
    {
        $this->onQueue('google');
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function uniqueId(): string
    {
        return static::class;
    }
}
