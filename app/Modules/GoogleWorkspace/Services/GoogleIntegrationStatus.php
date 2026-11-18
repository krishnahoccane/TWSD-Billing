<?php

namespace App\Modules\GoogleWorkspace\Services;

use App\Models\User;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\GoogleWorkspace\Contracts\WorkspaceProviderInterface;
use App\Modules\Integrations\Models\IntegrationSetting;
use Throwable;

/**
 * Read-only view of the Google integration configuration, plus the
 * connection test. Reports which settings are present — never their values.
 */
class GoogleIntegrationStatus
{
    public function __construct(
        private readonly WorkspaceProviderInterface $provider,
        private readonly AuditLogger $audit,
    ) {}

    /** "mock" until GOOGLE_WORKSPACE_ENABLED=true (and never live in tests). */
    public function mode(): string
    {
        return $this->provider->name() === 'google' ? 'live' : 'mock';
    }

    /**
     * @return list<array{label: string, ok: bool, detail: string}>
     */
    public function checks(): array
    {
        $config = config('wcc.google');
        $path = $config['service_account_json'];
        $key = filled($path) && is_file($path) ? json_decode((string) @file_get_contents($path), true) : null;

        return [
            ['label' => 'Integration enabled (GOOGLE_WORKSPACE_ENABLED)', 'ok' => (bool) $config['enabled'],
                'detail' => $config['enabled'] ? 'Enabled' : 'Disabled — the mock provider is in use'],
            ['label' => 'Cloud project id (GOOGLE_CLOUD_PROJECT_ID)', 'ok' => filled($config['project_id']),
                'detail' => filled($config['project_id']) ? 'Set' : 'Not set'],
            ['label' => 'Service-account key file (GOOGLE_SERVICE_ACCOUNT_JSON)', 'ok' => is_array($key) && ($key['type'] ?? null) === 'service_account',
                'detail' => match (true) {
                    blank($path) => 'Not set',
                    ! is_file($path) => 'File not found at '.$path,
                    ! is_array($key) || ($key['type'] ?? null) !== 'service_account' => 'Not a service-account key',
                    default => 'Valid key file found',
                }],
            ['label' => 'Key file is outside the web root', 'ok' => filled($path) && ! str_starts_with((string) realpath($path), (string) realpath(public_path())),
                'detail' => blank($path) ? 'Not set' : 'Checked'],
            ['label' => 'Customer id (GOOGLE_CUSTOMER_ID)', 'ok' => filled($config['customer_id']),
                'detail' => (string) $config['customer_id']],
            ['label' => 'Admin e-mail for delegation (GOOGLE_ADMIN_EMAIL)', 'ok' => ! $config['delegation_enabled'] || filled($config['admin_email']),
                'detail' => $config['delegation_enabled']
                    ? (filled($config['admin_email']) ? 'Set' : 'Required when delegation is enabled')
                    : 'Not needed — delegation is off'],
        ];
    }

    public function lastTest(): ?IntegrationSetting
    {
        return IntegrationSetting::query()->where('provider', 'google_workspace')->first();
    }

    /**
     * A harmless read (first page of one user) through whichever provider
     * is bound. Records the outcome.
     *
     * @return array{ok: bool, mode: string, message: string}
     */
    public function test(User $actor): array
    {
        try {
            $this->provider->listUsers(pageSize: 1);
            $ok = true;
            $message = $this->mode() === 'mock'
                ? 'The mock provider responded. This does not test Google.'
                : 'Google Workspace responded successfully.';
        } catch (Throwable $e) {
            $ok = false;
            $message = $e->getMessage();
        }

        IntegrationSetting::query()->updateOrCreate(
            ['provider' => 'google_workspace'],
            [
                'last_tested_at' => now(),
                'last_test_status' => $ok ? 'success' : 'failure',
                'last_test_message' => mb_substr($this->mode().': '.$message, 0, 500),
                'updated_by' => $actor->getKey(),
            ],
        );

        $this->audit->record(
            action: 'integration.google.tested',
            new: ['mode' => $this->mode(), 'result' => $ok ? 'success' : 'failure'],
            status: $ok ? AuditLogger::STATUS_SUCCESS : AuditLogger::STATUS_FAILURE,
            actor: $actor,
        );

        return ['ok' => $ok, 'mode' => $this->mode(), 'message' => $message];
    }
}
