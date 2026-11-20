<?php

namespace App\Modules\Licensing\Services;

use App\Models\User;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Licensing\Exceptions\LicensingException;
use App\Modules\Licensing\Models\SoftwareActivation;
use App\Modules\Licensing\Models\SoftwareLicense;
use App\Modules\Licensing\Support\DomainName;
use App\Modules\Licensing\Support\LicenseToken;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Domains / installations of a license, from the portal (pre-approve, move,
 * deactivate, request a check-in) and from the license server (activate,
 * check-in, deactivate).
 *
 * Rules: production activations ≤ included + extra domains; staging is
 * free; a domain is active on one license at a time; at most 3 moves per
 * license in 30 days.
 */
class ActivationService
{
    public const MAX_MOVES = 3;

    public const TOKEN_DAYS = 30;

    public function __construct(
        private readonly LicenseService $licenses,
        private readonly AuditLogger $audit,
        private readonly StorefrontWebhooks $webhooks,
        private readonly LicenseToken $tokens,
    ) {}

    /** Pre-approve a domain from the portal: the install then activates against it. */
    public function add(SoftwareLicense $license, string $domain, ?string $environment, ?User $actor, string $actorLabel): SoftwareActivation
    {
        $activation = $this->locked($license, function () use ($license, $domain, $environment, $actor, $actorLabel) {
            [$domain, $environment] = $this->checkNew($license, $domain, $environment);

            $activation = SoftwareActivation::query()->create([
                'software_license_id' => $license->getKey(),
                'domain' => $domain,
                'environment' => $environment,
                'status' => SoftwareActivation::PENDING,
            ]);

            $this->record($license, 'domain_added', ['domain' => $domain, 'environment' => $environment], $actor, $actorLabel);

            return $activation;
        });

        $this->webhooks->activationEvent($activation, 'activation.created');

        return $activation;
    }

    public function deactivate(SoftwareActivation $activation, ?User $actor, string $actorLabel): SoftwareActivation
    {
        if ($activation->status === SoftwareActivation::DEACTIVATED) {
            return $activation;
        }

        $activation->forceFill(['status' => SoftwareActivation::DEACTIVATED, 'deactivated_at' => now(), 'check_in_requested' => false])->save();
        $this->record($activation->license, 'domain_deactivated', ['domain' => $activation->domain], $actor, $actorLabel);
        $this->webhooks->activationEvent($activation, 'activation.deactivated');

        return $activation;
    }

    /** Move a production domain to another one (anti-sharing limit: 3 per 30 days). */
    public function move(SoftwareActivation $activation, string $newDomain, ?User $actor, string $actorLabel): SoftwareActivation
    {
        $license = $activation->license;

        return $this->locked($license, function () use ($activation, $license, $newDomain, $actor, $actorLabel) {
            if ($activation->status === SoftwareActivation::DEACTIVATED) {
                throw new LicensingException('activation_deactivated', 'That domain is no longer active on this license.');
            }

            $moves = $license->events()->where('event', 'domain_moved')->where('created_at', '>=', now()->subDays(30))->count();

            if ($moves >= self::MAX_MOVES) {
                throw new LicensingException('move_limit_reached', 'A license can be moved to another domain at most '.self::MAX_MOVES.' times in 30 days.', 429);
            }

            $normalised = DomainName::normalise($newDomain) ?? throw new LicensingException('invalid_domain', 'Enter a valid domain name.', 422, 'new_domain');
            $this->assertDomainFree($normalised, $license);

            $old = $activation->domain;
            $activation->forceFill(['status' => SoftwareActivation::DEACTIVATED, 'deactivated_at' => now(), 'check_in_requested' => false])->save();

            $new = SoftwareActivation::query()->create([
                'software_license_id' => $license->getKey(),
                'domain' => $normalised,
                'environment' => $activation->environment,
                'status' => SoftwareActivation::PENDING,
            ]);

            $this->record($license, 'domain_moved', ['from' => $old, 'to' => $normalised], $actor, $actorLabel);
            $this->webhooks->activationEvent($activation, 'activation.deactivated');
            $this->webhooks->activationEvent($new, 'activation.created');

            return $new;
        });
    }

    public function requestCheckIn(SoftwareActivation $activation, ?User $actor, string $actorLabel): void
    {
        $activation->forceFill(['check_in_requested' => true])->save();
        $this->record($activation->license, 'check_in_requested', ['domain' => $activation->domain], $actor, $actorLabel);
    }

    // ---- License server ----------------------------------------------------

    /**
     * @param  array{domain: string, environment?: ?string, version?: ?string, php_version?: ?string, instance_id?: ?string, ip?: ?string}  $install
     * @return array{token: string, activation: SoftwareActivation}
     */
    public function activateInstall(string $key, array $install): array
    {
        $license = $this->licenses->findByKey($key) ?? throw new LicensingException('invalid_key', 'License key not recognised.', 404);

        if (! $license->allowsUse()) {
            throw new LicensingException('license_'.$license->displayStatus(), 'This license is '.$license->displayStatus().'.', 403);
        }

        $activation = $this->locked($license, function () use ($license, $install) {
            $domain = DomainName::normalise($install['domain']) ?? throw new LicensingException('invalid_domain', 'Invalid domain.', 422, 'domain');

            // Pre-approved (pending) or already active on this license: take it over.
            $activation = $license->activations()->where('domain', $domain)->whereIn('status', [SoftwareActivation::PENDING, SoftwareActivation::ACTIVE])->first();

            if ($activation === null) {
                [$domain, $environment] = $this->checkNew($license, $domain, $install['environment'] ?? null);
                $activation = new SoftwareActivation(['software_license_id' => $license->getKey(), 'domain' => $domain, 'environment' => $environment]);
            }

            $created = ! $activation->exists || $activation->status !== SoftwareActivation::ACTIVE;
            $activation->forceFill([
                'status' => SoftwareActivation::ACTIVE,
                'activated_at' => $activation->activated_at ?? now(),
                'instance_id' => $install['instance_id'] ?? $activation->instance_id,
                'server_ip' => $install['ip'] ?? null,
                'version' => $install['version'] ?? null,
                'php_version' => $install['php_version'] ?? null,
                'last_check_in_at' => now(),
                'check_in_requested' => false,
            ])->save();

            if ($created) {
                $this->licenses->event($license, 'installation_activated', ['domain' => $activation->domain, 'version' => $activation->version], 'license server');
            }

            return $activation;
        });

        $this->webhooks->activationEvent($activation, 'activation.created');

        return ['token' => $this->token($activation), 'activation' => $activation];
    }

    /**
     * @param  array{version?: ?string, php_version?: ?string, ip?: ?string}  $install
     * @return array{token: string, activation: SoftwareActivation, check_in_requested: bool}
     */
    public function checkIn(string $token, array $install): array
    {
        $activation = $this->fromToken($token);
        $requested = $activation->check_in_requested;

        $activation->forceFill([
            'version' => $install['version'] ?? $activation->version,
            'php_version' => $install['php_version'] ?? $activation->php_version,
            'server_ip' => $install['ip'] ?? $activation->server_ip,
            'last_check_in_at' => now(),
            'check_in_requested' => false,
        ])->save();

        return ['token' => $this->token($activation), 'activation' => $activation, 'check_in_requested' => $requested];
    }

    public function deactivateInstall(string $token): void
    {
        $this->deactivate($this->fromToken($token), null, 'license server');
    }

    /** Signed token claims: lic, act, dom, env, plan, exp, support_until, max_version, status. */
    public function token(SoftwareActivation $activation): string
    {
        $license = $activation->license()->with('plan')->first();

        return $this->tokens->sign([
            'lic' => $license->publicId(),
            'act' => $activation->publicId(),
            'dom' => $activation->domain,
            'env' => $activation->environment,
            'plan' => $license->plan?->code,
            'status' => $license->displayStatus(),
            'support_until' => $license->support_until?->getTimestamp(),
            'max_version' => $license->max_version,
            'exp' => now()->addDays(self::TOKEN_DAYS)->getTimestamp(),
        ]);
    }

    private function fromToken(string $token): SoftwareActivation
    {
        $claims = $this->tokens->verify($token) ?? throw new LicensingException('invalid_token', 'Invalid token.', 401);
        $uuid = str_starts_with((string) ($claims['act'] ?? ''), 'act_') ? substr($claims['act'], 4) : null;
        $activation = $uuid ? SoftwareActivation::query()->where('uuid', $uuid)->with('license')->first() : null;

        if ($activation === null || $activation->status !== SoftwareActivation::ACTIVE) {
            throw new LicensingException('activation_inactive', 'This installation is not active. Activate it again.', 403);
        }

        if (! $activation->license->allowsUse()) {
            throw new LicensingException('license_'.$activation->license->displayStatus(), 'This license is '.$activation->license->displayStatus().'.', 403);
        }

        return $activation;
    }

    /** @return array{0: string, 1: string} normalised domain and environment */
    private function checkNew(SoftwareLicense $license, string $domain, ?string $environment): array
    {
        if (in_array($license->status, [SoftwareLicense::SUSPENDED, SoftwareLicense::REVOKED], true)) {
            throw new LicensingException('license_'.$license->status, 'This license is '.$license->status.'.', 403);
        }

        $normalised = DomainName::normalise($domain) ?? throw new LicensingException('invalid_domain', 'Enter a valid domain name.', 422, 'domain');
        $environment = $environment === SoftwareActivation::STAGING || DomainName::isStaging($normalised) ? SoftwareActivation::STAGING : SoftwareActivation::PRODUCTION;

        $this->assertDomainFree($normalised, $license);

        if ($environment === SoftwareActivation::PRODUCTION) {
            $used = $license->activations()->where('environment', SoftwareActivation::PRODUCTION)
                ->whereIn('status', [SoftwareActivation::PENDING, SoftwareActivation::ACTIVE])->count();

            if ($used >= $license->domainLimit()) {
                throw new LicensingException('domain_limit_reached', "All {$license->domainLimit()} production domains are in use.");
            }
        }

        return [$normalised, $environment];
    }

    private function assertDomainFree(string $domain, SoftwareLicense $license): void
    {
        $taken = SoftwareActivation::query()->where('domain', $domain)
            ->whereIn('status', [SoftwareActivation::PENDING, SoftwareActivation::ACTIVE])->exists();

        if ($taken) {
            // Never say which license has it (it may belong to another customer).
            throw new LicensingException('domain_in_use', 'That domain is already active on a license.', 409, 'domain');
        }
    }

    /** @param  array<string, mixed>  $details */
    private function record(SoftwareLicense $license, string $event, array $details, ?User $actor, string $actorLabel): void
    {
        $this->licenses->event($license, $event, $details, $actorLabel);
        $this->audit->record('licensing.'.$event, $license, new: $details + ['by' => $actorLabel], organisation: $license->organisation, actor: $actor);
    }

    /** One activation change per license at a time (domain limit and uniqueness checks). */
    private function locked(SoftwareLicense $license, callable $callback): mixed
    {
        return Cache::lock('license-activations:'.$license->getKey(), 15)->block(10, fn () => DB::transaction($callback));
    }
}
