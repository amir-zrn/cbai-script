<?php
namespace App\Providers;

use App\Services\FileLogger;
use Illuminate\Support\ServiceProvider;

class FileLoggerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(FileLogger::class, function ($app) {
            return new FileLogger();
        });
    }
}
