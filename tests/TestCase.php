<?php

namespace Goldnead\Affiliates\Tests;

use Goldnead\Affiliates\Models\Partner;
use Goldnead\Affiliates\ServiceProvider;
use Goldnead\Affiliates\Tags\Affiliates as AffiliatesTag;
use Goldnead\StatamicPayments\Events\PaymentPaid;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Models\PaymentItem;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Facades\CP\Nav;
use Statamic\Facades\Role;
use Statamic\Facades\User;
use Statamic\Testing\AddonTestCase;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

abstract class TestCase extends AddonTestCase
{
    use PreventsSavingStacheItemsToDisk;
    use RefreshDatabase;

    protected string $addonServiceProvider = ServiceProvider::class;

    /** @var list<callable> Nav::extend() callbacks bootAddon() registered. */
    protected array $navCallbacks = [];

    protected function setUp(): void
    {
        parent::setUp();

        // AddonTestCase swaps Nav for a strict mock; keep the callback.
        Nav::shouldReceive('extend')->andReturnUsing(function ($callback) {
            $this->navCallbacks[] = $callback;
        });

        $provider = $this->app->getProvider(ServiceProvider::class);
        $provider?->bootAddon();

        // Core wires listeners and tags from its booted callback, which
        // Testbench never fires; do what that discovery would.
        $provider?->bootEvents();
        AffiliatesTag::register();

        // What statamic-consent's provider does on a real site: its cookie is
        // written by the banner script, unencrypted.
        EncryptCookies::except(['statamic_consent']);
        view()->addNamespace('affiliates', __DIR__.'/../resources/views');
    }

    /**
     * Registered here, not by a `migrate` call in setUp(): DDL inside
     * RefreshDatabase's transaction commits it implicitly under MySQL.
     *
     * payments' migrations are loaded without its provider: the tests use its
     * model and its events, not its checkout.
     */
    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../vendor/goldnead/statamic-brand-context/database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/../vendor/goldnead/statamic-payments/database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    protected function getPackageProviders($app)
    {
        return [
            ...parent::getPackageProviders($app),
            \Goldnead\BrandContext\ServiceProvider::class,
        ];
    }

    /**
     * The routes as a real site mounts them, plus a stand-in for the
     * checkout: a request in the `web` group that creates a payment, the
     * way statamic-payments' checkout does.
     */
    protected function defineRoutes($router): void
    {
        $router->middleware('web')
            ->name('statamic.')
            ->prefix('!/affiliates')
            ->group(__DIR__.'/../routes/actions.php');

        $router->middleware(['statamic.cp', 'statamic.cp.authenticated'])
            ->prefix('cp')
            ->name('statamic.cp.')
            ->group(__DIR__.'/../routes/cp.php');

        $router->middleware('web')->get('/landing', fn () => 'landing');

        $router->middleware('web')->post('/test-checkout', function () {
            $payment = Payment::query()->create([
                'provider' => 'mollie',
                'provider_id' => 'tr_'.uniqid(),
                'product' => request('product', 'kurs'),
                'amount_cent' => (int) request('amount', 10000),
                'currency' => 'EUR',
                'status' => Payment::STATUS_INITIATED,
                'email' => request('email', 'kaeufer@example.com'),
            ]);

            return ['id' => $payment->id];
        });
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', $this->testingConnection());
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('app.timezone', 'America/Chicago');
        $app['config']->set('brand-context.multi_brand', false);
        $app['config']->set('brand-context.cp.enabled', false);
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('session.driver', 'array');
        $app['config']->set('mail.default', 'array');
        $app['config']->set('statamic.editions.pro', true);
        $app['config']->set('affiliates.consent.mode', 'always');
        $app['config']->set('affiliates.commissions.hold_days', 30);
        $app['config']->set('affiliates.commissions.default.percent', 30);
    }

    /**
     * In-memory SQLite by default; DB_DRIVER=mysql runs the identical suite
     * against a real server, the only place the unique indexes are tested.
     *
     * @return array<string, mixed>
     */
    protected function testingConnection(): array
    {
        if (env('DB_DRIVER', 'sqlite') !== 'mysql') {
            return [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ];
        }

        return [
            'driver' => 'mysql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'affiliates_test'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
        ];
    }

    /** @param  array<string, mixed>  $attributes */
    public function makePartner(array $attributes = []): Partner
    {
        return Partner::query()->create([
            'name' => 'Clara Chor',
            'email' => 'clara@example.com',
            'code' => 'clara',
            'status' => Partner::STATUS_ACTIVE,
            'approved_at' => now(),
            ...$attributes,
        ]);
    }

    /**
     * A paid payment, the way statamic-payments leaves one before it
     * dispatches PaymentPaid.
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>  $items
     */
    public function makePayment(array $attributes = [], array $items = []): Payment
    {
        $payment = Payment::query()->create([
            'provider' => 'mollie',
            'provider_id' => 'tr_'.uniqid(),
            'product' => 'kurs',
            'amount_cent' => 10000,
            'currency' => 'EUR',
            'status' => Payment::STATUS_PAID,
            'email' => 'kaeufer@example.com',
            'paid_at' => Carbon::now(),
            ...$attributes,
        ]);

        foreach ($items as $item) {
            PaymentItem::query()->create([
                'payment_id' => $payment->id,
                'product' => 'kurs',
                'name' => 'Kurs',
                'amount_cent' => 10000,
                'quantity' => 1,
                'discount_cent' => 0,
                'kind' => PaymentItem::KIND_PRIMARY,
                ...$item,
            ]);
        }

        return $payment->fresh() ?? $payment;
    }

    public function pay(Payment $payment): void
    {
        PaymentPaid::dispatch($payment->fresh() ?? $payment);
    }

    protected function superUser(): UserContract
    {
        $user = User::make()->email('admin@example.com')->makeSuper();
        $user->save();

        return $user;
    }

    protected function cpUser(string ...$permissions): UserContract
    {
        $role = Role::make('affiliates-test-'.md5(implode(',', $permissions)))
            ->permissions(['access cp', ...$permissions]);
        $role->save();

        $user = User::make()->email(uniqid().'@example.com')->assignRole($role);
        $user->save();

        return $user;
    }

    protected function member(string $email = 'clara@example.com'): UserContract
    {
        $user = User::make()->email($email)->data(['name' => 'Clara Chor']);
        $user->save();

        return $user;
    }

    protected function referralCookie(string $code, ?int $clickedAt = null): string
    {
        return (string) json_encode(['c' => $code, 't' => $clickedAt ?? time()]);
    }
}
