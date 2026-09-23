<?php

namespace Goldnead\Affiliates;

use Goldnead\Affiliates\Http\Middleware\CaptureReferral;
use Goldnead\Affiliates\Integrations\PaymentsBridge;
use Goldnead\Affiliates\Support\Attribution;
use Goldnead\Affiliates\Support\Ledger;
use Goldnead\Affiliates\Support\Payouts;
use Goldnead\Affiliates\Support\Settings;
use Goldnead\Affiliates\Support\Tracking;
use Goldnead\BrandContext\Settings\SettingsRegistry;
use Illuminate\Support\Facades\Event;
use Statamic\Facades\CP\Nav;
use Statamic\Facades\Permission;
use Statamic\Providers\AddonServiceProvider;

class ServiceProvider extends AddonServiceProvider
{
    /**
     * The Control Panel bundle. The three values must byte-match `laravel()`
     * in vite.config.js.
     *
     * Untyped on purpose: the parent declares it without a type.
     */
    protected $vite = [
        'hotFile' => __DIR__.'/../dist/hot',
        'publicDirectory' => 'dist',
        'input' => ['resources/js/cp.js'],
    ];

    /**
     * The sibling that owns the shared "Suite" nav section, when installed.
     */
    public const SUITE_NAV = '\Goldnead\StatamicPayments\Cp\SuiteNav';

    /**
     * `affiliates::dashboard`, not the package name core would pick.
     */
    protected $viewNamespace = 'affiliates';

    public function register(): void
    {
        parent::register();

        $this->mergeConfigFrom(__DIR__.'/../config/affiliates.php', 'affiliates');

        // By class name, never under a short slug.
        $this->app->singleton(Affiliates::class);
        $this->app->singleton(Tracking::class);
        $this->app->singleton(Attribution::class);
        $this->app->singleton(Ledger::class);
        $this->app->singleton(Payouts::class);

        // On the resolving translator rather than in boot: nav and permission
        // labels are built before bootAddon() runs.
        $langPath = __DIR__.'/../resources/lang';
        $this->app->resolving('translator', fn ($translator) => $translator->addNamespace('affiliates', $langPath));

        if ($this->app->resolved('translator')) {
            $this->app['translator']->addNamespace('affiliates', $langPath);
        }
    }

    public function bootAddon(): void
    {
        $this->bootMigrations()
            ->bootCommands()
            ->bootTracking()
            ->bootIntegrations()
            ->bootSettings()
            ->bootPermissions()
            ->bootNavigation()
            ->bootPublishables();
    }

    protected function bootMigrations(): self
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        return $this;
    }

    /**
     * Registered by hand: core's command discovery runs after Statamic's boot
     * sequence, which a plain console context never reaches.
     */
    protected function bootCommands(): self
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                Console\Commands\Install::class,
                Console\Commands\Release::class,
                Console\Commands\Book::class,
            ]);
        }

        return $this;
    }

    /**
     * On every page of the site, after the session and cookie encryption.
     */
    protected function bootTracking(): self
    {
        $this->app['router']->pushMiddlewareToGroup('web', CaptureReferral::class);

        return $this;
    }

    /**
     * statamic-payments, only when installed. Registered by hand rather than
     * discovered from src/Listeners: discovery would wire them to event
     * classes that may not exist.
     */
    protected function bootIntegrations(): self
    {
        // Once per application, however often bootAddon() runs: a second
        // registration would book every commission twice.
        if ($this->app->bound(self::class.'.integrations')) {
            return $this;
        }

        $this->app->instance(self::class.'.integrations', true);

        if (PaymentsBridge::available()) {
            Event::listen('eloquent.created: '.ltrim(PaymentsBridge::PAYMENT, '\\'), [PaymentsBridge::class, 'created']);
            Event::listen(ltrim(PaymentsBridge::PAID, '\\'), [PaymentsBridge::class, 'paid']);

            if (class_exists(PaymentsBridge::REFUNDED)) {
                Event::listen(ltrim(PaymentsBridge::REFUNDED, '\\'), [PaymentsBridge::class, 'refunded']);
            }

            if (class_exists(PaymentsBridge::CHARGED_BACK)) {
                Event::listen(ltrim(PaymentsBridge::CHARGED_BACK, '\\'), [PaymentsBridge::class, 'chargedBack']);
            }
        }

        return $this;
    }

    /**
     * Per-brand settings, through the suite's one settings layer.
     */
    protected function bootSettings(): self
    {
        $this->app->make(SettingsRegistry::class)->register(Settings::class);

        return $this;
    }

    protected function bootPermissions(): self
    {
        Permission::extend(function (): void {
            Permission::group('affiliates', __('affiliates::cp.nav'), function (): void {
                Permission::register('view affiliates', function ($permission): void {
                    $permission->label(__('affiliates::cp.permission_view'))->children([
                        Permission::make('manage affiliates')->label(__('affiliates::cp.permission_manage')),
                        Permission::make('manage affiliate payouts')->label(__('affiliates::cp.permission_payouts')),
                    ]);
                });
                Permission::register(Settings::settingsPermission())
                    ->label(__('affiliates::settings.permission'));
            });
        });

        return $this;
    }

    /**
     * Under the suite's shared section when statamic-payments provides one,
     * under Tools otherwise.
     */
    protected function bootNavigation(): self
    {
        if (! config('affiliates.cp.enabled', true)) {
            return $this;
        }

        Nav::extend(function ($nav): void {
            $suiteNav = self::SUITE_NAV;
            $section = class_exists($suiteNav) ? $suiteNav::section() : 'Tools';

            $nav->create(__('affiliates::cp.nav'))
                ->section($section)
                ->icon('share-mega-phone')
                ->route('affiliates.partners.index')
                ->can('view affiliates')
                ->children([
                    $nav->item(__('affiliates::cp.partners'))->route('affiliates.partners.index')->can('view affiliates'),
                    $nav->item(__('affiliates::cp.commissions'))->route('affiliates.commissions.index')->can('view affiliates'),
                    $nav->item(__('affiliates::cp.payouts'))->route('affiliates.payouts.index')->can('manage affiliate payouts'),
                    $nav->item(__('affiliates::cp.rates'))->route('affiliates.rates.index')->can('manage affiliates'),
                    $nav->item(__('affiliates::cp.jv'))->route('affiliates.jv.index')->can('manage affiliates'),
                    // No "Settings" child: statamic-brand-context lists every
                    // addon's settings under Settings and opens its tab.
                ]);
        });

        return $this;
    }

    protected function bootPublishables(): self
    {
        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'affiliates-migrations');

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/affiliates'),
        ], 'affiliates-views');

        $this->publishes([
            __DIR__.'/../resources/lang' => lang_path('vendor/affiliates'),
        ], 'affiliates-translations');

        return $this;
    }
}
