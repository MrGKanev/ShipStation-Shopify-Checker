<?php

namespace App\Providers;

use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Integrations\Shopify\ShopifyAdminClient;
use App\Models\User;
use App\UserRole;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Spatie\Health\Checks\Checks\CacheCheck;
use Spatie\Health\Checks\Checks\DatabaseCheck;
use Spatie\Health\Checks\Checks\QueueCheck;
use Spatie\Health\Checks\Checks\ScheduleCheck;
use Spatie\Health\Checks\Checks\UsedDiskSpaceCheck;
use Spatie\Health\Facades\Health;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(ShopifyAdminGateway::class, ShopifyAdminClient::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Health::checks([
            DatabaseCheck::new(),
            CacheCheck::new(),
            UsedDiskSpaceCheck::new()->warnWhenUsedSpaceIsAbovePercentage(80)->failWhenUsedSpaceIsAbovePercentage(90),
            ScheduleCheck::new()->heartbeatMaxAgeInMinutes(5),
            QueueCheck::new()->failWhenHealthJobTakesLongerThanMinutes(10),
        ]);

        Gate::define(
            'manage-administration',
            fn (User $user): bool => $user->role === UserRole::Admin,
        );
        Gate::define('run-audits', fn (User $user): bool => in_array($user->role, [UserRole::Operator, UserRole::Admin], true));

        RateLimiter::for('login', function (Request $request): Limit {
            $email = Str::lower((string) $request->input('email'));

            return Limit::perMinute(5)->by(Str::transliterate($email.'|'.$request->ip()));
        });
        RateLimiter::for('oauth', fn (Request $request): Limit => Limit::perMinute(10)->by($request->ip()));

        RateLimiter::for('spot-check', fn (Request $request): Limit => Limit::perMinute(10)->by(
            ($request->user()?->getAuthIdentifier() ?? 'guest').'|'.$request->ip(),
        ));

        RateLimiter::for('tracking', fn (Request $request): Limit => Limit::perMinute(10)->by(
            ($request->user()?->getAuthIdentifier() ?? 'guest').'|'.$request->ip(),
        ));
        RateLimiter::for('packing-slip', fn (Request $request): Limit => Limit::perMinute(10)->by(
            ($request->user()?->getAuthIdentifier() ?? 'guest').'|'.$request->ip(),
        ));
        RateLimiter::for('tag-search', fn (Request $request): Limit => Limit::perMinute(10)->by(
            ($request->user()?->getAuthIdentifier() ?? 'guest').'|'.$request->ip(),
        ));
        RateLimiter::for('audit-report', fn (Request $request): Limit => Limit::perMinute(5)->by(($request->user()?->getAuthIdentifier() ?? 'guest').'|'.$request->ip()));
        RateLimiter::for('api-health', fn (Request $request): Limit => Limit::perMinute(5)->by(($request->user()?->getAuthIdentifier() ?? 'guest').'|'.$request->ip()));
    }
}
