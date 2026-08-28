<?php

namespace App\Notifications;

use App\Models\Job;
use App\Models\NotificationLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BookingCreatedNotification extends Notification implements ShouldQueue
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
            ->subject("New Order: {$this->job->job_number}")
            ->greeting("New order submitted")
            ->line("Job #{$this->job->job_number} requires PO verification.")
            ->line("Company: {$this->job->company->name}")
            ->line("Type: " . ($this->job->isTransport() ? 'Transport' : 'Yard Work'))
            ->action('View Order', route('admin.orders.show', $this->job))
            ->salutation('TRIDENT Control & Dispatch Center');

        // NB: template slug stays 'booking_created' -- it's an internal
        // reporting key, not user-visible copy, and renaming it would
        // orphan every existing NotificationLog row.
        NotificationLog::create([
            'to_email' => $notifiable->email ?? $notifiable->username,
            'to_user_id' => $notifiable->id,
            'subject' => "New Order: {$this->job->job_number}",
            'channel' => 'email',
            'template' => 'booking_created',
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
            'message' => "New order #{$this->job->job_number} needs verification.",
        ];
    }
}
