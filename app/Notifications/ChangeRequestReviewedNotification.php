<?php

namespace App\Notifications;

use App\Models\BookingChangeRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ChangeRequestReviewedNotification extends Notification implements ShouldQueue
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
        $status = ucfirst($this->changeRequest->status);

        $mail = (new MailMessage)
            ->subject("Change Request {$status}: Job {$job->job_number}")
            ->greeting("Change Request {$status}")
            ->line("Your collection date change request for Job {$job->job_number} has been {$this->changeRequest->status}.");

        if ($this->changeRequest->status === 'approved') {
            $val = $this->changeRequest->requested_value;
            $mail->line("New collection date: {$val['date']} at {$val['time']}");
        }

        if ($this->changeRequest->review_notes) {
            $mail->line("Admin notes: {$this->changeRequest->review_notes}");
        }

        return $mail->salutation('Proselver TOP System');
    }

    public function toArray($notifiable): array
    {
        return [
            'job_id' => $this->changeRequest->job_id,
            'request_id' => $this->changeRequest->id,
            'type' => 'change_request_reviewed',
            'status' => $this->changeRequest->status,
        ];
    }
}
