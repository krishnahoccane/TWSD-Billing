<?php

namespace Tests\Feature\Finalisation;

use App\Models\User;
use App\Modules\AccessControl\Enums\Ability;
use App\Modules\AccessControl\Enums\RoleName;
use App\Modules\AccessControl\Models\Role;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Organisations\Models\Organisation;
use App\Modules\Support\Models\SupportRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdministrationTest extends TestCase
{
    public function test_support_requests_are_raised_answered_and_isolated(): void
    {
        [$a, $b] = Organisation::factory()->count(2)->create();
        $manager = $this->organisationManager($a);
        $billing = $this->billingManager($a);
        $outsider = $this->organisationManager($b);
        $admin = $this->superAdmin();

        $this->actingAs($manager)->post('/support', ['organisation' => $a->uuid, 'category' => 'email', 'subject' => 'Cannot sign in', 'message' => 'A user cannot sign in since Monday.'])
            ->assertRedirect();
        $this->actingAs($billing)->post('/support', ['organisation' => $a->uuid, 'category' => 'billing', 'subject' => 'GST number', 'message' => 'Please update our GST number.'])
            ->assertRedirect();
        $this->actingAs($manager)->post('/support', ['organisation' => $b->uuid, 'category' => 'email', 'subject' => 'Hijack', 'message' => 'Not my organisation at all.'])
            ->assertNotFound();

        $item = SupportRequest::query()->where('subject', 'Cannot sign in')->firstOrFail();
        $this->assertContains('New support request', $admin->fresh()->notifications()->pluck('data')->pluck('title')->all());

        $this->actingAs($outsider)->get('/support')->assertOk()->assertDontSee('Cannot sign in');
        $this->get('/support/'.$item->uuid)->assertNotFound();
        $this->actingAs($manager)->put('/support/'.$item->uuid, ['status' => 'closed'])->assertForbidden();

        $this->actingAs($admin)->get('/support')->assertOk()->assertSee('Cannot sign in')->assertSee('GST number');
        $this->put('/support/'.$item->uuid, ['status' => 'resolved', 'response' => 'Password reset done.'])->assertSessionHas('success');

        $this->assertSame('resolved', $item->fresh()->status);
        $this->actingAs($manager)->get('/support/'.$item->uuid)->assertOk()->assertSee('Password reset done.');
        $this->assertContains('Support request updated', $manager->fresh()->notifications()->pluck('data')->pluck('title')->all());
        $this->assertDatabaseHas('audit_logs', ['action' => 'support.updated', 'user_id' => $admin->id]);
    }

    public function test_audit_log_screen_access_filters_and_export(): void
    {
        $organisation = Organisation::factory()->create(['name' => 'Audited Co']);
        $admin = $this->superAdmin();
        $ops = $this->operationsAdmin($organisation);
        $audit = app(AuditLogger::class);
        $audit->record('account.suspended', new: ['email' => 'x@audited.test'], organisation: $organisation, actor: $admin);
        $audit->record('pricing.organisation_price_set', new: ['price_minor' => 100, 'access_token' => 'leak-me'], organisation: $organisation, actor: $admin);

        $this->actingAs($this->organisationManager($organisation))->get('/audit')->assertForbidden();

        $this->actingAs($ops)->get('/audit')->assertOk()->assertSee('account.suspended')->assertDontSee('pricing.organisation_price_set');

        $this->actingAs($admin)->get('/audit')->assertOk()->assertSee('account.suspended')->assertSee('pricing.organisation_price_set');
        $this->get('/audit?action=pricing')->assertOk()->assertDontSee('account.suspended');
        $this->get('/audit?organisation='.$organisation->uuid.'&status=success')->assertOk()->assertSee('Audited Co');

        $csv = $this->get('/audit/export?action=pricing')->assertOk()->streamedContent();
        $this->assertStringContainsString('pricing.organisation_price_set', $csv);
        $this->assertStringNotContainsString('leak-me', $csv);
        $this->assertStringNotContainsString('leak-me', $this->get('/audit')->getContent());
    }

    public function test_queue_monitoring_and_health(): void
    {
        $uuid = (string) Str::uuid();
        DB::table('failed_jobs')->insert(['uuid' => $uuid, 'connection' => 'database', 'queue' => 'google',
            'payload' => json_encode(['displayName' => 'App\\Modules\\GoogleWorkspace\\Jobs\\SuspendGoogleUserJob', 'job' => 'x', 'data' => []]),
            'exception' => "App\\Modules\\GoogleWorkspace\\Exceptions\\WorkspaceException: boom\n#0 trace", 'failed_at' => now()]);
        $organisation = Organisation::factory()->create();

        foreach ([$this->organisationManager($organisation), $this->billingManager($organisation)] as $user) {
            $this->actingAs($user)->get('/system/queues')->assertForbidden();
            $this->get('/system/health')->assertForbidden();
            $this->post('/system/queues/'.$uuid.'/retry')->assertForbidden();
        }

        $ops = $this->operationsAdmin($organisation);
        $this->actingAs($ops)->get('/system/queues')->assertOk()->assertSee('SuspendGoogleUserJob')->assertSee('boom')->assertDontSee('#0 trace');
        $this->get('/system/health')->assertOk()->assertSee('Database')->assertSee('Scheduler')->assertSee('Zoho Books');

        $this->delete('/system/queues/'.$uuid)->assertSessionHas('success');
        $this->assertDatabaseMissing('failed_jobs', ['uuid' => $uuid]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'queue.job_discarded', 'user_id' => $ops->id]);
        $this->post('/system/queues/'.$uuid.'/retry')->assertNotFound();
    }

    public function test_role_permissions_can_be_edited_except_super_admin(): void
    {
        $organisation = Organisation::factory()->create();
        $admin = $this->superAdmin();
        $ops = $this->operationsAdmin($organisation);
        $this->assertFalse($ops->hasAbility(Ability::AccountsSoftDelete, $organisation));

        $this->actingAs($this->organisationManager($organisation))->get('/settings')->assertForbidden();
        $this->actingAs($ops)->put('/settings/roles/operations_admin', ['permissions' => ['accounts.soft_delete']])->assertForbidden();

        $current = Role::findByName(RoleName::OperationsAdmin)->permissions()->pluck('name')->all();
        $this->actingAs($admin)->get('/settings')->assertOk()->assertSee('Operations Admin')->assertSee('Sensitive');
        $this->put('/settings/roles/operations_admin', ['permissions' => [...$current, 'accounts.soft_delete']])->assertSessionHas('success');

        $this->assertTrue($ops->fresh()->hasAbility(Ability::AccountsSoftDelete, $organisation));
        $this->assertDatabaseHas('audit_logs', ['action' => 'access.role_permissions_changed', 'user_id' => $admin->id]);

        $this->put('/settings/roles/super_admin', ['permissions' => []])->assertForbidden();
        $this->assertTrue($admin->fresh()->hasAbility(Ability::SettingsManage));
        $this->put('/settings/roles/operations_admin', ['permissions' => ['not.a.permission']])->assertSessionHasErrors('permissions.0');
    }

    public function test_dashboards_show_real_scoped_figures(): void
    {
        [$a, $b] = Organisation::factory()->count(2)->create();
        $manager = $this->organisationManager($a);
        $billing = $this->billingManager($a);

        $this->actingAs($manager)->get('/dashboard')->assertOk()->assertSee('Email accounts')->assertSee('Monthly subscription')->assertSee('Recent account activity');
        $this->actingAs($billing)->get('/dashboard')->assertOk()->assertSee('Monthly subscription')->assertSee('Current billing')
            ->assertDontSee('Email accounts')->assertDontSee('Recent account activity');

        $this->actingAs($this->superAdmin())->get('/dashboard')->assertOk()
            ->assertSee('Monthly revenue')->assertSee('Account growth')->assertSee('Account lifecycle')->assertSee('Payment collection');

        $this->actingAs(User::factory()->create())->get('/dashboard')->assertOk()->assertSee('No organisation assigned');
    }

    public function test_content_security_policy_uses_a_per_request_nonce(): void
    {
        $this->actingAs($this->superAdmin());
        $first = $this->get('/dashboard')->assertOk();
        $policy = $first->headers->get('Content-Security-Policy');

        preg_match("/'nonce-([^']+)'/", $policy, $match);
        $this->assertNotEmpty($match[1] ?? null);
        $this->assertStringContainsString("frame-ancestors 'none'", $policy);
        $this->assertStringContainsString("object-src 'none'", $policy);
        $this->assertStringNotContainsString("script-src 'self' 'unsafe-inline'", $policy);
        $this->assertStringContainsString('nonce="'.$match[1].'"', $first->getContent());
        $this->assertDoesNotMatchRegularExpression('/\son(click|change|submit)=/', $first->getContent());

        $second = $this->get('/dashboard')->headers->get('Content-Security-Policy');
        $this->assertNotSame($policy, $second);
    }
}
