<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\LeaveRequest;
use App\Models\User;

/** To the other party: approver(s) when the requester cancels, the requester when HR (or deactivation) cancels. */
final class LeaveRequestCancelled extends LeaveRequestNotification
{
    public function __construct(LeaveRequest $leaveRequest, public string $cancelledBy)
    {
        parent::__construct($leaveRequest);
    }

    protected function content(User $notifiable): array
    {
        return [
            'subject' => "Leave request of {$this->leaveRequest->user->name} was cancelled",
            'lines' => [$this->summary(), "Cancelled by {$this->cancelledBy}."],
            'path' => "/leave-requests/{$this->leaveRequest->public_id}",
            'action' => 'View request',
        ];
    }
}
