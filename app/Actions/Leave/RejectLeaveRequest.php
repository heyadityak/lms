<?php

declare(strict_types=1);

namespace App\Actions\Leave;

use App\Enums\LeaveAction;
use App\Enums\LeaveStatus;
use App\Exceptions\LeaveRuleViolation;
use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/** UC-M3 / UC-H6: pending → rejected, comment required. */
final class RejectLeaveRequest
{
    public function handle(LeaveRequest $request, User $actor, ?string $comment): LeaveRequest
    {
        $comment = trim((string) $comment);
        if (mb_strlen($comment) < 3) {
            throw new LeaveRuleViolation('COMMENT_REQUIRED', 'A comment of at least 3 characters is required to reject a request.', 'comment');
        }

        return DB::transaction(function () use ($request, $actor, $comment): LeaveRequest {
            $locked = LeaveTransition::lock($request, LeaveStatus::Rejected);
            if ($locked->user_id === $actor->id) {
                throw new AuthorizationException('You cannot reject your own leave request.');
            }

            return LeaveTransition::apply($locked, LeaveStatus::Rejected, LeaveAction::Rejected, $actor, $comment, [
                'decided_by' => $actor->id, 'decided_at' => now(), 'decision_comment' => $comment,
            ]);
        });
    }
}
