<?php

namespace App\Providers;

use App\Observers\AuditoriaObserver;
use App\Support\Auditor;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Auditoría automática de altas, cambios y bajas
        foreach (array_keys(AuditoriaObserver::MODELOS) as $modelo) {
            $modelo::observe(AuditoriaObserver::class);
        }

        // Auditoría de inicios y cierres de sesión
        Event::listen(Login::class, fn (Login $e) => Auditor::registrar('login', 'Sesión', "{$e->user->name} inició sesión", $e->user, null, $e->user));
        Event::listen(Logout::class, fn (Logout $e) => $e->user
            ? Auditor::registrar('logout', 'Sesión', "{$e->user->name} cerró sesión", $e->user, null, $e->user)
            : null);
        Event::listen(Failed::class, fn (Failed $e) => Auditor::registrar(
            'login_fallido',
            'Sesión',
            'Intento de acceso fallido con '.mb_substr((string) ($e->credentials['email'] ?? '?'), 0, 100),
            $e->user,
        ));
    }
}