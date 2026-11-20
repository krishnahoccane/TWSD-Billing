<?php

namespace App\Modules\Licensing\Http\Controllers\LicenseServer;

use App\Http\Controllers\Controller;
use App\Modules\Licensing\Services\ActivationService;
use App\Modules\Licensing\Support\ApiPresenter;
use App\Modules\Licensing\Support\LicenseToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Called by LabelDesk installs (not by the storefront):
 *   POST /api/license/v1/activate     { key, domain, environment?, version?, php_version?, instance_id? }
 *   POST /api/license/v1/check-in     { token, version?, php_version? }   (every 24 h)
 *   POST /api/license/v1/deactivate   { token }
 *   GET  /api/license/v1/public-key   Ed25519 key to verify tokens offline
 * Rate-limited per IP. The key is never logged or echoed back.
 */
class LicenseServerController extends Controller
{
    public function __construct(private readonly ActivationService $activations) {}

    public function activate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:64'],
            'domain' => ['required', 'string', 'max:253'],
            'environment' => ['nullable', 'in:production,staging'],
            'version' => ['nullable', 'string', 'max:20', 'regex:/^[0-9A-Za-z.\-+]+$/'],
            'php_version' => ['nullable', 'string', 'max:20', 'regex:/^[0-9A-Za-z.\-+]+$/'],
            'instance_id' => ['nullable', 'string', 'max:100'],
        ]);

        $result = $this->activations->activateInstall($data['key'], [
            'domain' => $data['domain'],
            'environment' => $data['environment'] ?? null,
            'version' => $data['version'] ?? null,
            'php_version' => $data['php_version'] ?? null,
            'instance_id' => $data['instance_id'] ?? null,
            'ip' => $request->ip(),
        ]);

        return $this->state($result['token'], $result['activation']->license, false);
    }

    public function checkIn(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:4000'],
            'version' => ['nullable', 'string', 'max:20', 'regex:/^[0-9A-Za-z.\-+]+$/'],
            'php_version' => ['nullable', 'string', 'max:20', 'regex:/^[0-9A-Za-z.\-+]+$/'],
        ]);

        $result = $this->activations->checkIn($data['token'], [
            'version' => $data['version'] ?? null,
            'php_version' => $data['php_version'] ?? null,
            'ip' => $request->ip(),
        ]);

        return $this->state($result['token'], $result['activation']->license, $result['check_in_requested']);
    }

    public function deactivate(Request $request): Response
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:4000']]);
        $this->activations->deactivateInstall($data['token']);

        return response()->noContent();
    }

    public function publicKey(LicenseToken $tokens): JsonResponse
    {
        return response()->json(['alg' => 'EdDSA', 'crv' => 'Ed25519', 'kid' => $tokens->keyId(), 'public_key' => $tokens->publicKeyBase64()]);
    }

    private function state(string $token, $license, bool $checkInRequested): JsonResponse
    {
        return response()->json([
            'token' => $token,
            'status' => $license->displayStatus(),
            'support_until' => ApiPresenter::date($license->support_until),
            'max_version' => $license->max_version,
            'check_in_requested' => $checkInRequested,
            'messages' => [],
        ]);
    }
}
