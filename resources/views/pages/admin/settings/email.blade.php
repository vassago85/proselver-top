<?php
use App\Models\SystemSetting;
use App\Providers\AppServiceProvider;
use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

new #[Layout('components.layouts.app')] class extends Component {
    public string $mailDriver = 'smtp';
    public string $fromName = '';
    public string $fromAddress = '';
    public string $smtpHost = '';
    public string $smtpPort = '587';
    public string $smtpUsername = '';
    public string $smtpPassword = '';
    public string $smtpEncryption = 'tls';
    public string $mailgunDomain = '';
    public string $mailgunSecret = '';
    public string $mailgunEndpoint = 'api.mailgun.net';
    public string $testEmailAddress = '';

    public function mount(): void
    {
        $this->mailDriver = (string) SystemSetting::get('mail_driver', config('mail.default', 'smtp'));
        $this->fromName = (string) SystemSetting::get('mail_from_name', config('mail.from.name', ''));
        $this->fromAddress = (string) SystemSetting::get('mail_from_address', config('mail.from.address', ''));
        $this->smtpHost = (string) SystemSetting::get('mail_smtp_host', config('mail.mailers.smtp.host', ''));
        $this->smtpPort = (string) SystemSetting::get('mail_smtp_port', config('mail.mailers.smtp.port', '587'));
        $this->smtpUsername = (string) SystemSetting::get('mail_smtp_username', config('mail.mailers.smtp.username', ''));
        $this->smtpPassword = (string) SystemSetting::get('mail_smtp_password', '');
        $this->smtpEncryption = (string) SystemSetting::get('mail_smtp_encryption', config('mail.mailers.smtp.encryption', 'tls'));
        $this->mailgunDomain = (string) SystemSetting::get('mail_mailgun_domain', config('services.mailgun.domain', ''));
        $this->mailgunSecret = (string) SystemSetting::get('mail_mailgun_secret', '');
        $this->mailgunEndpoint = (string) SystemSetting::get('mail_mailgun_endpoint', config('services.mailgun.endpoint', 'api.mailgun.net'));
    }

    public function save(): void
    {
        $this->validate([
            'mailDriver' => 'required|in:smtp,mailgun,log',
            'fromName' => 'required|string|max:255',
            'fromAddress' => 'required|email',
            'mailgunEndpoint' => 'nullable|string|max:255',
        ]);

        SystemSetting::set('mail_driver', $this->mailDriver);
        SystemSetting::set('mail_from_name', $this->fromName);
        SystemSetting::set('mail_from_address', $this->fromAddress);
        SystemSetting::set('mail_smtp_host', $this->smtpHost);
        SystemSetting::set('mail_smtp_port', $this->smtpPort);
        SystemSetting::set('mail_smtp_username', $this->smtpUsername);
        if ($this->smtpPassword) {
            SystemSetting::set('mail_smtp_password', $this->smtpPassword);
        }
        SystemSetting::set('mail_smtp_encryption', $this->smtpEncryption);
        SystemSetting::set('mail_mailgun_domain', $this->mailgunDomain);
        if ($this->mailgunSecret) {
            SystemSetting::set('mail_mailgun_secret', $this->mailgunSecret);
        }
        SystemSetting::set('mail_mailgun_endpoint', $this->mailgunEndpoint ?: 'api.mailgun.net');

        // Re-apply the saved values to the live container immediately so the
        // "Send Test" button below — which fires in the same browser session
        // but a new HTTP request — picks them up. (Boot-time hydration also
        // runs every request, this is just defensive in case the user clicks
        // Send Test before any cache invalidation propagates.)
        AppServiceProvider::hydrateMailConfigFromDatabase();

        session()->flash('success', 'Email settings saved.');
    }

    public function sendTestEmail(): void
    {
        $this->validate(['testEmailAddress' => 'required|email']);

        // Pull the latest values into config one more time. Cheap, idempotent,
        // and rules out "stale config" as a cause when diagnosing failures.
        AppServiceProvider::hydrateMailConfigFromDatabase();

        $driver = config('mail.default');

        // Surface configuration mistakes up front rather than letting the
        // transport throw a confusing low-level exception.
        if ($driver === 'mailgun') {
            if (!config('services.mailgun.domain') || !config('services.mailgun.secret')) {
                session()->flash('error', 'Mailgun domain or API secret is missing. Save the Mailgun settings before sending a test email.');
                return;
            }
        }

        try {
            Mail::raw('This is a test email from TRIDENT Control & Dispatch Center.', function ($message) {
                $message->to($this->testEmailAddress)
                    ->subject('Test Email - TRIDENT');
            });

            $note = $driver === 'log'
                ? ' (driver is set to "log" — check storage/logs/laravel.log; nothing was actually delivered)'
                : '';

            session()->flash('success', "Test email sent to {$this->testEmailAddress} via {$driver}." . $note);
        } catch (\Throwable $e) {
            // Full stack goes to the log; the UI gets a single-line summary so
            // operators can still copy/paste it into a support ticket.
            Log::error('Test email send failed', [
                'driver' => $driver,
                'to' => $this->testEmailAddress,
                'exception' => $e,
            ]);
            session()->flash('error', "Failed to send test email via {$driver}: {$e->getMessage()}");
        }
    }
};
?>
<div>
    <x-slot:header>Email Settings</x-slot:header>

    @if(session('success'))
        <div class="mb-4 rounded-lg bg-green-50 border border-green-200 p-4 text-sm text-green-700">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="mb-4 rounded-lg bg-red-50 border border-red-200 p-4 text-sm text-red-700">{{ session('error') }}</div>
    @endif

    <form wire:submit="save" class="max-w-2xl">
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">Mail Driver</h3>
            <div class="flex gap-4">
                @foreach(['smtp' => 'SMTP', 'mailgun' => 'Mailgun', 'log' => 'Log (Testing)'] as $val => $label)
                <label class="flex items-center gap-2 cursor-pointer">
                    <input wire:model.live="mailDriver" type="radio" value="{{ $val }}" class="h-4 w-4 text-blue-600">
                    <span class="text-sm font-medium">{{ $label }}</span>
                </label>
                @endforeach
            </div>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">From Address</h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">From Name *</label>
                    <input wire:model="fromName" type="text" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500" placeholder="TRIDENT Control &amp; Dispatch Center">
                    @error('fromName')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">From Address *</label>
                    <input wire:model="fromAddress" type="email" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500" placeholder="noreply@example.com">
                    @error('fromAddress')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
            </div>
        </div>

        @if($mailDriver === 'smtp')
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">SMTP Configuration</h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Host</label>
                    <input wire:model="smtpHost" type="text" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500" placeholder="smtp.mailgun.org">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Port</label>
                    <input wire:model="smtpPort" type="text" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500" placeholder="587">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Username</label>
                    <input wire:model="smtpUsername" type="text" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Password</label>
                    <input wire:model="smtpPassword" type="password" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500" placeholder="Leave blank to keep current">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Encryption</label>
                    <select wire:model="smtpEncryption" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm">
                        <option value="tls">TLS</option>
                        <option value="ssl">SSL</option>
                        <option value="">None</option>
                    </select>
                </div>
            </div>
        </div>
        @endif

        @if($mailDriver === 'mailgun')
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">Mailgun Configuration</h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Domain</label>
                    <input wire:model="mailgunDomain" type="text" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500" placeholder="mg.example.com">
                    <p class="mt-1 text-xs text-gray-500">Sending domain as it appears in your Mailgun dashboard.</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">API Secret</label>
                    <input wire:model="mailgunSecret" type="password" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500" placeholder="Leave blank to keep current">
                    <p class="mt-1 text-xs text-gray-500">Use the <strong>Sending API key</strong> (starts with <code>key-</code> or a long token), not the private API key.</p>
                </div>
                <div class="sm:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Region / API Endpoint</label>
                    <select wire:model="mailgunEndpoint" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm">
                        <option value="api.mailgun.net">US — api.mailgun.net</option>
                        <option value="api.eu.mailgun.net">EU — api.eu.mailgun.net</option>
                    </select>
                    <p class="mt-1 text-xs text-gray-500">A 401 / "domain not found" error from Mailgun almost always means the wrong region is selected.</p>
                </div>
            </div>
        </div>
        @endif

        <div class="flex justify-end gap-3 mb-8">
            <a href="{{ route('admin.settings.index') }}" class="rounded-lg border border-gray-300 bg-white px-6 py-3 text-sm font-semibold text-gray-700 hover:bg-gray-50">Back</a>
            <button type="submit" class="rounded-lg bg-blue-600 px-6 py-3 text-sm font-semibold text-white hover:bg-blue-500">Save Settings</button>
        </div>
    </form>

    <div class="max-w-2xl bg-white rounded-xl shadow-sm border border-gray-200 p-6">
        <h3 class="text-lg font-semibold text-gray-900 mb-4">Send Test Email</h3>
        <form wire:submit="sendTestEmail" class="flex gap-3">
            <input wire:model="testEmailAddress" type="email" placeholder="recipient@example.com" class="flex-1 rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500">
            <button type="submit" class="rounded-lg bg-green-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-green-500" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="sendTestEmail">Send Test</span>
                <span wire:loading wire:target="sendTestEmail">Sending...</span>
            </button>
        </form>
        @error('testEmailAddress')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>
</div>
