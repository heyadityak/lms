<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\LeaveRequest;
use App\Models\User;
use App\Support\Brand;
use App\Support\Settings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Shared shape of the leave mails (UC-S2): queued on the default (Redis) connection after the transaction commits.
 * Brand is resolved when the worker renders the mail (per job, D-27). Links = APP_URL + the Next.js path (D-43).
 */
abstract class LeaveRequestNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public LeaveRequest $leaveRequest)
    {
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /** @return array{subject: string, lines: list<string>, path: string, action: string} */
    abstract protected function content(User $notifiable): array;

    public function toMail(User $notifiable): MailMessage
    {
        $brand = Brand::fromSettings(app(Settings::class));
        $content = $this->content($notifiable);

        return (new MailMessage)
            ->from((string) config('mail.from.address'), $brand->name)
            ->subject("[{$brand->name}] {$content['subject']}")
            ->view(['mail.leave-request', 'mail.leave-request-text'], [
                'brand' => $brand,
                'name' => $notifiable->name,
                'lines' => $content['lines'],
                'url' => rtrim((string) config('app.url'), '/').$content['path'],
                'action' => $content['action'],
            ]);
    }

    protected function summary(): string
    {
        $r = $this->leaveRequest;

        return "{$r->user->name}: {$r->days} day(s) of {$r->leaveType->name} ({$r->leaveType->code}), {$r->start_date->toDateString()} to {$r->end_date->toDateString()}.";
    }
}
