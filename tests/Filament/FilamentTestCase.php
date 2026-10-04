<?php

namespace Lostdomain\BlogKit\Tests\Filament;

use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Filament\Actions\ActionsServiceProvider;
use Filament\FilamentServiceProvider;
use Filament\Forms\FormsServiceProvider;
use Filament\Infolists\InfolistsServiceProvider;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\QueryBuilder\QueryBuilderServiceProvider;
use Filament\Schemas\SchemasServiceProvider;
use Filament\Support\SupportServiceProvider;
use Filament\Tables\TablesServiceProvider;
use Filament\Widgets\WidgetsServiceProvider;
use Illuminate\Support\Facades\Gate;
use Kirschbaum\PowerJoins\PowerJoinsServiceProvider;
use Livewire\LivewireServiceProvider;
use Lostdomain\BlogKit\Tests\Fixtures\AdminPanelProvider;
use Lostdomain\BlogKit\Tests\Fixtures\User;
use Lostdomain\BlogKit\Tests\TestCase;
use RyanChandler\BladeCaptureDirective\BladeCaptureDirectiveServiceProvider;

abstract class FilamentTestCase extends TestCase
{
    protected User $admin;

    protected function getPackageProviders($app): array
    {
        // Not every Filament 4/5 release ships every sub-package (query-builder came later).
        return array_values(array_filter([
            ...parent::getPackageProviders($app),
            BladeIconsServiceProvider::class,
            BladeHeroiconsServiceProvider::class,
            BladeCaptureDirectiveServiceProvider::class,
            PowerJoinsServiceProvider::class,
            SupportServiceProvider::class,
            ActionsServiceProvider::class,
            FormsServiceProvider::class,
            InfolistsServiceProvider::class,
            NotificationsServiceProvider::class,
            QueryBuilderServiceProvider::class,
            SchemasServiceProvider::class,
            TablesServiceProvider::class,
            WidgetsServiceProvider::class,
            FilamentServiceProvider::class,
            // After Filament, the order package discovery uses in a real app: Filament < 4.13.3 / 5.8.3
            // binds Livewire's DataStore as non-shared, which breaks when Livewire registers first.
            LivewireServiceProvider::class,
            AdminPanelProvider::class,
        ], 'class_exists'));
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('auth.providers.users.model', User::class);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Fixtures/migrations');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => bcrypt('secret')]);
    }

    protected function allowBlogKit(bool $allowed = true): void
    {
        Gate::define('viewBlogKit', fn (User $user) => $allowed);
    }
}
