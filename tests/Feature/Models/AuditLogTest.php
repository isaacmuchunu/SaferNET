<?php

namespace Tests\Feature\Models;

use App\Models\AuditLog;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use LogicException;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_audit_record_cannot_be_updated(): void
    {
        $auditLog = AuditLog::create(['event' => 'test.created']);

        $this->expectException(LogicException::class);

        $auditLog->update(['event' => 'test.changed']);
    }

    public function test_audit_record_cannot_be_deleted(): void
    {
        $auditLog = AuditLog::create(['event' => 'test.created']);

        $this->expectException(LogicException::class);

        $auditLog->delete();
    }
}
