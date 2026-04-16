<?php

namespace App\Notifications;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DriverExpiryNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected User $driver,
        protected string $expiryType,
        protected Carbon $expiryDate,
    ) {}

    public function via(): array
    {
        return ['mail', 'database'];
    }

    public function toMail(): MailMessage
    {
        $typeLabel = $this->expiryType === 'license' ? 'License' : 'PDP';
        $daysRemaining = now()->diffInDays($this->expiryDate, false);
        $status = $daysRemaining < 0 ? 'EXPIRED' : "{$daysRemaining} days remaining";

        return (new MailMessage)
            ->subject("Driver {$this->driver->name} - {$typeLabel} {$status}")
            ->greeting("Driver Document Expiry Alert")
            ->line("**Driver:** {$this->driver->name}")
            ->line("**Document:** {$typeLabel}")
            ->line("**Expiry Date:** {$this->expiryDate->format('d M Y')}")
            ->line("**Status:** {$status}")
            ->action('View Driver Profile', url("/admin/drivers/{$this->driver->id}/edit"))
            ->line('Please ensure the driver\'s documentation is renewed promptly.');
    }

    public function toArray(): array
    {
        return [
            'driver_id' => $this->driver->id,
            'driver_name' => $this->driver->name,
            'expiry_type' => $this->expiryType,
            'expiry_date' => $this->expiryDate->toDateString(),
        ];
    }
}
