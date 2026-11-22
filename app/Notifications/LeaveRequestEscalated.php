<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\LeaveRequest;
use App\Models\User;

/** To each active hr_admin (except the requester) the first time a pending request is escalated. */
final class LeaveRequestEscalated extends LeaveRequestNotification
{
    public function __construct(LeaveRequest $leaveRequest, public int $pendingDays)
    {
        parent::__construct($leaveRequest);
    }

    protected function content(User $notifiable): array
    {
        return [
            'subject' => "Leave request pending for {$this->pendingDays} days",
            'lines' => [$this->summary()],
            'path' => '/leave-requests/'.$this->leaveRequest->public_id,
            'action' => 'Open request',
        ];
    }
}
