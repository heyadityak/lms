<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\User;
use App\Support\Brand;
use App\Support\Settings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * UC-S4 daily digest. Queued after the send-log transaction commits. The count is frozen at queue time.
 * Brand is resolved when the worker renders the mail (D-27). Link is APP_URL + /approvals (D-43).
 */
final class PendingApprovalsReminder extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $pendingCount)
    {
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $brand = Brand::fromSettings(app(Settings::class));
        $subject = $this->pendingCount === 1
            ? '1 leave request awaiting your decision'
            : "{$this->pendingCount} leave requests awaiting your decision";
        $line = $this->pendingCount === 1
            ? '1 leave request is waiting in your approvals inbox.'
            : "{$this->pendingCount} leave requests are waiting in your approvals inbox.";

        return (new MailMessage)
            ->from((string) config('mail.from.address'), $brand->name)
            ->subject("[{$brand->name}] {$subject}")
            ->view(['mail.leave-request', 'mail.leave-request-text'], [
                'brand' => $brand,
                'name' => $notifiable->name,
                'lines' => [$line],
                'url' => rtrim((string) config('app.url'), '/').'/approvals',
                'action' => 'Open approvals',
            ]);
    }
}
