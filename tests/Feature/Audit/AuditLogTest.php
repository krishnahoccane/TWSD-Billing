<?php

namespace Tests\Feature\Audit;

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Organisations\Models\Organisation;
use LogicException;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    public function test_sensitive_values_are_redacted_at_any_depth(): void
    {
        $entry = app(AuditLogger::class)->record('test.redaction', new: [
            'name' => 'Visible',
            'password' => 'hunter2',
            'access_token' => 'abc',
            'nested' => ['refresh_token' => 'def', 'client_secret' => 'ghi', 'private_key' => 'jkl', 'note' => 'kept'],
        ]);

        $data = $entry->fresh()->new_data;

        $this->assertSame('Visible', $data['name']);
        $this->assertSame('kept', $data['nested']['note']);
        $this->assertSame('[redacted]', $data['password']);
        $this->assertSame('[redacted]', $data['access_token']);
        $this->assertSame('[redacted]', $data['nested']['refresh_token']);
        $this->assertSame('[redacted]', $data['nested']['client_secret']);
        $this->assertSame('[redacted]', $data['nested']['private_key']);
    }

    public function test_entry_captures_actor_role_organisation_and_request_id(): void
    {
        $organisation = Organisation::factory()->create();
        $manager = $this->organisationManager($organisation);

        $this->actingAs($manager)->get('/dashboard');

        $entry = app(AuditLogger::class)->record('test.context', $organisation, organisation: $organisation);

        $this->assertSame($manager->id, $entry->user_id);
        $this->assertSame($manager->email, $entry->user_email);
        $this->assertSame('Organisation Manager', $entry->user_role);
        $this->assertSame($organisation->id, $entry->organisation_id);
        $this->assertSame($organisation->uuid, $entry->resource_id);
        $this->assertNotEmpty($entry->request_id);
        $this->assertNotEmpty($entry->uuid);
    }

    public function test_audit_entries_cannot_be_updated(): void
    {
        $entry = app(AuditLogger::class)->record('test.immutable');

        $this->expectException(LogicException::class);

        $entry->update(['action' => 'tampered']);
    }

    public function test_audit_entries_cannot_be_deleted(): void
    {
        $entry = app(AuditLogger::class)->record('test.immutable');

        try {
            $entry->delete();
            $this->fail('Deleting an audit entry must be rejected.');
        } catch (LogicException) {
            $this->assertSame(1, AuditLog::query()->where('action', 'test.immutable')->count());
        }
    }
}
