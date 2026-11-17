<?php

namespace App\Modules\Zoho\Jobs;

use App\Modules\Zoho\Exceptions\ZohoException;
use App\Modules\Zoho\Exceptions\ZohoRateLimitException;
use App\Modules\Zoho\Exceptions\ZohoTransientException;
use App\Modules\Zoho\Services\ZohoSync;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Base for Zoho Books jobs: 5 attempts with backoff, 60 s timeout, rate
 * limits released for the delay Zoho asks for, transient errors retried,
 * permanent errors failed at once, one job per record. A final failure is
 * recorded on the record's Zoho mapping and kept in failed_jobs.
 */
abstract class ZohoJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    public int $timeout = 60;

    public int $uniqueFor = 900;

    public function __construct(public int $recordId)
    {
        $this->onQueue('zoho');
    }

    abstract protected function run(ZohoSync $sync): void;

    /** Record the final failure against the local record. */
    abstract protected function recordFailure(ZohoSync $sync, Throwable $error): void;

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120, 300, 900];
    }

    public function uniqueId(): string
    {
        return static::class.':'.$this->recordId;
    }

    public function handle(ZohoSync $sync): void
    {
        try {
            $this->run($sync);
        } catch (ZohoRateLimitException $e) {
            $this->release($e->retryAfter);
        } catch (ZohoTransientException $e) {
            throw $e;
        } catch (ZohoException $e) {
            $this->recordFailure($sync, $e);
            $this->fail($e);
        }
    }

    public function failed(?Throwable $error): void
    {
        $this->recordFailure(app(ZohoSync::class), $error ?? new ZohoException('Job failed.'));
    }
}
