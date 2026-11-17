<?php

namespace Tests\Feature\Ui;

use App\Livewire\OrganisationSwitcher;
use App\Models\User;
use App\Modules\Organisations\Enums\OrganisationStatus;
use App\Modules\Organisations\Models\Organisation;
use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;
use Tests\TestCase;

class NavigationAndDashboardTest extends TestCase
{
    public function test_every_navigation_icon_exists(): void
    {
        foreach (['platform', 'organisation'] as $menu) {
            foreach (config("navigation.{$menu}") as $section) {
                foreach ($section['items'] as $item) {
                    $this->assertNotEmpty(
                        Blade::render('<x-ui.icon :name="$name" />', ['name' => $item['icon']]),
                        "Missing icon {$item['icon']}",
                    );
                }
            }
        }
    }

    public function test_platform_dashboard_reports_real_organisation_counts(): void
    {
        Organisation::factory()->count(3)->create();
        Organisation::factory()->status(OrganisationStatus::Suspended)->create();

        $this->actingAs($this->superAdmin())->get('/dashboard')
            ->assertOk()
            ->assertSeeInOrder(['Organisations', '4', '3 active · 1 suspended'])
            ->assertSee('Monthly recurring revenue')
            ->assertSee('Recent activity');
    }

    public function test_super_admin_navigation_is_not_shown_to_organisation_managers(): void
    {
        $manager = $this->organisationManager(Organisation::factory()->create());

        $this->actingAs($manager)->get('/dashboard')
            ->assertOk()
            ->assertDontSee('Design System')
            ->assertDontSee('Organisation Management')
            ->assertDontSee('Integrations')
            ->assertDontSee('Recent audit activity');

        $this->actingAs($this->superAdmin())->get('/dashboard')->assertSee('Design System');
    }

    public function test_hidden_pages_are_also_forbidden_server_side(): void
    {
        $organisation = Organisation::factory()->create();

        $this->actingAs($this->organisationManager($organisation))->get('/design-system')->assertForbidden();
        $this->actingAs($this->billingManager($organisation))->get('/design-system')->assertForbidden();
        $this->actingAs($this->operationsAdmin($organisation))->get('/design-system')->assertForbidden();
        $this->actingAs($this->superAdmin())->get('/design-system')->assertOk();
    }

    public function test_organisation_dashboard_shows_only_assigned_organisations(): void
    {
        $mine = Organisation::factory()->create(['name' => 'Mine Trading Co']);
        Organisation::factory()->create(['name' => 'Someone Else Ltd']);

        $this->actingAs($this->organisationManager($mine))->get('/dashboard')
            ->assertOk()
            ->assertSee('Mine Trading Co')
            ->assertSee('Organisation Manager')
            ->assertDontSee('Someone Else Ltd');
    }

    public function test_user_without_an_organisation_sees_an_empty_state(): void
    {
        $this->actingAs(User::factory()->create())->get('/dashboard')
            ->assertOk()
            ->assertSee('No organisation assigned');
    }

    public function test_switcher_lists_only_assigned_organisations(): void
    {
        [$a, $b] = Organisation::factory()->count(2)->create();
        $other = Organisation::factory()->create(['name' => 'Unrelated Holdings']);

        Livewire::actingAs($this->organisationManager($a, $b))
            ->test(OrganisationSwitcher::class)
            ->assertSee($a->name)
            ->assertSee($b->name)
            ->assertDontSee('Unrelated Holdings')
            ->assertDontSee('All organisations');
    }

    public function test_switcher_rejects_an_unassigned_organisation_uuid(): void
    {
        [$a, $b] = Organisation::factory()->count(2)->create();
        $other = Organisation::factory()->create();
        $manager = $this->organisationManager($a, $b);

        Livewire::actingAs($manager)
            ->test(OrganisationSwitcher::class)
            ->call('select', $other->uuid)
            ->assertForbidden();

        Livewire::actingAs($manager)
            ->test(OrganisationSwitcher::class)
            ->call('select', $b->uuid)
            ->assertRedirect(route('dashboard'));

        $this->assertSame($b->uuid, session('wcc.organisation'));
    }
}
