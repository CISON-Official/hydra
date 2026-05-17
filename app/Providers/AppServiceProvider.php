<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\RevocationService;
use App\Services\Crypto\HMACHandler;
use App\Services\Crypto\TokenManager;
use App\Services\QRGeneratorService;



class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */

    public function register(): void
    {
        $this->app->singleton(RevocationService::class, function ($app) {
            return new RevocationService();
        });
        $this->app->when(HMACHandler::class)
            ->needs('$secretKey')
            ->give(function () {
                return config('services.crypto.secret') ?? env('CRYPTO_SECRET_KEY');
            });
        $this->app->when(TokenManager::class)
            ->needs('$secretKey') // Note the single quotes and the dollar sign
            ->give(function () {
                $key = config('services.crypto.secret') ?? env('CRYPTO_SECRET_KEY');
                ;

                if (empty($key)) {
                    throw new \RuntimeException('Crypto secret key is missing for TokenManager.');
                }

                return $key;
            });
        $this->app->when(QRGeneratorService::class)
            ->needs('$baseUrl')
            ->give(function () {
                // Pulls the URL from your config/app.php ('url' key)
                return config('app.url') ?? 'http://localhost';
            })->needs('$secretKey')->give(function () {
                return config('app.qr_secret_key') ?? 'qr_secret_key';
            });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
