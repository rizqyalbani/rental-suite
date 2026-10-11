<?php

use App\Http\Middleware\RoleMiddleware;
use App\Http\Middleware\CspReportOnlyHeader;
use App\Http\Middleware\SecurityRequestTelemetry;
use App\Http\Middleware\SetCurrentStore;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $trusted = env('TRUSTED_PROXIES');
        if ($trusted !== null && $trusted !== '') {
            $middleware->trustProxies(at: $trusted === '*' || $trusted === '**' ? $trusted : array_map(trim(...), explode(',', $trusted)));
        }

        $middleware->preventRequestForgery(except: [
            'csp-report',
            'debug/bugtrap-report',
        ]);

        $middleware->alias([
            'role' => RoleMiddleware::class,
            'set.store' => SetCurrentStore::class,
        ]);

        $middleware->appendToGroup('web', CspReportOnlyHeader::class);
        $middleware->appendToGroup('web', SecurityRequestTelemetry::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
