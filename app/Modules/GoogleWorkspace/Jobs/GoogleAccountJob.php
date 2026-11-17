<?php

namespace App\Modules\GoogleWorkspace\Jobs;

use App\Models\User;
use App\Modules\GoogleWorkspace\Enums\AccountOperation;
use App\Modules\GoogleWorkspace\Exceptions\WorkspaceException;
use App\Modules\GoogleWorkspace\Exceptions\WorkspaceRateLimitException;
use App\Modules\GoogleWorkspace\Exceptions\WorkspaceTransientException;
use App\Modules\GoogleWorkspace\Models\GoogleAccount;
use App\Modules\GoogleWorkspace\Services\GoogleAccountOperations;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Base for every job that changes one mailbox in Google.
 *
 * - Retries: up to 5 attempts, backoff 15s → 10min, 60s timeout.
 * - Rate limits: released back to the queue for the delay Google asked for.
 * - Transient errors (timeouts, 5xx): rethrown so the queue retries.
 * - Permanent errors: failed immediately, no pointless retries.
 * - Duplicates: unique per account + operation, and the operation itself
 *   is idempotent (GoogleAccountOperations).
 * - Final failure: recorded on the account, its history and the audit log,
 *   and the job stays in failed_jobs. Nothing is discarded silently.
 */
abstract class GoogleAccountJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    public int $timeout = 60;

    public int $uniqueFor = 900;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public int $accountId,
        public ?int $actorId = null,
        public array $payload = [],
    ) {
        $this->onQueue('google');
    }

    abstract public function operation(): ?AccountOperation;

    abstract protected function run(GoogleAccountOperations $operations, GoogleAccount $account, ?User $actor): void;

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [15, 60, 180, 600];
    }

    public function uniqueId(): string
    {
        // The payload is part of the key so two different edits to the same
        // account are both applied, while an exact duplicate is dropped.
        return static::class.':'.$this->accountId.':'.md5((string) json_encode($this->payload));
    }

    public function handle(GoogleAccountOperations $operations): void
    {
        $account = GoogleAccount::query()->find($this->accountId);

        if ($account === null) {
            return;
        }

        $actor = $this->actorId === null ? null : User::query()->find($this->actorId);

        try {
            $this->run($operations, $account, $actor);
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
        $operation = $this->operation();
        $account = GoogleAccount::query()->find($this->accountId);

        if ($operation !== null && $account !== null) {
            app(GoogleAccountOperations::class)->markFailed($account, $operation, $error ?? new WorkspaceException('Job failed.'));
        }
    }
}
