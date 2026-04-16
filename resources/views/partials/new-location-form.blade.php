<div class="mt-3 rounded-lg border border-blue-200 bg-blue-50 p-4 space-y-3">
    <p class="text-sm font-semibold text-gray-900">New Location</p>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">Company / Location Name *</label>
            <input wire:model="newLocCompanyName" type="text" class="w-full rounded-md border border-gray-300 px-2.5 py-2 text-sm" placeholder="e.g. ABC Motors Sandton">
            @error('newLocCompanyName')<p class="mt-0.5 text-xs text-red-600">{{ $message }}</p>@enderror
        </div>
        <div x-data="placesAutocomplete({ addressModel: 'newLocAddress', cityModel: 'newLocCity', provinceModel: 'newLocProvince', latModel: 'newLocLat', lngModel: 'newLocLng' })">
            <label class="block text-xs font-medium text-gray-600 mb-1">Address *</label>
            <input x-ref="addressInput" wire:model="newLocAddress" type="text" autocomplete="off" class="w-full rounded-md border border-gray-300 px-2.5 py-2 text-sm" placeholder="Start typing to search...">
            @error('newLocAddress')<p class="mt-0.5 text-xs text-red-600">{{ $message }}</p>@enderror
            <button type="button" wire:click="lookupNewLocAddress" class="mt-1 inline-flex items-center gap-1 text-xs text-indigo-600 hover:text-indigo-800 font-medium">
                <svg class="h-3 w-3" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" fill="none"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                <span wire:loading.remove wire:target="lookupNewLocAddress">Lookup Address</span>
                <span wire:loading wire:target="lookupNewLocAddress">Looking up...</span>
            </button>
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">City</label>
            <input wire:model="newLocCity" type="text" class="w-full rounded-md border border-gray-300 px-2.5 py-2 text-sm">
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">Province</label>
            <input wire:model="newLocProvince" type="text" class="w-full rounded-md border border-gray-300 px-2.5 py-2 text-sm">
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">Customer Name</label>
            <input wire:model="newLocCustomerName" type="text" class="w-full rounded-md border border-gray-300 px-2.5 py-2 text-sm">
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">Phone</label>
            <input wire:model="newLocCustomerPhone" type="text" class="w-full rounded-md border border-gray-300 px-2.5 py-2 text-sm">
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">Email</label>
            <input wire:model="newLocCustomerEmail" type="email" class="w-full rounded-md border border-gray-300 px-2.5 py-2 text-sm">
        </div>
    </div>
    <div class="flex justify-end gap-2">
            <button type="button" wire:click="$set('showNew{{ ucfirst($target) }}', false)" class="rounded-md border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-50">Cancel</button>
            <button type="button" wire:click="saveNewLocation('{{ $target }}')" class="rounded-md bg-blue-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-blue-500">Save Location</button>
    </div>
</div>
