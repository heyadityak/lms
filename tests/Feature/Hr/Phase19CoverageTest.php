<?php

declare(strict_types=1);

namespace Tests\Feature\Hr;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Leave\LeaveTestHelpers;
use Tests\Support\ValidatesOpenApi;
use Tests\TestCase;

/**
 * Phase 19 gaps. Department audit rows and the long approve-and-debit flow already
 * pass elsewhere; these tests cover the list envelope, a leave-type audit row,
 * location list/create, employee create denial, and a direct-report approval.
 */
final class Phase19CoverageTest extends TestCase
{
    use LeaveTestHelpers, RefreshDatabase, ValidatesOpenApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLeave();
    }

    public function test_hr_audit_log_lists_a_leave_type_write_and_an_employee_is_forbidden(): void
    {
        $typeId = $this->type('EL')->public_id;
        $this->api($this->carol)->patchJson('/api/v1/leave-types/'.$typeId, [
            'name' => 'Earned Leave (revised)',
        ])->assertOk();

        $page = $this->api($this->carol)->getJson('/api/v1/audit-logs')->assertOk();
        $this->assertMatchesSpec($page, '/audit-logs');
        $page->assertJsonStructure([
            'data',
            'meta' => ['current_page', 'per_page', 'total', 'last_page'],
            'links',
        ]);

        $row = collect($page->json('data'))->first(
            fn (array $entry): bool => $entry['subject_type'] === 'leave_type' && $entry['subject_id'] === $typeId,
        );
        $this->assertNotNull($row);
        $this->assertSame('updated', $row['action']);
        $this->assertSame($this->carol->public_id, $row['actor']['id']);

        $denied = $this->api($this->alice)->getJson('/api/v1/audit-logs')->assertForbidden()
            ->assertJsonPath('code', 'FORBIDDEN');
        $this->assertMatchesSpec($denied, '/audit-logs');
    }

    public function test_department_write_is_returned_by_the_audit_log(): void
    {
        $created = $this->api($this->carol)->postJson('/api/v1/departments', [
            'code' => 'AUD',
            'name' => 'Audit Fixture',
        ])->assertCreated();
        $id = $created->json('data.id');

        $page = $this->api($this->carol)->getJson('/api/v1/audit-logs')->assertOk();
        $this->assertMatchesSpec($page, '/audit-logs');
        $row = collect($page->json('data'))->first(
            fn (array $entry): bool => $entry['subject_type'] === 'department' && $entry['subject_id'] === $id,
        );
        $this->assertNotNull($row);
        $this->assertSame('created', $row['action']);
    }

    public function test_hr_lists_and_creates_departments_and_locations_an_employee_cannot_create(): void
    {
        $departments = $this->api($this->carol)->getJson('/api/v1/departments')->assertOk();
        $this->assertMatchesSpec($departments, '/departments');
        $departments->assertJsonStructure(['data', 'meta', 'links']);

        $created = $this->api($this->carol)->postJson('/api/v1/departments', [
            'code' => 'QA',
            'name' => 'Quality',
        ])->assertCreated()->assertJsonPath('data.code', 'QA');
        $this->assertMatchesSpec($created, '/departments', 'post');

        $listed = $this->api($this->carol)->getJson('/api/v1/departments')->assertOk();
        $this->assertMatchesSpec($listed, '/departments');
        $this->assertTrue(collect($listed->json('data'))->contains(
            fn (array $row): bool => $row['id'] === $created->json('data.id') && $row['name'] === 'Quality',
        ));

        $deniedDepartment = $this->api($this->alice)->postJson('/api/v1/departments', [
            'code' => 'NOPE',
            'name' => 'Not Allowed',
        ])->assertForbidden()->assertJsonPath('code', 'FORBIDDEN');
        $this->assertMatchesSpec($deniedDepartment, '/departments', 'post');

        $locations = $this->api($this->carol)->getJson('/api/v1/locations')->assertOk();
        $this->assertMatchesSpec($locations, '/locations');
        $locations->assertJsonStructure(['data', 'meta', 'links']);

        $location = $this->api($this->carol)->postJson('/api/v1/locations', [
            'code' => 'QA1',
            'name' => 'Quality Office',
        ])->assertCreated()->assertJsonPath('data.code', 'QA1');
        $this->assertMatchesSpec($location, '/locations', 'post');

        $listedLocations = $this->api($this->carol)->getJson('/api/v1/locations')->assertOk();
        $this->assertMatchesSpec($listedLocations, '/locations');
        $this->assertTrue(collect($listedLocations->json('data'))->contains(
            fn (array $row): bool => $row['id'] === $location->json('data.id') && $row['name'] === 'Quality Office',
        ));

        $deniedLocation = $this->api($this->alice)->postJson('/api/v1/locations', [
            'code' => 'NO',
            'name' => 'Nope',
        ])->assertForbidden()->assertJsonPath('code', 'FORBIDDEN');
        $this->assertMatchesSpec($deniedLocation, '/locations', 'post');
    }

    public function test_manager_lists_a_direct_reports_pending_request_and_approves_it(): void
    {
        $id = $this->apply($this->alice, [
            'start_date' => '2026-12-14',
            'end_date' => '2026-12-14',
        ])->assertCreated()->json('data.id');

        $inbox = $this->api($this->bob)->getJson('/api/v1/approvals')->assertOk();
        $this->assertMatchesSpec($inbox, '/approvals');
        $inbox->assertJsonStructure([
            'data',
            'meta' => ['current_page', 'per_page', 'total', 'last_page'],
            'links',
        ]);
        $row = collect($inbox->json('data'))->firstWhere('id', $id);
        $this->assertNotNull($row);
        $this->assertSame('pending', $row['status']);
        $this->assertSame($this->alice->public_id, $row['user']['id']);

        $approved = $this->api($this->bob)->postJson("/api/v1/leave-requests/{$id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.decided_by.id', $this->bob->public_id);
        $this->assertMatchesSpec($approved, '/leave-requests/{leaveRequestId}/approve', 'post');

        $this->api($this->bob)->getJson('/api/v1/approvals')->assertOk()->assertJsonMissing(['id' => $id]);
    }
}
