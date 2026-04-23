<?php
/*
 * Cancellation Permissions
 *
 * The owner (and super_admin / developer) decides which internal role
 * slugs may cancel a confirmed order. The list is persisted in
 * system_settings under the key `order_cancel_allowed_roles` as a JSON
 * array of role slugs, and is read by JobPolicy::cancel().
 *
 * Two roles (developer, super_admin, owner) are *always* allowed in the
 * policy regardless of what's saved here. That's a deliberate safety
 * floor so an accidental empty save cannot lock the org out of
 * cancellations entirely.
 *
 * Customer/dealer self-cancellation (while the movement is still at the
 * depot) is governed by business rules inside the policy and is *not*
 * affected by this screen — only internal-tier cancellation is.
 */

use App\Models\Role;
use App\Models\SystemSetting;
use App\Services\AuditService;
use Livewire\Volt\Component;
use Livewire\Attributes\Layout;

new #[Layout('components.layouts.app')] class extends Component {
    /** @var array<int,string> role slugs the owner has explicitly enabled */
    public array $selected = [];

    public function mount(): void
    {
        $user = auth()->user();
        abort_unless(
            $user?->isDeveloper() || $user?->isSuperAdmin() || $user?->isOwner(),
            403,
            'Only the owner or a platform admin may manage cancellation permissions.'
        );

        $stored = SystemSetting::get('order_cancel_allowed_roles', null);
        $this->selected = is_array($stored)
            ? array_values(array_map('strval', $stored))
            : ['developer', 'super_admin', 'owner', 'ops_manager', 'operations_controller'];
    }

    public function save(): void
    {
        $user = auth()->user();
        abort_unless(
            $user?->isDeveloper() || $user?->isSuperAdmin() || $user?->isOwner(),
            403
        );

        // Normalise: keep only known internal-tier role slugs.
        $validSlugs = Role::where('tier', 'internal')->pluck('slug')->all();
        $clean = array_values(array_intersect($this->selected, $validSlugs));

        SystemSetting::set(
            'order_cancel_allowed_roles',
            $clean,
            'json',
            'Internal role slugs that may cancel confirmed orders. Owner/super_admin/developer are always allowed regardless.'
        );

        AuditService::log('cancellation_permissions_updated', 'system_setting', 0, null, [
            'allowed_roles' => $clean,
        ]);

        session()->flash('success', 'Cancellation permissions updated.');
    }

    public function with(): array
    {
        // Only internal roles are meaningful here — external (customer /
        // dealer / oem) cancellation is governed by a separate business
        // rule inside JobPolicy and doesn't belong on this form.
        $roles = Role::where('tier', 'internal')
            ->orderBy('name')
            ->get(['slug', 'name', 'description']);

        return ['roles' => $roles];
    }
};
?>

<div>
    <x-slot:header>
        <div class="flex items-center gap-3">
            <a href="{{ route('admin.settings.index') }}" class="text-gray-400 hover:text-gray-600">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
            </a>
            <span>Cancellation Permissions</span>
        </div>
    </x-slot:header>

    @if(session('success'))
        <div class="mb-4 rounded-lg bg-green-50 border border-green-200 p-4 text-sm text-green-700">{{ session('success') }}</div>
    @endif

    <div class="max-w-3xl space-y-6">

        {{-- Context / explainer card --}}
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <div class="flex items-start gap-3">
                <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-red-50 text-red-600 shrink-0">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg>
                </div>
                <div class="min-w-0">
                    <h3 class="text-base font-semibold text-gray-900">Who can cancel an order?</h3>
                    <p class="mt-1 text-sm text-gray-600 leading-relaxed">
                        Tick the internal roles that are trusted to cancel a confirmed order. Everyone else
                        sees a grey "Cancellation requires authorisation" notice in place of the cancel button
                        on the order page.
                    </p>
                    <ul class="mt-3 space-y-1.5 text-xs text-gray-500 list-disc pl-5">
                        <li><strong class="text-gray-700">Owner</strong>, <strong class="text-gray-700">Super Admin</strong> and <strong class="text-gray-700">Developer</strong> are always allowed — they cannot be unchecked. This is a safety floor so nobody can lock the business out of cancelling.</li>
                        <li>External users (customers / dealers) can still self-cancel their own bookings until the vehicle leaves the depot. That's a business rule and is not controlled here.</li>
                        <li>Cancellations are always captured in the audit log with the role that performed them.</li>
                    </ul>
                </div>
            </div>
        </div>

        {{-- Role picker --}}
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <h3 class="text-sm font-semibold text-gray-900 mb-3">Internal roles allowed to cancel</h3>
            <div class="divide-y divide-gray-100">
                @foreach($roles as $role)
                    @php
                        $isFloor = in_array($role->slug, ['developer', 'super_admin', 'owner'], true);
                    @endphp
                    <label class="flex items-start gap-3 py-3 cursor-pointer">
                        <input type="checkbox"
                               wire:model="selected"
                               value="{{ $role->slug }}"
                               @if($isFloor) checked disabled @endif
                               class="mt-1 h-4 w-4 rounded border-gray-300 text-red-600 focus:ring-red-500 disabled:opacity-60">
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <span class="text-sm font-medium text-gray-900">{{ $role->name }}</span>
                                <span class="text-[11px] font-mono text-gray-400">{{ $role->slug }}</span>
                                @if($isFloor)
                                    <span class="text-[10px] uppercase tracking-wide rounded-full bg-gray-100 text-gray-600 border border-gray-200 px-1.5 py-0.5">always allowed</span>
                                @endif
                            </div>
                            @if($role->description)
                                <p class="text-xs text-gray-500 mt-0.5">{{ $role->description }}</p>
                            @endif
                        </div>
                    </label>
                @endforeach
            </div>

            <div class="mt-5 flex items-center justify-end gap-3 border-t border-gray-100 pt-4">
                <a href="{{ route('admin.settings.index') }}"
                   class="rounded-lg px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100">
                    Back to settings
                </a>
                <button wire:click="save"
                        class="rounded-lg bg-red-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-red-500 transition-colors">
                    <span wire:loading.remove wire:target="save">Save permissions</span>
                    <span wire:loading wire:target="save">Saving…</span>
                </button>
            </div>
        </div>
    </div>
</div>
