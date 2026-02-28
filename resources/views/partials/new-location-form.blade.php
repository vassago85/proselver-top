<div class="mt-3 rounded-lg border border-blue-200 bg-blue-50 p-4 space-y-3">
    <p class="text-sm font-semibold text-gray-900">New Location</p>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">Company / Location Name *</label>
            <input wire:model="newLocCompanyName" type="text" class="w-full rounded-md border border-gray-300 px-2.5 py-2 text-sm" placeholder="e.g. ABC Motors Sandton">
            @error('newLocCompanyName')<p class="mt-0.5 text-xs text-red-600">{{ $message }}</p>@enderror
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">Address *</label>
            <input wire:model="newLocAddress" type="text" class="w-full rounded-md border border-gray-300 px-2.5 py-2 text-sm" placeholder="123 Main Rd, Sandton">
            @error('newLocAddress')<p class="mt-0.5 text-xs text-red-600">{{ $message }}</p>@enderror
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
            <label class="block text-xs font-medium text-gray-600 mb-1">Customer Contact</label>
            <input wire:model="newLocCustomerContact" type="text" class="w-full rounded-md border border-gray-300 px-2.5 py-2 text-sm">
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
    <div class="flex items-center justify-between">
        <label class="flex items-center gap-2 text-sm">
            <input wire:model="newLocIsPrivate" type="checkbox" class="h-4 w-4 rounded border-gray-300 text-blue-600">
            Private (only visible to your company)
        </label>
        <div class="flex gap-2">
            <button type="button" wire:click="$set('showNew{{ ucfirst($target) }}', false)" class="rounded-md border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-50">Cancel</button>
            <button type="button" wire:click="saveNewLocation('{{ $target }}')" class="rounded-md bg-blue-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-blue-500">Save Location</button>
        </div>
    </div>
</div>
