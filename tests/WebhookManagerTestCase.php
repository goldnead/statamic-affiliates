<?php

namespace Goldnead\Affiliates\Tests;

use Goldnead\Affiliates\Integrations\WebhookManager\WebhookManagerBridge;
use Goldnead\Affiliates\Integrations\WebhookManager\WebhookPayload;
use Goldnead\WebhookManager\WebhookManagerServiceProvider;
use ReflectionClass;
use ReflectionMethod;

/**
 * The suite with the real webhook manager booted: its provider, its
 * migrations, its registries and its TriggerDetected listener. Every other
 * test runs without it, so a bridge that does anything on its own shows up
 * there, and the install without the package at all is proved in a separate
 * process (tests/Unit/BootWithoutWebhookManagerTest.php).
 */
abstract class WebhookManagerTestCase extends TestCase
{
    protected function getPackageProviders($app)
    {
        return [...parent::getPackageProviders($app), WebhookManagerServiceProvider::class];
    }

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        $this->loadMigrationsFrom(dirname((new ReflectionClass(WebhookManagerServiceProvider::class))->getFileName(), 2).'/database/migrations');
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Testbench never runs the addons' bootAddon(), so the bridge's two
        // booted attempts found no manager. Wire the manager by hand and give
        // the bridge the retry production gives it.
        if (! $this->app->bound('webhook-manager')) {
            $manager = $this->app->getProvider(WebhookManagerServiceProvider::class);
            foreach (['bootWebhookConfig', 'bootBindings', 'bootRegistries', 'bootEvents'] as $method) {
                (new ReflectionMethod($manager, $method))->invoke($manager);
            }
        }

        $this->app->make(WebhookManagerBridge::class)->boot($this->app->make('events'));

        WebhookPayload::forgetBrands();
    }
}
