<?php

namespace App\Modules\Domains\Jobs;

use App\Models\User;
use App\Modules\Domains\Models\DomainRequest;
use App\Modules\Domains\Services\DomainRequestProcessor;
use App\Modules\GoogleWorkspace\Exceptions\WorkspaceException;
use App\Modules\GoogleWorkspace\Exceptions\WorkspaceRateLimitException;
use App\Modules\GoogleWorkspace\Exceptions\WorkspaceTransientException;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Base for the domain workflow jobs. Same guarantees as the mailbox jobs:
 * 5 attempts with backoff, 60 s timeout, rate limits released, transient
 * errors retried, permanent errors failed at once, one job per request and
 * step, and a final failure recorded on the request (never discarded).
 */
abstract class DomainRequestJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    public int $timeout = 60;

    public int $uniqueFor = 900;

    public function __construct(public int $requestId, public ?int $actorId = null)
    {
        $this->onQueue('google');
    }

    /** token | verify | activate | remove */
    abstract public function step(): string;

    abstract protected function run(DomainRequestProcessor $processor, DomainRequest $request, ?User $actor): void;

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [15, 60, 180, 600];
    }

    public function uniqueId(): string
    {
        return static::class.':'.$this->requestId;
    }

    public function handle(DomainRequestProcessor $processor): void
    {
        $request = DomainRequest::query()->find($this->requestId);

        if ($request === null) {
            return;
        }

        $actor = $this->actorId === null ? null : User::query()->find($this->actorId);

        try {
            $this->run($processor, $request, $actor);
        } catch (WorkspaceRateLimitException $e) {
            $this->release($e->retryAfter);
        } catch (WorkspaceTransientException $e) {
            throw $e;
        } catch (WorkspaceException $e) {
            $this->fail($e);
        }
    }

    public function failed(?Throwable $error): void
    {
        $request = DomainRequest::query()->find($this->requestId);

        if ($request !== null) {
            app(DomainRequestProcessor::class)->markFailed($request, $this->step(), $error ?? new WorkspaceException('Job failed.'));
        }
    }
}
