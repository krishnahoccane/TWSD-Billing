<?php

namespace App\Modules\System\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\AccessControl\Enums\Ability;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\GoogleWorkspace\Models\GoogleSyncLog;
use App\Modules\GoogleWorkspace\Services\GoogleIntegrationStatus;
use App\Modules\Zoho\Models\ZohoMapping;
use App\Modules\Zoho\Services\ZohoConnection;
use App\Modules\Zoho\Services\ZohoSync;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

/**
 * Queue monitoring (queues.monitor) and system health (system.health.view).
 */
class SystemController extends Controller
{
    public const QUEUES = ['google', 'zoho', 'billing', 'notifications', 'default'];

    public const HEARTBEAT_KEY = 'wcc:scheduler-heartbeat';

    public function queues(): View
    {
        Gate::authorize(Ability::QueuesMonitor->value);

        $sizes = [];

        foreach (self::QUEUES as $queue) {
            try {
                $sizes[$queue] = Queue::size($queue);
            } catch (Throwable) {
                $sizes[$queue] = null;
            }
        }

        $failed = DB::table('failed_jobs')->orderByDesc('id')->paginate(25);
        $failed->getCollection()->transform(function ($job) {
            $payload = json_decode((string) $job->payload, true) ?: [];

            return (object) [
                'uuid' => $job->uuid,
                'queue' => $job->queue,
                'job' => class_basename((string) ($payload['displayName'] ?? 'Unknown job')),
                // The first line of the exception only — never the payload, which can hold ids.
                'error' => Str::limit(strtok((string) $job->exception, "\n") ?: '', 300),
                'failed_at' => $job->failed_at,
            ];
        });

        return view('system.queues', [
            'driver' => config('queue.default'),
            'sizes' => $sizes,
            'failed' => $failed,
            'canRetry' => auth()->user()->hasAbility(Ability::QueuesMonitor),
        ]);
    }

    public function retry(Request $request, string $uuid, AuditLogger $audit): RedirectResponse
    {
        Gate::authorize(Ability::QueuesMonitor->value);
        abort_unless(DB::table('failed_jobs')->where('uuid', $uuid)->exists(), 404);

        Artisan::call('queue:retry', ['id' => [$uuid]]);
        $audit->record('queue.job_retried', new: ['job' => $uuid], actor: $request->user());

        return back()->with('success', 'The job was put back on its queue.');
    }

    public function forget(Request $request, string $uuid, AuditLogger $audit): RedirectResponse
    {
        Gate::authorize(Ability::QueuesMonitor->value);
        abort_unless(DB::table('failed_jobs')->where('uuid', $uuid)->exists(), 404);

        Artisan::call('queue:forget', ['id' => $uuid]);
        $audit->record('queue.job_discarded', new: ['job' => $uuid], actor: $request->user());

        return back()->with('success', 'The failed job was removed. Its record stays in the audit log.');
    }

    public function health(GoogleIntegrationStatus $google, ZohoSync $zoho, ZohoConnection $zohoConnection): View
    {
        Gate::authorize(Ability::SystemHealthView->value);

        $checks = [];

        // Database
        try {
            $started = hrtime(true);
            DB::select('select 1');
            $ms = (int) ((hrtime(true) - $started) / 1_000_000);
            $checks[] = ['Database', $ms < 500 ? 'ok' : 'warn', DB::getDriverName().' · '.$ms.' ms'];
        } catch (Throwable $e) {
            $checks[] = ['Database', 'fail', Str::limit($e->getMessage(), 120)];
        }

        // Cache
        try {
            Cache::put('wcc:health', 'ok', 30);
            $checks[] = ['Cache', Cache::get('wcc:health') === 'ok' ? 'ok' : 'fail', config('cache.default')];
        } catch (Throwable $e) {
            $checks[] = ['Cache', 'fail', Str::limit($e->getMessage(), 120)];
        }

        // Queue
        $failedJobs = DB::table('failed_jobs')->count();
        $driver = (string) config('queue.default');
        $checks[] = ['Queue driver', $driver === 'sync' ? 'warn' : 'ok', $driver === 'sync' ? 'sync — jobs run inside the request (development only)' : $driver];
        $checks[] = ['Failed jobs', $failedJobs === 0 ? 'ok' : 'warn', $failedJobs.' waiting for review'];

        if ($driver === 'database') {
            $oldest = DB::table('jobs')->min('available_at');
            $age = $oldest ? now()->timestamp - (int) $oldest : 0;
            $checks[] = ['Oldest queued job', $age > 900 ? 'warn' : 'ok', $oldest ? gmdate('H:i:s', $age).' old — is a worker running?' : 'Queue empty'];
        }

        // Scheduler
        $beat = Cache::get(self::HEARTBEAT_KEY);
        $checks[] = ['Scheduler', $beat && now()->diffInMinutes($beat) < 5 ? 'ok' : 'warn',
            $beat ? 'Last ran '.Carbon::parse($beat)->diffForHumans() : 'No heartbeat — is the cron entry for schedule:run installed?'];

        // Integrations
        $checks[] = ['Google Workspace', $google->mode() === 'live' ? 'ok' : 'warn', $google->mode() === 'live' ? 'Live' : 'Mock — not connected'];
        $lastSync = GoogleSyncLog::query()->latest('id')->first();
        $checks[] = ['Last Google sync', $lastSync?->status === 'failed' ? 'fail' : ($lastSync ? 'ok' : 'warn'),
            $lastSync ? str_replace('_', ' ', $lastSync->type).' '.$lastSync->status.' '.$lastSync->created_at->diffForHumans() : 'Never'];
        $checks[] = ['Zoho Books', $zoho->mode() === 'live' && $zohoConnection->isConnected() ? 'ok' : 'warn',
            $zoho->mode() === 'live' ? ($zohoConnection->isConnected() ? 'Live and connected' : 'Live but NOT connected') : 'Mock — not connected'];
        $zohoProblems = ZohoMapping::query()->whereIn('sync_status', [ZohoMapping::FAILED, ZohoMapping::MISMATCH, ZohoMapping::NEEDS_ATTENTION])->count();
        $checks[] = ['Zoho differences', $zohoProblems === 0 ? 'ok' : 'warn', $zohoProblems.' record(s) need attention'];
        $lastReconcile = AuditLog::query()->where('action', 'zoho.reconciled')->latest('id')->first();
        $checks[] = ['Last Zoho reconciliation', $lastReconcile ? 'ok' : 'warn', $lastReconcile?->created_at->diffForHumans() ?? 'Never'];

        // Configuration that must be right in production
        $production = app()->isProduction();
        $checks[] = ['Debug mode', config('app.debug') && $production ? 'fail' : 'ok', config('app.debug') ? 'On'.($production ? ' — must be off in production' : '') : 'Off'];
        $checks[] = ['Two-factor enforcement', config('wcc.security.enforce_two_factor') ? 'ok' : ($production ? 'fail' : 'warn'),
            config('wcc.security.enforce_two_factor') ? 'On' : 'Off — privileged users can sign in without 2FA'];
        $checks[] = ['Secure session cookie', config('session.secure') ? 'ok' : ($production ? 'fail' : 'warn'), config('session.secure') ? 'On' : 'Off'];
        $checks[] = ['Environment', 'ok', app()->environment().' · PHP '.PHP_VERSION.' · Laravel '.app()->version()];

        $free = @disk_free_space(storage_path());
        if ($free !== false) {
            $checks[] = ['Free disk space', $free > 1_000_000_000 ? 'ok' : 'warn', number_format($free / 1_073_741_824, 1).' GB'];
        }

        return view('system.health', ['checks' => $checks]);
    }
}
