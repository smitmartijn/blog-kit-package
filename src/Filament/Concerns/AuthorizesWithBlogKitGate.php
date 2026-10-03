<?php

namespace Lostdomain\BlogKit\Filament\Concerns;

use Filament\Facades\Filament;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Lostdomain\BlogKit\Filament\BlogKitPlugin;
use UnitEnum;

/** Every resource action is allowed or denied by the one config('blog-kit.filament.gate') gate. */
trait AuthorizesWithBlogKitGate
{
    public static function getAuthorizationResponse(string|UnitEnum $action, ?Model $record = null): Response
    {
        $gate = (string) config('blog-kit.filament.gate');

        if (! Gate::has($gate)) {
            // Once per request: navigation and pages check this many times.
            if (! app()->bound('blog-kit.gate_missing_reported')) {
                app()->instance('blog-kit.gate_missing_reported', true);
                Log::error('blog-kit.filament.gate_missing', ['gate' => $gate]);
            }

            return Response::deny("Define the \"{$gate}\" gate to use the Blog section.");
        }

        return Gate::inspect($gate);
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        $panel = Filament::getCurrentPanel();

        return $panel?->hasPlugin('blog-kit')
            ? $panel->getPlugin('blog-kit')->getNavigationGroup()
            : app(BlogKitPlugin::class)->getNavigationGroup();
    }
}
