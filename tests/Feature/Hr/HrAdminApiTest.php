<?php

declare(strict_types=1);

namespace Tests\Feature\Hr;

use App\Enums\LedgerKind;
use App\Models\Department;
use App\Models\Holiday;
use App\Models\LeaveLedgerEntry;
use App\Models\LeaveType;
use App\Models\User;
use App\Notifications\LeaveRequestDecided;
use App\Services\AccrualCalculator;
use App\Support\LeaveYear;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Feature\Leave\LeaveTestHelpers;
use Tests\Support\ValidatesOpenApi;
use Tests\TestCase;

/** Phase 13 happy paths: UC-H1–H5, UC-H7–H9, UC-S1, UC-S2. Full matrix is QA phase 17. */
final class HrAdminApiTest extends TestCase
{
    use LeaveTestHelpers, RefreshDatabase, ValidatesOpenApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLeave();
    }

    private function spec(TestResponse $response, string $path, string $method = 'get'): TestResponse
    {
        if ($response->baseResponse instanceof StreamedResponse) {
            $response = TestResponse::fromBaseResponse(new Response(
                $response->streamedContent(),
                $response->getStatusCode(),
                $response->headers->all(),
            ));
        }
        $this->assertMatchesSpec($response, $path, $method);

        return $response;
    }

    private function problem(TestResponse $response, int $status, string $code, string $path, string $method = 'get'): void
    {
        $response->assertStatus($status)->assertJsonPath('code', $code);
        $this->assertMatchesSpec($response, $path, $method);
    }

    public function test_department_crud_and_delete_blocked_when_users_exist(): void
    {
        $created = $this->spec($this->api($this->carol)->postJson('/api/v1/departments', [
            'code' => 'FIN', 'name' => 'Finance',
        ]), '/departments', 'post')->assertCreated()
            ->assertJsonPath('data.code', 'FIN')
            ->assertJsonPath('data.users_count', 0)
            ->assertHeader('Location');
        $id = $created->json('data.id');
        $department = Department::query()->where('code', 'FIN')->firstOrFail();
        $this->assertSame($department->public_id, $id);

        $this->spec($this->api($this->alice)->getJson('/api/v1/departments'), '/departments')
            ->assertOk()->assertJsonPath('data.0.id', $id);
        $this->spec($this->api($this->alice)->getJson("/api/v1/departments/{$id}"), '/departments/{departmentId}')
            ->assertOk()->assertJsonPath('data.name', 'Finance');

        $this->spec($this->api($this->carol)->patchJson("/api/v1/departments/{$id}", [
            'code' => 'FIN', 'name' => 'Finance Ops',
        ]), '/departments/{departmentId}', 'patch')->assertOk()->assertJsonPath('data.name', 'Finance Ops');

        $this->spec($this->api($this->carol)->deleteJson("/api/v1/departments/{$id}"), '/departments/{departmentId}', 'delete')
            ->assertNoContent();
        $this->assertModelMissing($department);

        $again = $this->api($this->carol)->postJson('/api/v1/departments', [
            'code' => 'FIN', 'name' => 'Finance',
        ])->assertCreated();
        $busyId = $again->json('data.id');
        $this->api($this->carol)->patchJson("/api/v1/users/{$this->alice->public_id}", [
            'department_id' => $busyId,
        ])->assertOk();

        $this->problem(
            $this->api($this->carol)->deleteJson("/api/v1/departments/{$busyId}"),
            409, 'DEPARTMENT_NOT_EMPTY', '/departments/{departmentId}', 'delete',
        );
        $this->assertNotNull(Department::query()->where('public_id', $busyId)->first());
        $this->spec($this->api($this->carol)->getJson("/api/v1/departments/{$busyId}"), '/departments/{departmentId}')
            ->assertJsonPath('data.users_count', 1);
    }

    public function test_leave_type_create_and_code_is_immutable(): void
    {
        $created = $this->spec($this->api($this->carol)->postJson('/api/v1/leave-types', [
            'code' => 'COMP', 'name' => 'Compensatory', 'annual_quota' => 5, 'tracks_balance' => true,
        ]), '/leave-types', 'post')->assertCreated()
            ->assertJsonPath('data.code', 'COMP')
            ->assertJsonPath('data.is_active', true)
            ->assertHeader('Location');
        $id = $created->json('data.id');
        $this->assertSame(LeaveType::query()->where('code', 'COMP')->value('public_id'), $id);

        $this->spec($this->api($this->carol)->getJson("/api/v1/leave-types/{$id}"), '/leave-types/{leaveTypeId}')
            ->assertOk()->assertJsonPath('data.annual_quota', 5);

        $this->problem(
            $this->api($this->carol)->patchJson("/api/v1/leave-types/{$id}", ['code' => 'NOPE']),
            422, 'VALIDATION_FAILED', '/leave-types/{leaveTypeId}', 'patch',
        );
        $this->assertSame('COMP', LeaveType::query()->where('public_id', $id)->value('code'));

        $this->spec($this->api($this->carol)->patchJson("/api/v1/leave-types/{$id}", [
            'name' => 'Compensatory Off',
        ]), '/leave-types/{leaveTypeId}', 'patch')->assertOk()
            ->assertJsonPath('data.code', 'COMP')
            ->assertJsonPath('data.name', 'Compensatory Off');
    }

    public function test_holiday_crud_is_behind_the_holidays_module(): void
    {
        $created = $this->spec($this->api($this->carol)->postJson('/api/v1/holidays', [
            'date' => '2026-08-15', 'name' => 'Independence Day',
        ]), '/holidays', 'post')->assertCreated()->assertHeader('Location');
        $id = $created->json('data.id');
        $this->assertSame(Holiday::query()->where('date', '2026-08-15')->value('public_id'), $id);

        $this->spec($this->api($this->alice)->getJson('/api/v1/holidays?filter[year]=2026'), '/holidays')
            ->assertOk()->assertJsonPath('data.0.id', $id);
        $this->spec($this->api($this->alice)->getJson("/api/v1/holidays/{$id}"), '/holidays/{holidayId}')
            ->assertOk()->assertJsonPath('data.date', '2026-08-15');
        $this->spec($this->api($this->carol)->patchJson("/api/v1/holidays/{$id}", [
            'date' => '2026-08-15', 'name' => 'Independence Day (observed)',
        ]), '/holidays/{holidayId}', 'patch')->assertOk()
            ->assertJsonPath('data.name', 'Independence Day (observed)');
        $this->spec($this->api($this->carol)->deleteJson("/api/v1/holidays/{$id}"), '/holidays/{holidayId}', 'delete')
            ->assertNoContent();

        $this->setting('modules.disabled', ['holidays']);
        $this->problem($this->api($this->carol)->getJson('/api/v1/holidays'), 404, 'NOT_FOUND', '/holidays');
        $this->problem($this->api($this->carol)->postJson('/api/v1/holidays', [
            'date' => '2026-10-02', 'name' => 'Gandhi Jayanti',
        ]), 404, 'NOT_FOUND', '/holidays', 'post');
    }

    public function test_hr_patches_lms_fields_deactivates_and_rejects_role(): void
    {
        $user = User::factory()->create(['date_of_joining' => null, 'name' => 'Eve New']);
        $department = Department::factory()->create(['code' => 'OPS', 'name' => 'Operations']);

        $updated = $this->spec($this->api($this->carol)->patchJson("/api/v1/users/{$user->public_id}", [
            'department_id' => $department->public_id,
            'manager_id' => $this->bob->public_id,
            'date_of_joining' => '2026-06-15',
            'employee_code' => 'E-1301',
            'designation' => 'Analyst',
            'employment_type' => 'full_time',
        ]), '/users/{userId}', 'patch')->assertOk()
            ->assertJsonPath('data.id', $user->public_id)
            ->assertJsonPath('data.department.id', $department->public_id)
            ->assertJsonPath('data.manager.id', $this->bob->public_id)
            ->assertJsonPath('data.date_of_joining', '2026-06-15')
            ->assertJsonPath('data.employee_code', 'E-1301')
            ->assertJsonPath('data.designation', 'Analyst')
            ->assertJsonPath('data.employment_type', 'full_time')
            ->assertJsonPath('data.role', 'employee');

        $this->assertSame($user->public_id, $updated->json('data.id'));
        $expected = AccrualCalculator::prorate(
            (float) $this->type('CL')->annual_quota,
            6,
            (int) config('lms.leave_year_start_month'),
        );
        $this->assertSame($expected, (float) LeaveLedgerEntry::query()->where([
            'user_id' => $user->id, 'leave_type_id' => $this->type('CL')->id, 'kind' => LedgerKind::Grant,
        ])->value('days'));

        $this->problem(
            $this->api($this->carol)->patchJson("/api/v1/users/{$user->public_id}", ['role' => 'manager']),
            422, 'ROLE_READ_ONLY', '/users/{userId}', 'patch',
        );
        $this->assertSame('employee', $user->refresh()->role->value);

        $this->spec($this->api($this->carol)->postJson("/api/v1/users/{$user->public_id}/deactivate"), '/users/{userId}/deactivate', 'post')
            ->assertOk()->assertJsonPath('data.is_active', false);
        $this->assertFalse($user->refresh()->is_active);

        $this->spec($this->api($this->carol)->postJson("/api/v1/users/{$user->public_id}/activate"), '/users/{userId}/activate', 'post')
            ->assertOk()->assertJsonPath('data.is_active', true);
        $this->assertTrue($user->refresh()->is_active);
    }

    public function test_balance_adjustment_appends_a_ledger_row(): void
    {
        $created = $this->spec($this->api($this->carol)->postJson("/api/v1/users/{$this->alice->public_id}/ledger-entries", [
            'leave_type_id' => $this->type('EL')->public_id,
            'days' => 2,
            'note' => 'Bonus credit',
        ]), '/users/{userId}/ledger-entries', 'post')->assertCreated()
            ->assertJsonPath('data.kind', 'adjustment')
            ->assertJsonPath('data.days', 2)
            ->assertJsonPath('data.note', 'Bonus credit')
            ->assertJsonPath('data.actor.id', $this->carol->public_id)
            ->assertJsonPath('data.leave_request_id', null)
            ->assertHeader('Location');
        $this->assertStringContainsString(
            '/api/v1/users/'.$this->alice->public_id.'/ledger-entries',
            (string) $created->headers->get('Location'),
        );
        $this->assertSame(2.0, $this->ledgerSum($this->alice, 'EL', LedgerKind::Adjustment));

        $list = $this->spec(
            $this->api($this->carol)->getJson("/api/v1/users/{$this->alice->public_id}/ledger-entries"),
            '/users/{userId}/ledger-entries',
        )->assertOk();
        $kinds = collect($list->json('data'))->pluck('kind');
        $this->assertSame('grant', $kinds->first());
        $this->assertSame('adjustment', $kinds->last());
    }

    public function test_reports_json_and_csv_for_hr_and_forbidden_for_employee(): void
    {
        $requestId = $this->apply($this->alice)->assertCreated()->json('data.id');

        $json = $this->spec($this->api($this->carol)->getJson('/api/v1/reports/leave-requests'), '/reports/leave-requests')
            ->assertOk()
            ->assertJsonPath('data.0.request_id', $requestId)
            ->assertJsonPath('data.0.employee', 'Alice Employee')
            ->assertJsonPath('data.0.leave_type', 'EL')
            ->assertJsonPath('data.0.status', 'pending')
            ->assertJsonPath('meta.current_page', 1);
        $this->assertSame(5, $json->json('data.0.days'));

        $csv = $this->api($this->carol)->get('/api/v1/reports/leave-requests', ['Accept' => 'text/csv']);
        $body = $this->spec($csv, '/reports/leave-requests')->assertOk()->getContent();
        $this->assertStringStartsWith('request_id,employee_code,employee,department,leave_type,start_date,end_date,day_part,days,status,decided_by,decided_at', $body);
        $this->assertStringContainsString($requestId, $body);
        $this->assertStringContainsString('leave-requests-2026-11-16.csv', (string) $csv->headers->get('content-disposition'));

        $balances = $this->spec($this->api($this->carol)->getJson('/api/v1/reports/balances'), '/reports/balances')->assertOk();
        $el = collect($balances->json('data'))->first(fn (array $row): bool => $row['employee'] === 'Alice Employee' && $row['leave_type'] === 'EL');
        $this->assertNotNull($el);
        $this->assertSame(LeaveYear::current(), $el['year']);
        $this->assertEquals(30, $el['entitled']);
        $this->assertEquals(5, $el['pending']);

        $balanceCsv = $this->api($this->carol)->get('/api/v1/reports/balances', ['Accept' => 'text/csv']);
        $balanceBody = $this->spec($balanceCsv, '/reports/balances')->assertOk()->getContent();
        $this->assertStringStartsWith('employee_code,employee,department,leave_type,year,entitled,used,pending,balance,available', $balanceBody);
        $this->assertStringContainsString('Alice Employee', $balanceBody);

        $this->problem($this->api($this->alice)->getJson('/api/v1/reports/leave-requests'), 403, 'FORBIDDEN', '/reports/leave-requests');
        $this->problem($this->api($this->alice)->getJson('/api/v1/reports/balances'), 403, 'FORBIDDEN', '/reports/balances');
    }

    public function test_accrual_command_is_scheduled_and_rerun_writes_no_rows(): void
    {
        $month = (int) config('lms.leave_year_start_month');
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains((string) $event->command, 'lms:accrue-year'));
        $this->assertNotNull($event);
        $this->assertSame("10 0 1 {$month} *", $event->expression);
        $this->assertTrue($event->onOneServer);
        $this->assertTrue($event->withoutOverlapping);

        $this->artisan('lms:accrue-year', ['year' => 2027])->assertSuccessful();
        $count = LeaveLedgerEntry::query()->count();
        $this->assertGreaterThan(0, LeaveLedgerEntry::query()->where(['year' => 2027, 'kind' => LedgerKind::Grant])->count());

        $this->artisan('lms:accrue-year', ['year' => 2027])
            ->expectsOutputToContain('0 ledger rows written')
            ->assertSuccessful();
        $this->assertSame($count, LeaveLedgerEntry::query()->count());
    }

    public function test_decision_mail_links_to_the_next_leave_request_path(): void
    {
        Notification::fake();
        $id = $this->apply($this->alice)->assertCreated()->json('data.id');
        $this->api($this->bob)->postJson("/api/v1/leave-requests/{$id}/approve", ['comment' => 'Enjoy'])->assertOk();

        Notification::assertSentTo($this->alice, LeaveRequestDecided::class, function (LeaveRequestDecided $notification) use ($id): bool {
            $mail = $notification->toMail($this->alice);
            $expected = rtrim((string) config('app.url'), '/').'/leave-requests/'.$id;
            $this->assertSame($id, $notification->leaveRequest->public_id);
            $this->assertSame($expected, $mail->viewData['url']);
            $this->assertStringContainsString('href="'.$expected.'"', view('mail.leave-request', $mail->viewData)->render());
            $this->assertStringContainsString($expected, view('mail.leave-request-text', $mail->viewData)->render());

            return true;
        });
    }
}
