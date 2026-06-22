<?php

use App\Models\BodyBuilderDealerLink;
use App\Models\Company;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    public ?Company $company = null;

    public string $searchTerm = '';
    public ?int $selectedBbId = null;
    public string $linkNotes = '';

    public function mount(): void
    {
        $this->company = auth()->user()?->company();
        abort_unless($this->company, 403);
        abort_unless(
            auth()->user()->canManageBbLinks(),
            403,
            'You do not have permission to manage body-builder links.'
        );
        abort_if(
            $this->company->isOem(),
            403,
            'OEM accounts do not manage body-builder links — ProSelver handles inbound fitments centrally.'
        );
    }

    public function addLink(): void
    {
        $this->validate([
            'selectedBbId' => 'required|exists:companies,id',
            'linkNotes'    => 'nullable|string|max:500',
        ]);

        // Defence: the BB picker only shows TYPE_BODY_BUILDER companies,
        // but a tampered payload could submit a dealer id here. Re-check
        // server-side so we can never accidentally turn a dealer into a
        // BB by linking them.
        $bb = Company::findOrFail($this->selectedBbId);
        if ($bb->type !== Company::TYPE_BODY_BUILDER) {
            $this->addError('selectedBbId', 'That company is not registered as a body builder.');
            return;
        }

        // Re-add a previously deactivated link by toggling is_active
        // rather than creating a duplicate — keeps history clean.
        $link = BodyBuilderDealerLink::updateOrCreate(
            [
                'dealer_company_id'       => $this->company->id,
                'body_builder_company_id' => $bb->id,
            ],
            [
                'linked_by_user_id' => auth()->id(),
                'is_active'         => true,
                'notes'             => $this->linkNotes ?: null,
            ],
        );

        session()->flash('success', "Linked {$bb->name}. They can now confirm vehicles delivered to their workshops and raise movement requests.");
        $this->reset(['selectedBbId', 'linkNotes', 'searchTerm']);
    }

    public function toggleActive(int $linkId): void
    {
        $link = BodyBuilderDealerLink::where('dealer_company_id', $this->company->id)
            ->findOrFail($linkId);
        $link->update(['is_active' => ! $link->is_active]);
    }

    public function with(): array
    {
        $links = BodyBuilderDealerLink::query()
            ->where('dealer_company_id', $this->company->id)
            ->with(['bodyBuilder:id,name,type', 'linkedBy:id,name'])
            ->orderByDesc('is_active')
            ->orderBy('id')
            ->get();

        $linkedIds = $links->pluck('body_builder_company_id')->all();

        // Searchable list of unlinked BB companies. Capped at 50 to keep
        // the dropdown snappy; if the user can't see the BB they're
        // looking for they can refine the search.
        $bbOptions = Company::query()
            ->where('type', Company::TYPE_BODY_BUILDER)
            ->where('is_active', true)
            ->when($this->searchTerm, fn ($q, $term) => $q->where('name', 'like', "%{$term}%"))
            ->orderBy('name')
            ->limit(50)
            ->get(['id', 'name'])
            ->map(fn ($c) => ['value' => (string) $c->id, 'label' => $c->name . (in_array($c->id, $linkedIds) ? ' (already linked)' : '')])
            ->values()
            ->all();

        return compact('links', 'bbOptions');
    }
};
?>

<div>
    <x-slot:header>Linked Body Builders</x-slot:header>

    <div class="mb-4 rounded-lg border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-800 space-y-2">
        <p><strong>Two ways body builders move your stock:</strong></p>
        <ul class="list-disc pl-5 space-y-1 text-slate-700">
            <li><strong>Movement request</strong> — the BB asks you to book transport (you arrange and pay). Review these under <a href="{{ route('customer.movement-requests.index') }}" class="font-semibold text-blue-600 hover:text-blue-800">Movement Requests</a>.</li>
            <li><strong>Direct order</strong> — the BB books ProSelver themselves; you only approve the move as vehicle owner. These land in <a href="{{ route('customer.orders.index', ['owner_pending' => 1]) }}" class="font-semibold text-blue-600 hover:text-blue-800">My Orders</a>.</li>
        </ul>
    </div>

    @if(session('success'))
        <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('success') }}</div>
    @endif

    <div class="mb-4 rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-900">
        Authorise body builders / fitment shops to confirm vehicles delivered to their workshops and raise next-fitment or collection requests on your behalf.
        Requests they raise come into your <a href="{{ route('customer.movement-requests.index') }}" class="font-semibold underline">Movement Requests queue</a> for approval.
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2 overflow-hidden rounded-xl border border-slate-200 bg-white">
            <div class="border-b border-slate-200 px-5 py-3">
                <h2 class="text-sm font-semibold text-slate-900">Authorised body builders</h2>
            </div>
            @if($links->isEmpty())
                <div class="px-6 py-10 text-center text-sm text-slate-500">
                    No body builders linked yet. Use the form on the right to add one.
                </div>
            @else
                <table class="min-w-full divide-y divide-slate-200">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-slate-500">Body builder</th>
                            <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-slate-500">Linked by</th>
                            <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-slate-500">Since</th>
                            <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-slate-500">Status</th>
                            <th class="px-4 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($links as $link)
                            <tr class="{{ $link->is_active ? '' : 'opacity-60' }}">
                                <td class="whitespace-nowrap px-4 py-2 text-sm font-medium text-slate-900">{{ $link->bodyBuilder?->name }}</td>
                                <td class="whitespace-nowrap px-4 py-2 text-sm text-slate-700">{{ $link->linkedBy?->name ?: '—' }}</td>
                                <td class="whitespace-nowrap px-4 py-2 text-sm text-slate-700">{{ optional($link->created_at)->format('j M Y') }}</td>
                                <td class="whitespace-nowrap px-4 py-2 text-sm">
                                    @if($link->is_active)
                                        <span class="inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700">Active</span>
                                    @else
                                        <span class="inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600">Paused</span>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-4 py-2 text-right text-sm">
                                    <button wire:click="toggleActive({{ $link->id }})" class="font-medium text-blue-600 hover:text-blue-800">
                                        {{ $link->is_active ? 'Pause' : 'Reactivate' }}
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        <form wire:submit.prevent="addLink" class="rounded-xl border border-slate-200 bg-white p-5 space-y-3">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-semibold text-slate-900">Add a body builder</h2>
                <a href="{{ route('customer.body-builders.requests.index') }}" class="text-xs font-semibold text-blue-600 hover:text-blue-800 underline">Requests</a>
            </div>
            <p class="text-xs text-slate-500">
                Search the directory of registered body-builder companies. Don't see them?
                <a href="{{ route('customer.body-builders.requests.create') }}" class="font-semibold text-blue-600 underline">Request a new one</a>
                — ProSelver ops will add it (or point you at the existing entry).
            </p>

            <div>
                <label class="block text-xs font-medium text-slate-700">Search</label>
                <input type="search" wire:model.live.debounce.300ms="searchTerm" class="mt-1 block w-full rounded-md border-slate-300 text-sm" placeholder="Start typing the BB name…" />
            </div>

            <div>
                <label class="block text-xs font-medium text-slate-700">Body builder</label>
                <x-searchable-select
                    wire:model="selectedBbId"
                    :options="$bbOptions"
                    placeholder="— pick from results —"
                />
                @error('selectedBbId')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
            </div>

            <div>
                <label class="block text-xs font-medium text-slate-700">Notes (optional)</label>
                <textarea wire:model="linkNotes" rows="2" class="mt-1 block w-full rounded-md border-slate-300 text-sm" placeholder="e.g. canopy + radio fitments only"></textarea>
            </div>

            <button type="submit" class="w-full rounded-md bg-blue-600 px-3 py-2 text-sm font-semibold text-white hover:bg-blue-700">Link body builder</button>
        </form>
    </div>
</div>
