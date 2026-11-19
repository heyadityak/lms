<?php

declare(strict_types=1);

namespace App\Actions\Leave;

use App\Enums\LeaveAction;
use App\Enums\LeaveStatus;
use App\Enums\Module;
use App\Exceptions\InvalidTransition;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Notifications\LeaveRequestNotification;
use App\Support\Modules;
use Illuminate\Support\Facades\Notification;

/** Shared steps of approve/reject/cancel. Call inside DB::transaction. */
final class LeaveTransition
{
    /** Row lock (concurrent approve/cancel) + state machine check on the fresh row: anything invalid → 409. */
    public static function lock(LeaveRequest $request, LeaveStatus $to): LeaveRequest
    {
        $locked = LeaveRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
        if (! $locked->status->canTransitionTo($to)) {
            throw new InvalidTransition("Request is no longer pending (current status {$locked->status->value}).");
        }

        return $locked;
    }

    /** @param  array<string, mixed>  $attributes */
    public static function apply(LeaveRequest $locked, LeaveStatus $to, LeaveAction $action, User $actor, ?string $comment, array $attributes): LeaveRequest
    {
        $from = $locked->status;
        $locked->forceFill(['status' => $to] + $attributes)->save();
        $locked->actions()->create([
            'actor_id' => $actor->id, 'action' => $action, 'from_status' => $from, 'to_status' => $to,
            'comment' => $comment === '' ? null : $comment,
        ]);

        return $locked;
    }

    /**
     * UC-S2: queue a leave mail to the active recipients, only with module `notifications` on.
     * The notification is afterCommit, so a rolled-back transition sends nothing.
     *
     * @param  iterable<User>  $recipients
     */
    public static function notify(iterable $recipients, LeaveRequestNotification $notification): void
    {
        if (! app(Modules::class)->enabled(Module::Notifications)) {
            return;
        }
        Notification::send(collect($recipients)->filter(fn (User $u): bool => $u->is_active)->values(), $notification);
    }
}
