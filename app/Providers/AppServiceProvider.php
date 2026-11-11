<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Models\Borrower;
   use App\Models\User;
   use App\Observers\BorrowerObserver;
   use App\Observers\UserObserver;
   use Illuminate\Support\Facades\URL;

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
        Borrower::observe(BorrowerObserver::class);
       User::observe(UserObserver::class);
       if ($this->app->isProduction()) {
       URL::forceHttps();
   }
    }
}
