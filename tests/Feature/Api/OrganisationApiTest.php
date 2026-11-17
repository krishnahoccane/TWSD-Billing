<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Modules\Organisations\Models\Organisation;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrganisationApiTest extends TestCase
{
    public function test_endpoints_require_authentication(): void
    {
        $organisation = Organisation::factory()->create();

        $this->getJson('/api/v1/organisations')->assertUnauthorized();
        $this->getJson('/api/v1/organisations/'.$organisation->uuid)->assertUnauthorized();
        $this->postJson('/api/v1/organisations', [])->assertUnauthorized();
    }

    public function test_index_returns_only_the_callers_organisations(): void
    {
        [$a, $b] = Organisation::factory()->count(2)->create();
        Organisation::factory()->count(3)->create();
        Sanctum::actingAs($this->organisationManager($a, $b));

        $response = $this->getJson('/api/v1/organisations')->assertOk()->assertJsonCount(2, 'data');

        $this->assertEqualsCanonicalizing([$a->uuid, $b->uuid], $response->json('data.*.id'));
        $this->assertSame(2, $response->json('meta.total'));
        // Internal notes and numeric ids are never exposed to organisation users.
        $this->assertArrayNotHasKey('notes', $response->json('data.0'));
        $this->assertStringNotContainsString('"id":'.$a->id.',', $response->getContent());

        Sanctum::actingAs($this->superAdmin());
        $this->getJson('/api/v1/organisations?per_page=2')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 5);
        $this->getJson('/api/v1/organisations?search='.urlencode($a->name))->assertJsonCount(1, 'data');
    }

    public function test_show_hides_other_organisations_behind_a_404(): void
    {
        [$mine, $theirs] = Organisation::factory()->count(2)->create();
        Sanctum::actingAs($this->organisationManager($mine));

        $this->getJson('/api/v1/organisations/'.$mine->uuid)->assertOk()->assertJsonPath('data.id', $mine->uuid);
        $this->getJson('/api/v1/organisations/'.$theirs->uuid)->assertNotFound()->assertJsonStructure(['message', 'request_id']);
        $this->getJson('/api/v1/organisations/'.$theirs->id)->assertNotFound();
        $this->getJson('/api/v1/organisations/'.$theirs->uuid.'/managers')->assertNotFound();
        $this->putJson('/api/v1/organisations/'.$theirs->uuid, ['name' => 'Hacked', 'country' => 'IN'])->assertNotFound();
        $this->postJson('/api/v1/organisations/'.$theirs->uuid.'/managers', ['email' => 'a@b.example', 'role' => 'organisation_manager'])->assertNotFound();
    }

    public function test_only_super_admin_can_create_and_update(): void
    {
        $organisation = Organisation::factory()->create(['name' => 'Before']);
        $payload = ['name' => 'Api Created', 'code' => 'api-1', 'country' => 'IN'];

        Sanctum::actingAs($this->organisationManager($organisation));
        $this->postJson('/api/v1/organisations', $payload)->assertForbidden();
        $this->putJson('/api/v1/organisations/'.$organisation->uuid, ['name' => 'Hacked', 'country' => 'IN'])->assertForbidden();
        $this->assertSame('Before', $organisation->fresh()->name);

        Sanctum::actingAs($this->superAdmin());
        $this->postJson('/api/v1/organisations', $payload)
            ->assertCreated()
            ->assertJsonPath('data.code', 'API-1')
            ->assertJsonPath('data.status', 'active');
        $this->postJson('/api/v1/organisations', $payload)->assertStatus(422)->assertJsonValidationErrors('code');

        $this->putJson('/api/v1/organisations/'.$organisation->uuid, ['name' => 'After', 'country' => 'IN', 'code' => 'NEWCODE'])
            ->assertOk()
            ->assertJsonPath('data.name', 'After')
            ->assertJsonPath('data.code', $organisation->code);
    }

    public function test_request_body_cannot_redirect_a_write_to_another_organisation(): void
    {
        [$a, $b] = Organisation::factory()->count(2)->create(['name' => 'Original']);
        Sanctum::actingAs($this->superAdmin());

        $this->putJson('/api/v1/organisations/'.$a->uuid, [
            'name' => 'Renamed', 'country' => 'IN', 'organisation_id' => $b->id, 'id' => $b->id, 'uuid' => $b->uuid, 'status' => 'inactive',
        ])->assertOk();

        $this->assertSame('Renamed', $a->fresh()->name);
        $this->assertSame('active', $a->fresh()->status->value);
        $this->assertSame('Original', $b->fresh()->name);
        $this->assertNotSame($b->uuid, $a->fresh()->uuid);
    }

    public function test_managers_endpoints(): void
    {
        $organisation = Organisation::factory()->create();
        $manager = $this->organisationManager($organisation);
        $candidate = User::factory()->create();

        Sanctum::actingAs($manager);
        $this->getJson('/api/v1/organisations/'.$organisation->uuid.'/managers')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.user.id', $manager->uuid)
            ->assertJsonPath('data.0.role', 'organisation_manager');
        $this->postJson('/api/v1/organisations/'.$organisation->uuid.'/managers', ['email' => $candidate->email, 'role' => 'organisation_manager'])
            ->assertForbidden();

        Sanctum::actingAs($this->superAdmin());
        $this->postJson('/api/v1/organisations/'.$organisation->uuid.'/managers', ['email' => $candidate->email, 'role' => 'billing_manager'])
            ->assertCreated()
            ->assertJsonPath('data.user.id', $candidate->uuid);
        $this->postJson('/api/v1/organisations/'.$organisation->uuid.'/managers', ['email' => $candidate->email, 'role' => 'billing_manager'])
            ->assertOk();
        $this->postJson('/api/v1/organisations/'.$organisation->uuid.'/managers', ['email' => $candidate->email, 'role' => 'super_admin'])
            ->assertStatus(422);

        $this->assertDatabaseCount('organisation_user_roles', 2);
    }
}
