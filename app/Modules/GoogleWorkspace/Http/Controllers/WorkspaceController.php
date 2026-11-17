<?php

namespace App\Modules\GoogleWorkspace\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\AccessControl\Enums\Ability;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\GoogleWorkspace\Enums\AccountStatus;
use App\Modules\GoogleWorkspace\Jobs\ImportGoogleDomainsJob;
use App\Modules\GoogleWorkspace\Jobs\ImportGoogleUsersJob;
use App\Modules\GoogleWorkspace\Jobs\ReconcileGoogleAccountsJob;
use App\Modules\GoogleWorkspace\Jobs\SyncGoogleAccountsJob;
use App\Modules\GoogleWorkspace\Models\GoogleAccount;
use App\Modules\GoogleWorkspace\Models\GoogleApiLog;
use App\Modules\GoogleWorkspace\Models\GoogleSyncLog;
use App\Modules\GoogleWorkspace\Services\GoogleIntegrationStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class WorkspaceController extends Controller
{
    private const JOBS = [
        'users_full' => [ImportGoogleUsersJob::class, 'Full user import'],
        'users_sync' => [SyncGoogleAccountsJob::class, 'User synchronisation'],
        'domains' => [ImportGoogleDomainsJob::class, 'Domain synchronisation'],
        'reconcile' => [ReconcileGoogleAccountsJob::class, 'Retry stuck operations'],
    ];

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
            'jobs' => collect(self::JOBS)->map(fn (array $job) => $job[1])->all(),
            'mode' => $status->mode(),
            'stuck' => GoogleAccount::query()->whereNotNull('pending_operation')
                ->where('pending_since', '<', now()->subMinutes(ReconcileGoogleAccountsJob::STUCK_AFTER_MINUTES))->count(),
            'failed' => GoogleAccount::query()->whereIn('account_status', [AccountStatus::CreationFailed, AccountStatus::SyncFailed])->count(),
            'failedJobs' => DB::table('failed_jobs')->where('queue', 'google')->count(),
            'recentErrors' => GoogleApiLog::query()->where('status', 'failure')->where('created_at', '>', now()->subDay())->count(),
        ]);
    }

    public function run(Request $request, AuditLogger $audit): RedirectResponse
    {
        Gate::authorize(Ability::AccountsSync->value);

        $data = $request->validate(['type' => ['required', Rule::in(array_keys(self::JOBS))]]);
        [$job, $label] = self::JOBS[$data['type']];

        $job::dispatch($request->user()->getKey());

        $audit->record('workspace.sync_requested', new: ['type' => $data['type']], actor: $request->user());

        return back()->with('success', "{$label} queued. It runs on the queue worker; refresh this page to see the result.");
    }
}
