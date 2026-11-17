<?php

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsureTwoFactorEnrolled;
use App\Http\Middleware\SecurityHeaders;
use App\Modules\Installer\Http\Middleware\InstallationGate;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api/v1',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->prepend(AssignRequestId::class);
        $middleware->append(InstallationGate::class);
        $middleware->append(SecurityHeaders::class);

        $middleware->alias([
            'active' => EnsureAccountIsActive::class,
            'two-factor.enrolled' => EnsureTwoFactorEnrolled::class,
        ]);

        $middleware->throttleApi('api');
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // The API always answers in JSON, whatever the Accept header says.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Consistent API error envelope: { message, errors?, request_id }.
        $exceptions->respond(function ($response, Throwable $e, Request $request) {
            if (! $request->is('api/*') || ! $response instanceof JsonResponse) {
                return $response;
            }

            $data = $response->getData(true);
            $status = $response->getStatusCode();

            $payload = [
                'message' => $status >= 500 && ! config('app.debug')
                    ? 'Server error.'
                    : ($data['message'] ?? ($e instanceof HttpExceptionInterface ? $e->getMessage() : 'Error.')),
            ];

            if (isset($data['errors'])) {
                $payload['errors'] = $data['errors'];
            }

            $payload['request_id'] = Context::get('request_id');

            return $response->setData($payload);
        });
    })->create();
