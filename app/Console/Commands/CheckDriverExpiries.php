<?php

namespace App\Console\Commands;

use App\Models\DriverProfile;
use App\Models\NotificationLog;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use App\Notifications\DriverExpiryNotification;
use Illuminate\Console\Command;

class CheckDriverExpiries extends Command
{
    protected $signature = 'drivers:check-expiries';
    protected $description = 'Check for upcoming driver license and PDP expiries and notify staff';

    public function handle(): int
    {
        $licenseWarnMonths = (int) SystemSetting::get('driver_license_expiry_warn_months', 3);
        $pdpWarnMonths = (int) SystemSetting::get('driver_pdp_expiry_warn_months', 3);
        $notifyRoles = array_map('trim', explode(',', SystemSetting::get('driver_expiry_notify_roles', 'operations_controller,super_admin')));

        $recipients = User::whereHas('roles', fn($q) => $q->whereIn('slug', $notifyRoles))
            ->where('is_active', true)
            ->get();

        if ($recipients->isEmpty()) {
            $this->info('No notification recipients found.');
            return Command::SUCCESS;
        }

        $threshold = now()->addMonths(max($licenseWarnMonths, $pdpWarnMonths));
        $profiles = DriverProfile::with('user')
            ->where(function ($q) use ($threshold) {
                $q->where('license_expiry', '<=', $threshold)
                    ->orWhere('prdp_expiry', '<=', $threshold);
            })
            ->whereHas('user', fn($q) => $q->where('is_active', true))
            ->get();

        $sent = 0;

        foreach ($profiles as $profile) {
            if ($profile->license_expiry && $profile->license_expiry->lte(now()->addMonths($licenseWarnMonths))) {
                if ($this->shouldNotify($profile->user_id, 'license')) {
                    foreach ($recipients as $recipient) {
                        $recipient->notify(new DriverExpiryNotification($profile->user, 'license', $profile->license_expiry));
                    }
                    $this->logNotification($profile->user_id, 'license');
                    $sent++;
                    $this->line("  License warning: {$profile->user->name} - expires {$profile->license_expiry->format('Y-m-d')}");
                }
            }

            if ($profile->prdp_expiry && $profile->prdp_expiry->lte(now()->addMonths($pdpWarnMonths))) {
                if ($this->shouldNotify($profile->user_id, 'pdp')) {
                    foreach ($recipients as $recipient) {
                        $recipient->notify(new DriverExpiryNotification($profile->user, 'pdp', $profile->prdp_expiry));
                    }
                    $this->logNotification($profile->user_id, 'pdp');
                    $sent++;
                    $this->line("  PDP warning: {$profile->user->name} - expires {$profile->prdp_expiry->format('Y-m-d')}");
                }
            }
        }

        $this->info("Sent {$sent} expiry notification(s).");
        return Command::SUCCESS;
    }

    protected function shouldNotify(int $userId, string $type): bool
    {
        return !NotificationLog::where('entity_type', 'driver_expiry')
            ->where('entity_id', $userId)
            ->where('template', "driver_{$type}_expiry")
            ->where('sent_at', '>=', now()->subDays(7))
            ->exists();
    }

    protected function logNotification(int $userId, string $type): void
    {
        NotificationLog::create([
            'entity_type' => 'driver_expiry',
            'entity_id' => $userId,
            'template' => "driver_{$type}_expiry",
            'subject' => "Driver {$type} expiry warning",
            'channel' => 'mail',
            'sent_at' => now(),
            'status' => 'sent',
        ]);
    }
}
