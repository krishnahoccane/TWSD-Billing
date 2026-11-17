<?php

namespace App\Modules\GoogleWorkspace\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\AccessControl\Enums\Ability;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\GoogleWorkspace\Enums\AccountStatus;
use App\Modules\GoogleWorkspace\Jobs\ReconcileGoogleAccountsJob;
use App\Modules\GoogleWorkspace\Models\GoogleAccount;
use App\Modules\GoogleWorkspace\Models\GoogleApiLog;
use App\Modules\GoogleWorkspace\Models\GoogleSyncLog;
use App\Modules\GoogleWorkspace\Services\DirectorySyncRunner;
use App\Modules\GoogleWorkspace\Services\GoogleDirectoryImporter;
use App\Modules\GoogleWorkspace\Services\GoogleIntegrationStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

class WorkspaceController extends Controller
{
    public function overview(Request $request, GoogleIntegrationStatus $status): View
    {
        Gate::authorize('viewAny', GoogleAccount::class);

        $user = $request->user();

        $counts = GoogleAccount::query()->visibleTo($user, Ability::AccountsView)
            ->select('account_status', DB::raw('count(*) as total'))
            ->groupBy('account_status')
            ->pluck('total', 'account_status');

        return view('workspace.overview', [
            'total' => $counts->sum(),
            'counts' => $counts,
            'statuses' => AccountStatus::cases(),
            'unassigned' => $user->hasGlobalAccess() ? GoogleAccount::query()->whereNull('organisation_id')->count() : null,
            'missing' => GoogleAccount::query()->visibleTo($user, Ability::AccountsView)->whereNotNull('missing_from_google_at')->count(),
            'mode' => $status->mode(),
            'lastSync' => $user->hasAbility(Ability::AccountsSync)
                ? GoogleSyncLog::query()->where('status', GoogleSyncLog::COMPLETED)->latest('id')->first()
                : null,
        ]);
    }

    public function sync(GoogleIntegrationStatus $status): View
    {
        Gate::authorize(Ability::AccountsSync->value);

        return view('workspace.sync', [
            'logs' => GoogleSyncLog::query()->with('triggeredBy:id,name')->latest('id')->paginate(15),
            'jobs' => collect(DirectorySyncRunner::TYPES)->map(fn (array $job) => $job[1])->all(),
            'mode' => $status->mode(),
            'stuck' => GoogleAccount::query()->whereNotNull('pending_operation')
                ->where('pending_since', '<', now()->subMinutes(ReconcileGoogleAccountsJob::STUCK_AFTER_MINUTES))->count(),
            'failed' => GoogleAccount::query()->whereIn('account_status', [AccountStatus::CreationFailed, AccountStatus::SyncFailed])->count(),
            'failedJobs' => DB::table('failed_jobs')->where('queue', 'google')->count(),
            'recentErrors' => GoogleApiLog::query()->where('status', 'failure')->where('created_at', '>', now()->subDay())->count(),
        ]);
    }

    /** Runs the synchronisation now, in this request (no queue worker involved). */
    public function run(Request $request, AuditLogger $audit, DirectorySyncRunner $runner): RedirectResponse
    {
        Gate::authorize(Ability::AccountsSync->value);

        $data = $request->validate(['type' => ['required', Rule::in(array_keys(DirectorySyncRunner::TYPES))]]);
        $type = $data['type'];
        $label = DirectorySyncRunner::label($type);

        $audit->record('workspace.sync_requested', new: ['type' => $type], actor: $request->user());

        $before = GoogleSyncLog::query()->max('id') ?? 0;

        try {
            $runner->run($type, $request->user());
        } catch (Throwable $e) {
            // The importer has already recorded the failure in the sync log.
            report($e);

            return back()->with('error', "{$label} failed: ".Str::limit($e->getMessage(), 300));
        }

        if ($type === 'reconcile') {
            return back()->with('success', 'Stuck operations were re-queued. They are applied by the queue worker.');
        }

        $log = GoogleSyncLog::query()->where('id', '>', $before)->where('type', $this->logType($type))->latest('id')->first();

        $summary = $log === null ? '' : match ($type) {
            'domains' => " {$log->fetched} found in Google, {$log->created} new, {$log->unassigned} not assigned to an organisation.",
            default => " {$log->fetched} fetched, {$log->created} new, {$log->updated} updated, {$log->unassigned} unassigned"
                .($log->errors > 0 ? ", {$log->errors} errors" : '').'.',
        };

        return back()->with($log?->errors > 0 ? 'warning' : 'success', "{$label} finished.".$summary);
    }

    private function logType(string $type): string
    {
        return match ($type) {
            'users_full' => GoogleDirectoryImporter::TYPE_FULL,
            'users_sync' => GoogleDirectoryImporter::TYPE_SYNC,
            default => GoogleDirectoryImporter::TYPE_DOMAINS,
        };
    }
}
