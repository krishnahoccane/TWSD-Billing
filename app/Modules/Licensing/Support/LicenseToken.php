<?php

namespace App\Modules\Licensing\Support;

use App\Modules\Integrations\Models\IntegrationSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Signed license tokens for installs: a JWT with alg "EdDSA" (Ed25519, via
 * libsodium). LabelDesk verifies it offline with the public key shown under
 * Licensing → Storefronts (and served at /api/license/v1/public-key).
 *
 * The signing key is generated once, kept only in integration_settings
 * (encrypted with APP_KEY) and never shown.
 */
class LicenseToken
{
    private const PROVIDER = 'licensing';

    /** @param  array<string, mixed>  $claims */
    public function sign(array $claims): string
    {
        $header = self::b64(json_encode(['alg' => 'EdDSA', 'typ' => 'JWT', 'kid' => $this->keyId()], JSON_THROW_ON_ERROR));
        $payload = self::b64(json_encode($claims + ['iat' => time(), 'iss' => config('app.url')], JSON_THROW_ON_ERROR));
        $signature = sodium_crypto_sign_detached($header.'.'.$payload, $this->secretKey());

        return $header.'.'.$payload.'.'.self::b64($signature);
    }

    /**
     * @return array<string, mixed>|null claims when the signature is valid (expiry is NOT checked here)
     */
    public function verify(string $token): ?array
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            return null;
        }

        [$header, $payload, $signature] = $parts;
        $raw = self::unb64($signature);

        if ($raw === null || strlen($raw) !== SODIUM_CRYPTO_SIGN_BYTES
            || ! sodium_crypto_sign_verify_detached($raw, $header.'.'.$payload, $this->publicKey())) {
            return null;
        }

        $claims = json_decode((string) self::unb64($payload), true);

        return is_array($claims) ? $claims : null;
    }

    /** Base64 (standard) Ed25519 public key for LabelDesk. */
    public function publicKeyBase64(): string
    {
        return base64_encode($this->publicKey());
    }

    public function keyId(): string
    {
        return substr(hash('sha256', $this->publicKey()), 0, 16);
    }

    private function publicKey(): string
    {
        return sodium_crypto_sign_publickey_from_secretkey($this->secretKey());
    }

    private function secretKey(): string
    {
        $secret = $this->row()->secrets['signing_key'] ?? null;

        if ($secret === null) {
            // First use: create the key pair exactly once.
            Cache::lock('licensing:signing-key', 10)->block(10, function () {
                DB::transaction(function () {
                    $row = IntegrationSetting::query()->where('provider', self::PROVIDER)->lockForUpdate()->firstOrFail();

                    if (blank($row->secrets['signing_key'] ?? null)) {
                        $row->secrets = ['signing_key' => base64_encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()))];
                        $row->save();
                    }
                });
            });

            $secret = $this->row()->secrets['signing_key'] ?? null;
        }

        $key = base64_decode((string) $secret, true);

        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new RuntimeException('The license signing key is invalid.');
        }

        return $key;
    }

    private function row(): IntegrationSetting
    {
        return IntegrationSetting::query()->firstOrCreate(['provider' => self::PROVIDER], ['settings' => []]);
    }

    private static function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function unb64(string $data): ?string
    {
        $decoded = base64_decode(strtr($data, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
