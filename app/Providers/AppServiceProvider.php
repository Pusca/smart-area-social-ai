<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Su Windows a volte OpenSSL non vede la conf se non la forziamo nel processo PHP
        $conf = env('OPENSSL_CONF');
        if (!empty($conf)) {
            putenv("OPENSSL_CONF={$conf}");
        }
    }
}
