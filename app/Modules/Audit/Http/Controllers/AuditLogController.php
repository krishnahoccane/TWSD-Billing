<?php

namespace App\Modules\Audit\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\AccessControl\Enums\Ability;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Organisations\Models\Organisation;
use App\Modules\Organisations\Services\OrganisationExporter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The full audit log for audit.view (Super Admins). Operations Admins with
 * audit.view_technical see only the technical actions.
 */
class AuditLogController extends Controller
{
    /** Action prefixes counted as "technical". */
    private const TECHNICAL = ['account.', 'domain.', 'workspace.', 'integration.', 'zoho.', 'queue.'];

    public function index(Request $request): View
    {
        $query = $this->query($request);

        return view('audit.index', [
            'entries' => $query->with('organisation:id,uuid,name')->latest('id')->paginate(50)->withQueryString(),
            'filters' => $request->only(['action', 'user', 'organisation', 'status', 'from', 'to']),
            'organisations' => Organisation::query()->orderBy('name')->pluck('name', 'uuid')->all(),
            'technicalOnly' => ! $request->user()->hasAbility(Ability::AuditView),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $query = $this->query($request)->with('organisation:id,name')->latest('id');

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['When (UTC)', 'Action', 'Status', 'User', 'Role', 'Organisation', 'Resource', 'Resource id', 'IP', 'Request id']);

            $query->limit(50000)->chunk(1000, function ($entries) use ($out) {
                foreach ($entries as $entry) {
                    fputcsv($out, array_map(fn ($value) => OrganisationExporter::cell((string) $value), [
                        $entry->created_at?->inDisplayTimezone()->toDateTimeString(), $entry->action, $entry->status, $entry->user_email, $entry->user_role,
                        $entry->organisation?->name, $entry->resource_type, $entry->resource_id, $entry->ip_address, $entry->request_id,
                    ]));
                }
            });

            fclose($out);
        }, 'audit-log-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function query(Request $request): Builder
    {
        $user = $request->user();
        $full = $user->hasAbility(Ability::AuditView);
        abort_unless($full || $user->hasAbility(Ability::AuditViewTechnical), 403);

        $filters = $request->validate([
            'action' => ['nullable', 'string', 'max:96'],
            'user' => ['nullable', 'string', 'max:255'],
            'organisation' => ['nullable', 'uuid'],
            'status' => ['nullable', 'in:success,failure,denied'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        return AuditLog::query()
            ->when(! $full, fn (Builder $query) => $query->where(function (Builder $inner) {
                foreach (self::TECHNICAL as $prefix) {
                    $inner->orWhere('action', 'like', $prefix.'%');
                }
            }))
            ->when($filters['action'] ?? null, fn (Builder $query, $action) => $query->where('action', 'like', '%'.addcslashes($action, '%_\\').'%'))
            ->when($filters['user'] ?? null, fn (Builder $query, $email) => $query->where('user_email', 'like', '%'.addcslashes($email, '%_\\').'%'))
            ->when($filters['organisation'] ?? null, fn (Builder $query, $uuid) => $query->whereIn('organisation_id', Organisation::query()->where('uuid', $uuid)->select('id')))
            ->when($filters['status'] ?? null, fn (Builder $query, $status) => $query->where('status', $status))
            ->when($filters['from'] ?? null, fn (Builder $query, $from) => $query->whereDate('created_at', '>=', $from))
            ->when($filters['to'] ?? null, fn (Builder $query, $to) => $query->whereDate('created_at', '<=', $to));
    }
}
