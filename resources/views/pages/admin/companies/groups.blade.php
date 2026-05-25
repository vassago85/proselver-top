<?php

use App\Models\Company;
use App\Models\CompanyGroup;
use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\Gate;

new #[Layout('components.layouts.app')] class extends Component {
    public bool $showCreate = false;
    public string $newName = '';
    public bool $newIsActive = true;

    public ?int $editingId = null;
    public string $editName = '';
    public bool $editIsActive = true;

    public function openCreate(): void
    {
        abort_unless(Gate::allows('create', Company::class), 403);
        $this->resetCreateForm();
        $this->showCreate = true;
    }

    public function closeCreate(): void
    {
        $this->showCreate = false;
        $this->resetCreateForm();
    }

    protected function resetCreateForm(): void
    {
        $this->newName = '';
        $this->newIsActive = true;
        $this->resetErrorBag(['newName']);
    }

    public function createGroup(): void
    {
        abort_unless(Gate::allows('create', Company::class), 403);

        $data = $this->validate([
            'newName'     => 'required|string|max:255|unique:company_groups,name',
            'newIsActive' => 'boolean',
        ]);

        CompanyGroup::create([
            'name'      => $data['newName'],
            'is_active' => (bool) $data['newIsActive'],
        ]);

        session()->flash('success', "Created group \"{$data['newName']}\".");
        $this->showCreate = false;
        $this->resetCreateForm();
    }

    public function startEdit(int $groupId): void
    {
        abort_unless(Gate::allows('create', Company::class), 403);

        $group = CompanyGroup::findOrFail($groupId);
        $this->editingId = $group->id;
        $this->editName = $group->name;
        $this->editIsActive = (bool) $group->is_active;
        $this->resetErrorBag(['editName']);
    }

    public function cancelEdit(): void
    {
        $this->editingId = null;
        $this->editName = '';
        $this->editIsActive = true;
    }

    public function saveEdit(): void
    {
        abort_unless($this->editingId !== null, 422);
        abort_unless(Gate::allows('create', Company::class), 403);

        $group = CompanyGroup::findOrFail($this->editingId);

        $data = $this->validate([
            'editName'     => 'required|string|max:255|unique:company_groups,name,' . $group->id,
            'editIsActive' => 'boolean',
        ]);

        $group->update([
            'name'      => $data['editName'],
            'is_active' => (bool) $data['editIsActive'],
        ]);

        session()->flash('success', "Updated group \"{$data['editName']}\".");
        $this->cancelEdit();
    }

    public function with(): array
    {
        // Eager-load companies count so admin sees how many dealerships
        // each group already owns at a glance — empty groups are fine
        // (they can be renamed / deleted) but populated ones are
        // load-bearing and need a confirm dialog before deletion.
        $groups = CompanyGroup::withCount('companies')
            ->orderBy('name')
            ->get();

        return [
            'groups'    => $groups,
            'canManage' => Gate::allows('create', Company::class),
        ];
    }
};

?>

<div>
    <x-slot:header>
        <div class="flex items-center gap-3">
            <a href="{{ route('admin.companies.index') }}" class="text-gray-400 hover:text-gray-600">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
            </a>
            Company Groups
        </div>
    </x-slot:header>

    @if(session('success'))
        <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('success') }}</div>
    @endif

    <div class="mb-4 flex items-start justify-between gap-4">
        <p class="max-w-2xl text-sm text-gray-600">
            A group is a holding-company umbrella over individual
            dealerships — for example <strong>MCCARTHY</strong> or
            <strong>CFAO</strong>. Stock and orders always belong to
            a single dealership; the group is purely an overview /
            shared-visibility construct so a CFAO ops manager can see
            what's happening across every CFAO dealership.
        </p>
        @if($canManage)
            <button type="button" wire:click="openCreate" class="inline-flex shrink-0 items-center gap-1.5 rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-500 transition-colors">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
                Add Group
            </button>
        @endif
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Name</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Dealerships</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Active</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @forelse($groups as $group)
                    @if($editingId === $group->id)
                        <tr class="bg-blue-50/40">
                            <td class="px-4 py-3">
                                <input wire:model="editName" type="text" class="w-full rounded-md border border-gray-300 px-3 py-1.5 text-sm focus:border-blue-500 focus:ring-blue-500" autofocus>
                                @error('editName') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-600">{{ $group->companies_count }}</td>
                            <td class="px-4 py-3">
                                <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                                    <input wire:model="editIsActive" type="checkbox" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                    Active
                                </label>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex gap-2">
                                    <button type="button" wire:click="saveEdit" class="rounded-md bg-blue-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-blue-500">Save</button>
                                    <button type="button" wire:click="cancelEdit" class="rounded-md border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50">Cancel</button>
                                </div>
                            </td>
                        </tr>
                    @else
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 text-sm font-medium text-gray-900">{{ $group->name }}</td>
                            <td class="px-4 py-3 text-sm text-gray-600">
                                <a href="{{ route('admin.companies.index', ['groupFilter' => $group->id]) }}" class="text-blue-600 hover:text-blue-800">
                                    {{ $group->companies_count }}
                                </a>
                            </td>
                            <td class="px-4 py-3">
                                @if($group->is_active)
                                    <span class="inline-flex items-center rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-700">Yes</span>
                                @else
                                    <span class="inline-flex items-center rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-700">No</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-sm">
                                @if($canManage)
                                    <button type="button" wire:click="startEdit({{ $group->id }})" class="text-blue-600 hover:text-blue-800 font-medium">Edit</button>
                                @endif
                            </td>
                        </tr>
                    @endif
                @empty
                    <tr>
                        <td colspan="4" class="px-6 py-12 text-center text-sm text-gray-500">
                            No groups yet.
                            @if($canManage)
                                <button type="button" wire:click="openCreate" class="text-blue-600 hover:text-blue-800 font-medium">Create the first one →</button>
                            @endif
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($showCreate)
        <div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/50 px-4 py-8" wire:click.self="closeCreate">
            <form wire:submit.prevent="createGroup" class="w-full max-w-md rounded-xl bg-white shadow-xl">
                <div class="flex items-center justify-between border-b border-slate-200 px-5 py-3">
                    <h3 class="text-base font-semibold text-slate-900">Add Group</h3>
                    <button type="button" wire:click="closeCreate" class="text-slate-400 hover:text-slate-600">
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>

                <div class="space-y-4 px-5 py-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Group name *</label>
                        <input wire:model="newName" type="text" placeholder="e.g. MCCARTHY, CFAO, Motus" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500" autofocus>
                        @error('newName') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="flex items-center gap-2">
                            <input wire:model="newIsActive" type="checkbox" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                            <span class="text-sm font-medium text-gray-700">Active</span>
                        </label>
                    </div>
                </div>

                <div class="flex items-center justify-end gap-2 border-t border-slate-200 px-5 py-3">
                    <button type="button" wire:click="closeCreate" class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">Cancel</button>
                    <button type="submit" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-500">Create group</button>
                </div>
            </form>
        </div>
    @endif
</div>
