<?php

namespace Everest\Providers;

use Carbon\Carbon;
use Everest\Models;
use Everest\Models\User;
use Illuminate\Support\Str;
use Laravel\Cashier\Cashier;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Illuminate\Database\Eloquent\Relations\Relation;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Schema::defaultStringLength(191);

        Paginator::useBootstrap();

        Cashier::useCustomerModel(User::class);

        // If the APP_URL value is set with https:// make sure we force it here. Theoretically
        // this should just work with the proxy logic, but there are a lot of cases where it
        // doesn't, and it triggers a lot of support requests, so lets just head it off here.
        //
        // @see https://github.com/pterodactyl/panel/issues/3623
        if (Str::startsWith(config('app.url') ?? '', 'https://')) {
            URL::forceScheme('https');
        }

        Relation::enforceMorphMap([
            'allocation' => Models\Allocation::class,
            'api_key' => Models\ApiKey::class,
            'backup' => Models\Backup::class,
            'database' => Models\Database::class,
            'egg' => Models\Egg::class,
            'egg_variable' => Models\EggVariable::class,
            'schedule' => Models\Schedule::class,
            'server' => Models\Server::class,
            'ssh_key' => Models\UserSSHKey::class,
            'ticket' => Models\Ticket::class,
            'task' => Models\Task::class,
            'link' => Models\CustomLink::class,
            'user' => Models\User::class,
        ]);

        Carbon::serializeUsing(fn ($carbon) => $carbon->utc()->toIso8601ZuluString());

        // Provide default styling variables to all email views to prevent undefined variable errors.
        View::composer('emails.*', function ($view) {
            $defaults = [
                'brandPrimary' => '#4F46E5',
                'brandDark' => '#111827',
                'brandMuted' => '#6B7280',
                'backgroundColor' => '#f3f4f6',
                'cardBackground' => '#ffffff',
                'borderColor' => '#e5e7eb',
                'fontFamily' => "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif",
                'headingStyle' => "margin:0 0 12px; font-size:22px; line-height:1.3; color:#111827; font-weight:700;",
                'paragraphStyle' => "margin:0 0 16px; color:#111827; font-size:15px; line-height:1.6;",
                'mutedStyle' => "margin:8px 0 0; color:#6B7280; font-size:13px; line-height:1.5;",
            ];

            $data = $view->getData();
            foreach ($defaults as $key => $value) {
                if (!array_key_exists($key, $data) || $data[$key] === null) {
                    // For styles that are computed using other variables, allow partials to recompute when null.
                    $view->with($key, $value);
                }
            }
        });
    }

    /**
     * Register application service providers.
     */
    public function register(): void
    {
        // Only load the settings / theme service provider if the environment
        // is configured to allow it.
        if (!config('everest.load_environment_only', false) && $this->app->environment() !== 'testing') {
            $this->app->register(SettingsServiceProvider::class);
            $this->app->register(ThemeServiceProvider::class);
        }
    }
}
