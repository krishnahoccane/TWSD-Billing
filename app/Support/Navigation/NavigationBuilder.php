<?php

namespace App\Support\Navigation;

use App\Models\User;
use App\Modules\AccessControl\Enums\Ability;
use Illuminate\Support\Facades\Route;

class NavigationBuilder
{
    /**
     * @return list<array{heading: ?string, items: list<array{label: string, icon: string, url: string, active: bool}>}>
     */
    public function for(User $user): array
    {
        $menu = config('navigation.'.($user->isPrivileged() ? 'platform' : 'organisation'), []);
        $sections = [];

        foreach ($menu as $section) {
            $items = [];

            foreach ($section['items'] as $item) {
                if (! Route::has($item['route'])) {
                    continue;
                }

                $ability = $item['ability'] ?? null;

                if ($ability instanceof Ability && ! $user->hasAbilityAnywhere($ability)) {
                    continue;
                }

                $items[] = [
                    'label' => $item['label'],
                    'icon' => $item['icon'],
                    'url' => route($item['route']),
                    'active' => request()->routeIs($item['route']),
                ];
            }

            if ($items !== []) {
                $sections[] = ['heading' => $section['heading'] ?? null, 'items' => $items];
            }
        }

        return $sections;
    }
}
