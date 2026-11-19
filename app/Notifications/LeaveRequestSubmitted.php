<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\User;

/** To the resolved approver(s) when a request is submitted. */
final class LeaveRequestSubmitted extends LeaveRequestNotification
{
    protected function content(User $notifiable): array
    {
        return [
            'subject' => "Leave request from {$this->leaveRequest->user->name} awaits your decision",
            'lines' => [$this->summary(), "Reason: {$this->leaveRequest->reason}"],
            'path' => '/approvals',
            'action' => 'Open approvals',
        ];
    }
}
