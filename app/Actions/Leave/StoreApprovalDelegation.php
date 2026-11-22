<?php

declare(strict_types=1);

namespace App\Actions\Leave;

use App\Enums\Role;
use App\Exceptions\LeaveRuleViolation;
use App\Models\ApprovalDelegation;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** One open delegation window per delegator. The delegate must be an active manager or hr_admin. */
final class StoreApprovalDelegation
{
    /** @param  array{delegate_id: string, start_date: string, end_date: string}  $data */
    public function handle(User $delegator, array $data): ApprovalDelegation
    {
        $delegate = User::query()->where('public_id', $data['delegate_id'])->firstOrFail();
        if ($delegate->is($delegator)) {
            throw new LeaveRuleViolation('VALIDATION_FAILED', 'You cannot delegate approvals to yourself.', 'delegate_id');
        }
        if (! $delegate->is_active || ! in_array($delegate->role, [Role::Manager, Role::HrAdmin], true)) {
            throw new LeaveRuleViolation('VALIDATION_FAILED', 'The delegate must be an active manager or HR admin.', 'delegate_id');
        }

        if (ApprovalDelegation::query()->where('delegator_id', $delegator->id)->exists()) {
            throw new LeaveRuleViolation('VALIDATION_FAILED', 'You already have an approval delegation. Delete it first.', 'start_date');
        }

        try {
            return DB::transaction(fn (): ApprovalDelegation => ApprovalDelegation::query()->create([
                'delegator_id' => $delegator->id,
                'delegate_id' => $delegate->id,
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'],
            ]));
        } catch (QueryException $e) {
            if ($e->getCode() === '23505') {
                throw new LeaveRuleViolation('VALIDATION_FAILED', 'You already have an approval delegation. Delete it first.', 'start_date');
            }

            throw $e;
        }
    }
}
