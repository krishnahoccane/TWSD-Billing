<?php

namespace App\Modules\Licensing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Licensing\Services\DownloadService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /downloads/{token}: streams the release package once. An unknown,
 * used or expired link is a 410 — ask the storefront for a new one.
 */
class DownloadController extends Controller
{
    public function __invoke(Request $request, string $token, DownloadService $downloads): Response
    {
        $link = $downloads->consume($token, $request->ip());

        if ($link === null) {
            return response('This download link has expired or was already used. Request a new one from your account.', 410)
                ->header('Content-Type', 'text/plain; charset=UTF-8')->header('Cache-Control', 'no-store');
        }

        $release = $link->release;

        return Storage::disk(DownloadService::DISK)->download($release->package_path, $release->package_name, [
            'Cache-Control' => 'no-store, private',
            'X-Content-SHA256' => (string) $release->sha256,
        ]);
    }
}
