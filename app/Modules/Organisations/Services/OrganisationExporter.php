<?php

namespace App\Modules\Organisations\Services;

use App\Models\User;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Organisations\Models\Organisation;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV export of the organisations the user may see. Uses the same
 * tenant-filtered query as the on-screen list.
 */
class OrganisationExporter
{
    private const COLUMNS = [
        'code' => 'Code',
        'name' => 'Name',
        'legal_name' => 'Legal name',
        'status' => 'Status',
        'gstin' => 'GSTIN',
        'pan' => 'PAN',
        'primary_contact_name' => 'Primary contact',
        'primary_contact_email' => 'Primary contact email',
        'contact_phone' => 'Phone',
        'billing_email' => 'Billing email',
        'city' => 'City',
        'state' => 'State',
        'country' => 'Country',
        'created_at' => 'Created',
    ];

    public function __construct(
        private readonly OrganisationQuery $query,
        private readonly AuditLogger $audit,
    ) {}

    public function download(User $user, ?string $search, ?string $status): StreamedResponse
    {
        $builder = $this->query->filtered($user, $search, $status);

        $this->audit->record('organisation.exported', new: ['search' => $search, 'status' => $status, 'rows' => (clone $builder)->count()], actor: $user);

        return response()->streamDownload(function () use ($builder) {
            $out = fopen('php://output', 'w');
            fputcsv($out, array_values(self::COLUMNS));

            $builder->reorder('id')->chunkById(200, function ($organisations) use ($out) {
                foreach ($organisations as $organisation) {
                    fputcsv($out, array_map(
                        fn (string $column) => self::cell($this->value($organisation, $column)),
                        array_keys(self::COLUMNS),
                    ));
                }
            });

            fclose($out);
        }, 'organisations-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function value(Organisation $organisation, string $column): string
    {
        return match ($column) {
            'status' => $organisation->status->value,
            'created_at' => (string) $organisation->created_at?->inDisplayTimezone()->toDateString(),
            default => (string) $organisation->getAttribute($column),
        };
    }

    /**
     * Neutralise spreadsheet formula injection: a cell that starts with
     * = + - @ (or tab / CR) is prefixed so it is treated as text.
     */
    public static function cell(string $value): string
    {
        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)
            ? "'".$value
            : $value;
    }
}
