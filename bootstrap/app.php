<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Console\Scheduling\Schedule;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'hasTenant'           => \App\Http\Middleware\EnsureUserHasTenant::class,
            'platformAdmin'       => \App\Http\Middleware\EnsureUserIsPlatformAdmin::class,
            // Risolve il tenant attivo dalla sessione (feature multi-brand agenzia).
            // Deve essere applicato prima di 'hasTenant' nel gruppo 'auth'.
            'resolveActiveTenant' => \App\Http\Middleware\ResolveActiveTenant::class,
            // Blocca l'accesso all'app finché l'onboarding non è completato.
            'onboardingComplete'  => \App\Http\Middleware\RedirectIfOnboardingIncomplete::class,
        ]);
    })
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('ai:drain-generation-queue --queue=default')
            ->everyMinute()
            ->withoutOverlapping();

        $schedule->command('social:publish-due --limit=25')
            ->everyMinute()
            ->withoutOverlapping();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
