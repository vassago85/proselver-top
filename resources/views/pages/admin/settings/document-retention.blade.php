<?php
/*
 * Document Retention settings
 *
 * Split by intent:
 *   - Photos (damage, vehicle, dashboard, data plate) are HARD-CODED to
 *     3 months. See App\Support\DocumentRetention::FIXED_PHOTO_MONTHS.
 *     Photos exist purely to resolve damage/missing-item disputes raised
 *     within that window. Leaving them longer increases storage cost and
 *     legal exposure (subject access / discovery) for no operational
 *     benefit, so this value is not owner-tunable by design.
 *
 *   - Formal paperwork (collection notes, PODs, purchase orders, invoices,
 *     slips) retention IS owner-tunable here. Default 60 months matches
 *     SA Income Tax Act s29 record-keeping.
 */

use App\Services\AuditService;
use App\Support\DocumentRetention;
use Livewire\Volt\Component;
use Livewire\Attributes\Layout;

new #[Layout('components.layouts.app')] class extends Component {
    public int $paperworkMonths = 60;

    public function mount(): void
    {
        $user = auth()->user();
        abort_unless(
            $user?->isDeveloper() || $user?->isSuperAdmin() || $user?->isOwner(),
            403,
            'Only the owner or a platform admin may manage document retention.'
        );

        $this->paperworkMonths = DocumentRetention::paperworkMonths();
    }

    public function save(): void
    {
        $user = auth()->user();
        abort_unless(
            $user?->isDeveloper() || $user?->isSuperAdmin() || $user?->isOwner(),
            403
        );

        $this->validate([
            'paperworkMonths' => 'required|integer|min:1|max:240',
        ]);

        DocumentRetention::setPaperworkMonths($this->paperworkMonths);

        AuditService::log('document_retention_updated', 'system_setting', 0, null, [
            'paperwork_months' => $this->paperworkMonths,
        ]);

        session()->flash('success', 'Document retention updated.');
    }

    public function with(): array
    {
        return [
            'photoMonths' => DocumentRetention::FIXED_PHOTO_MONTHS,
        ];
    }
};
?>

<div>
    <x-slot:header>
        <div class="flex items-center gap-3">
            <a href="{{ route('admin.settings.index') }}" class="text-gray-400 hover:text-gray-600">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
            </a>
            <span>Document Retention</span>
        </div>
    </x-slot:header>

    @if(session('success'))
        <div class="mb-4 rounded-lg bg-green-50 border border-green-200 p-4 text-sm text-green-700">{{ session('success') }}</div>
    @endif

    <div class="max-w-3xl space-y-6">

        {{-- Photos (fixed) --}}
        <div class="rounded-xl border border-gray-200 bg-white shadow-sm">
            <div class="border-b border-gray-100 px-6 py-4">
                <h2 class="text-base font-semibold text-gray-900">Vehicle photos</h2>
                <p class="text-sm text-gray-500 mt-0.5">Damage photos, dashboard, data plate, and general vehicle captures.</p>
            </div>
            <div class="px-6 py-5 flex items-center gap-4">
                <div class="flex h-12 w-12 items-center justify-center rounded-lg bg-amber-50 text-amber-600 shrink-0">
                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="3" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/></svg>
                </div>
                <div class="flex-1">
                    <p class="text-sm text-gray-700">
                        Photos are retained for <strong>{{ $photoMonths }} months</strong> from capture and then permanently deleted.
                    </p>
                    <p class="text-xs text-gray-500 mt-1">
                        This window covers the damage / missing-items dispute period. Kept deliberately short &mdash;
                        longer retention increases storage cost and legal exposure without operational benefit.
                        Dealers and customers are told to download anything they need to keep.
                    </p>
                </div>
                <span class="inline-flex items-center rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-600 shrink-0">Fixed</span>
            </div>
        </div>

        {{-- Paperwork (configurable) --}}
        <form wire:submit="save" class="rounded-xl border border-gray-200 bg-white shadow-sm">
            <div class="border-b border-gray-100 px-6 py-4">
                <h2 class="text-base font-semibold text-gray-900">Formal paperwork</h2>
                <p class="text-sm text-gray-500 mt-0.5">Collection notes, proofs of delivery, purchase orders, invoices, fuel / toll / parking slips.</p>
            </div>
            <div class="px-6 py-5 space-y-5">
                <div>
                    <label for="paperworkMonths" class="block text-sm font-medium text-gray-700">Keep for</label>
                    <div class="mt-1 flex items-center gap-3">
                        <input id="paperworkMonths"
                               type="number"
                               min="1"
                               max="240"
                               wire:model="paperworkMonths"
                               class="w-32 rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                        <span class="text-sm text-gray-600">months</span>
                        <span class="text-xs text-gray-400">(~{{ number_format($paperworkMonths / 12, 1) }} years)</span>
                    </div>
                    @error('paperworkMonths')
                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div class="rounded-md bg-blue-50 border border-blue-200 p-3 text-xs text-blue-800">
                    <strong>Tip.</strong> The SA Income Tax Act s29 requires most business records to be kept for
                    <strong>5 years (60 months)</strong>. Shorter values reduce storage cost; longer values help with
                    insurance / liability disputes that surface years after delivery.
                </div>

                <div class="flex items-center justify-end gap-3 border-t border-gray-100 pt-4">
                    <a href="{{ route('admin.settings.index') }}" class="text-sm text-gray-600 hover:text-gray-900">Cancel</a>
                    <button type="submit"
                            class="inline-flex items-center rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                        Save retention
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>
