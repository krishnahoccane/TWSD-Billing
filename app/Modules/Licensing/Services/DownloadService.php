<?php

namespace App\Modules\Licensing\Services;

use App\Models\User;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Licensing\Exceptions\LicensingException;
use App\Modules\Licensing\Models\SoftwareDownloadLink;
use App\Modules\Licensing\Models\SoftwareLicense;
use App\Modules\Licensing\Models\SoftwareProduct;
use App\Modules\Licensing\Models\SoftwareRelease;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Release packages and downloads.
 *
 * Packages are stored on this server (private "local" disk, never under
 * public/). A storefront asks for a download for a license; if the license
 * may use that version, it gets a link that works once, for 15 minutes.
 *
 * Entitlement: a version is available when it was released on or before the
 * license's support_until ("updates & support"). An expired annual license
 * keeps the versions it was entitled to; suspended and revoked licenses get
 * nothing.
 */
class DownloadService
{
    public const LINK_MINUTES = 15;

    public const DISK = 'local';

    public function __construct(
        private readonly LicenseService $licenses,
        private readonly AuditLogger $audit,
    ) {}

    /** The newest published version this license may download (null: none). */
    public function entitledRelease(SoftwareLicense $license, string $channel = 'stable'): ?SoftwareRelease
    {
        return SoftwareRelease::query()->where('software_product_id', $license->software_product_id)
            ->where('is_published', true)->where('channel', $channel)
            ->when($license->support_until, fn ($q) => $q->where('released_at', '<=', $license->support_until))
            ->orderByDesc('released_at')->orderByDesc('id')->first();
    }

    /**
     * @return array{link: SoftwareDownloadLink, token: string, release: SoftwareRelease}
     */
    public function issue(SoftwareLicense $license, string $version, string $actorLabel, ?User $actor = null): array
    {
        if (! $license->allowsUse()) {
            throw new LicensingException('license_'.$license->displayStatus(), 'This license is '.$license->displayStatus().'.', 403);
        }

        $release = SoftwareRelease::query()->where('software_product_id', $license->software_product_id)
            ->where('version', $version)->where('is_published', true)->first()
            ?? throw new LicensingException('release_not_found', 'That version does not exist.', 404, 'version');

        if ($license->support_until !== null && $release->released_at->gt($license->support_until)) {
            $latest = $this->entitledRelease($license);

            throw new LicensingException('updates_expired',
                'Updates for this license ended on '.$license->support_until->format('d M Y').'. Renew updates to download '.$release->version
                    .($latest ? '; the newest version available is '.$latest->version.'.' : '.'), 403, 'version');
        }

        if (blank($release->package_path) || ! Storage::disk(self::DISK)->exists($release->package_path)) {
            throw new LicensingException('package_unavailable', 'The package for '.$release->version.' has not been uploaded yet.', 409, 'version');
        }

        $token = Str::random(64);

        $link = SoftwareDownloadLink::query()->create([
            'token_hash' => hash('sha256', $token),
            'software_license_id' => $license->getKey(),
            'software_release_id' => $release->getKey(),
            'requested_by' => Str::limit($actorLabel, 115, ''),
            'expires_at' => now()->addMinutes(self::LINK_MINUTES),
        ]);

        $this->licenses->event($license, 'download_link_issued', ['version' => $release->version], $actorLabel);

        return ['link' => $link, 'token' => $token, 'release' => $release];
    }

    /** Use a link once. Null when unknown, used or expired. */
    public function consume(string $token, ?string $ip): ?SoftwareDownloadLink
    {
        if (strlen($token) !== 64) {
            return null;
        }

        $link = SoftwareDownloadLink::query()->where('token_hash', hash('sha256', $token))->with(['release', 'license'])->first();

        if ($link === null || $link->used_at !== null || $link->expires_at->isPast()) {
            return null;
        }

        // Atomic: two simultaneous requests cannot both use the link.
        $claimed = DB::table('software_download_links')->where('id', $link->getKey())->whereNull('used_at')
            ->update(['used_at' => now(), 'used_ip' => $ip]);

        if ($claimed !== 1 || ! $link->license->allowsUse()) {
            return null;
        }

        $this->licenses->event($link->license, 'downloaded', ['version' => $link->release->version], 'download link');

        return $link;
    }

    /** Store (or replace) a release's package and record its size and SHA-256. */
    public function storePackage(SoftwareRelease $release, UploadedFile $file, User $actor): SoftwareRelease
    {
        $product = SoftwareProduct::query()->findOrFail($release->software_product_id);
        $name = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)) ?: $product->code.'-'.$release->version;
        $name = $name.'.'.strtolower($file->getClientOriginalExtension() ?: 'zip');
        $directory = 'releases/'.$product->code.'/'.Str::slug($release->version, '.');

        $old = $release->package_path;
        $path = Storage::disk(self::DISK)->putFileAs($directory, $file, $name);

        $release->forceFill([
            'package_path' => $path,
            'package_name' => $name,
            'size_bytes' => Storage::disk(self::DISK)->size($path),
            'sha256' => hash_file('sha256', Storage::disk(self::DISK)->path($path)),
        ])->save();

        if ($old !== null && $old !== $path) {
            Storage::disk(self::DISK)->delete($old);
        }

        $this->audit->record('licensing.release_package_uploaded', $release,
            new: ['version' => $release->version, 'size_bytes' => $release->size_bytes, 'sha256' => $release->sha256], actor: $actor);

        return $release;
    }
}
