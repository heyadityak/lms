<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\User;

/** To the requester when their request is approved or rejected. */
final class LeaveRequestDecided extends LeaveRequestNotification
{
    protected function content(User $notifiable): array
    {
        $r = $this->leaveRequest;
        $lines = [$this->summary(), 'Decided by '.($r->decider?->name ?? 'HR').'.'];
        if ($r->decision_comment !== null) {
            $lines[] = "Comment: {$r->decision_comment}";
        }

        return [
            'subject' => "Your leave request was {$r->status->value}",
            'lines' => $lines,
            'path' => "/leave-requests/{$r->public_id}",
            'action' => 'View request',
        ];
    }
}
