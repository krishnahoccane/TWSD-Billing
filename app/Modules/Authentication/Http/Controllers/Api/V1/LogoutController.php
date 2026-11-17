<?php

namespace App\Modules\Authentication\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Laravel\Sanctum\PersonalAccessToken;

class LogoutController extends Controller
{
    public function __invoke(Request $request, AuditLogger $audit): Response
    {
        $user = $request->user();
        $token = $user->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        $audit->record('auth.api_logout', $user, actor: $user);

        return response()->noContent();
    }
}
