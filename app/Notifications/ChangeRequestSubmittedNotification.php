<?php

namespace App\Notifications;

use App\Models\BookingChangeRequest;
use App\Models\NotificationLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ChangeRequestSubmittedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public BookingChangeRequest $changeRequest) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $job = $this->changeRequest->job;
        $requester = $this->changeRequest->requestedBy;
        $requested = $this->changeRequest->requested_value;

        return (new MailMessage)
            ->subject("Change Request: Job {$job->job_number}")
            ->greeting("New Change Request")
            ->line("{$requester->name} has requested a collection date change for Job {$job->job_number}.")
            ->line("Requested date: {$requested['date']} at {$requested['time']}")
            ->line("Reason: {$this->changeRequest->reason}")
            ->action('Review Request', url('/admin/change-requests'))
            ->salutation('Proselver TOP System');
    }

    public function toArray($notifiable): array
    {
        return [
            'job_id' => $this->changeRequest->job_id,
            'request_id' => $this->changeRequest->id,
            'type' => 'change_request_submitted',
        ];
    }
}
