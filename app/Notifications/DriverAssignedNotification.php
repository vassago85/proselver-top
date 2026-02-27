<?php

namespace App\Notifications;

use App\Models\Job;
use App\Models\NotificationLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DriverAssignedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Job $job) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject("Job Assigned: {$this->job->job_number}")
            ->greeting("You've been assigned a new job")
            ->line("Job: {$this->job->job_number}")
            ->line("Date: {$this->job->scheduled_date->format('d M Y')}")
            ->action('View Job', url("/driver/jobs/{$this->job->id}"))
            ->salutation('Proselver TOP');

        NotificationLog::create([
            'to_email' => $notifiable->email ?? $notifiable->username,
            'to_user_id' => $notifiable->id,
            'subject' => "Job Assigned: {$this->job->job_number}",
            'channel' => 'email',
            'template' => 'driver_assigned',
            'entity_type' => 'job',
            'entity_id' => $this->job->id,
            'sent_at' => now(),
            'status' => 'sent',
        ]);

        return $message;
    }

    public function toArray(object $notifiable): array
    {
        return [
            'job_id' => $this->job->id,
            'job_number' => $this->job->job_number,
            'message' => "You've been assigned job #{$this->job->job_number}.",
        ];
    }
}
