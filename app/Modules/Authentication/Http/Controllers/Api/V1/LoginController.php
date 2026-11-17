<?php

namespace App\Modules\Authentication\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Authentication\Http\Requests\ApiLoginRequest;
use App\Modules\Authentication\Http\Resources\ProfileResource;
use App\Modules\Authentication\Services\ApiTokenIssuer;
use Illuminate\Http\JsonResponse;

class LoginController extends Controller
{
    public function __invoke(ApiLoginRequest $request, ApiTokenIssuer $issuer): JsonResponse
    {
        [$user, $token] = $issuer->issue(
            $request->string('email')->toString(),
            $request->string('password')->toString(),
            $request->input('code'),
            $request->input('device_name'),
        );

        return response()->json([
            'data' => [
                'token' => $token->plainTextToken,
                'token_type' => 'Bearer',
                'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
                'user' => new ProfileResource($user),
            ],
        ]);
    }
}
