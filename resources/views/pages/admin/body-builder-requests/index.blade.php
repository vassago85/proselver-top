<?php

use App\Models\BodyBuilderRequest;
use App\Models\Company;
use App\Services\BodyBuilderRequestService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

/*
 * Ops queue for dealer-initiated "add a body builder" requests.
 *
 * Each pending row is rendered as an inline card with three terminal
 * actions: approve-as-new, merge-into-existing, reject.  Reuses the
 * BodyBuilderRequestService -- the Volt page is a thin shell so the
 * business logic (dealer auto-link, BB company creation, audit
 * stamping) stays in one place.
 *
 * Dedupe hints from the service are shown next to each pending row so
 * ops can spot duplicates without manually searching the directory.
 */
new #[Layout('components.layouts.app')] class extends Component
{
    #[Url]
    public string $status = 'pending';

    public ?int $expandedId = null;
    public string $decisionNotes = '';
    public ?int $mergeTargetId = null;

    public function mount(): void
    {
        abort_unless(auth()->user()->isInternal(), 403);
    }

    public function expand(int $id): void
    {
        $this->expandedId = $this->expandedId === $id ? null : $id;
        $this->mergeTargetId = null;
        $this->decisionNotes = '';
    }

    public function approve(int $id): void
    {
        $req = $this->loadOrFail($id);
        app(BodyBuilderRequestService::class)->approveAsNew($req, auth()->user(), $this->decisionNotes ?: null);
        session()->flash('success', "Approved -- new body builder created and linked to {$req->dealer?->name}.");
        $this->reset(['expandedId', 'decisionNotes', 'mergeTargetId']);
    }

    public function merge(int $id): void
    {
        $req = $this->loadOrFail($id);
        if (!$this->mergeTargetId) {
            $this->addError('mergeTargetId', 'Pick an existing body builder to merge into.');
            return;
        }
        $target = Company::find($this->mergeTargetId);
        if (!$target || $target->type !== Company::TYPE_BODY_BUILDER) {
            $this->addError('mergeTargetId', 'Merge target is not a body builder.');
            return;
        }
        app(BodyBuilderRequestService::class)->mergeInto($req, $target, auth()->user(), $this->decisionNotes ?: null);
        session()->flash('success', "Merged into {$target->name}. {$req->dealer?->name} is now linked.");
        $this->reset(['expandedId', 'decisionNotes', 'mergeTargetId']);
    }

    public function reject(int $id): void
    {
        $req = $this->loadOrFail($id);
        app(BodyBuilderRequestService::class)->reject($req, auth()->user(), $this->decisionNotes ?: null);
        session()->flash('success', 'Request rejected.');
        $this->reset(['expandedId', 'decisionNotes', 'mergeTargetId']);
    }

    private function loadOrFail(int $id): BodyBuilderRequest
    {
        $req = BodyBuilderRequest::with('dealer')->findOrFail($id);
        if (!$req->isPending()) {
            throw new \RuntimeException("Request #{$id} is already {$req->status}.");
        }
        return $req;
    }

    /**
     * Search payload for the "merge into existing" picker on the
     * currently-expanded row.  Filters down to body-builder companies
     * sorted alphabetically; the searchable-select handles the rest.
     */
    public function bbOptions(): array
    {
        return Company::query()
            ->where('type', Company::TYPE_BODY_BUILDER)
            ->orderBy('name')
            ->limit(200)
            ->get(['id', 'name'])
            ->map(fn (Company $c) => ['value' => (string) $c->id, 'label' => $c->name])
            ->values()
            ->all();
    }

    public function with(): array
    {
        $base = BodyBuilderRequest::query()->with([
            'dealer:id,name,type',
            'requestedBy:id,name',
            'decidedBy:id,name',
            'resolvedBodyBuilder:id,name',
        ]);

        $requests = match($this->status) {
            'pending'  => $base->pending()->orderBy('created_at')->get(),
            'resolved' => $base->resolved()->orderByDesc('decided_at')->get(),
            default    => $base->orderByDesc('created_at')->get(),
        };

        $svc = app(BodyBuilderRequestService::class);
        $candidatesByRequest = [];
        foreach ($requests as $r) {
            if ($r->isPending()) {
                $candidatesByRequest[$r->id] = $svc->findDedupeCandidates($r->proposed_name, $r->proposed_address);
            }
        }

        return [
            'requests' => $requests,
            'pendingCount'  => BodyBuilderRequest::pending()->count(),
            'resolvedCount' => BodyBuilderRequest::resolved()->count(),
            'candidatesByRequest' => $candidatesByRequest,
            'bbOptions' => $this->bbOptions(),
        ];
    }
}; ?>

<div class="space-y-4">
    <x-slot:header>Body builder requests</x-slot:header>

    @if(session('success'))
        <div class="rounded-md bg-emerald-50 border border-emerald-200 px-3 py-2 text-sm text-emerald-800">{{ session('success') }}</div>
    @endif

    <div class="flex flex-wrap items-center gap-2 border-b border-slate-200 pb-3">
        @foreach([
            'pending'  => "Pending ({$pendingCount})",
            'resolved' => "Resolved ({$resolvedCount})",
            'all'      => 'All',
        ] as $value => $label)
            <button wire:click="$set('status', '{{ $value }}')"
                class="rounded-full px-3 py-1.5 text-sm font-medium {{ $status === $value ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200' }}">
                {{ $label }}
            </button>
        @endforeach
    </div>

    @if($requests->isEmpty())
        <div class="rounded-xl border border-slate-200 bg-white px-6 py-12 text-center text-sm text-slate-500">
            No body builder requests in this view.
        </div>
    @else
        <div class="space-y-3">
            @foreach($requests as $r)
                @php
                    $statusBadge = match($r->status) {
                        BodyBuilderRequest::STATUS_PENDING  => 'bg-amber-100 text-amber-800',
                        BodyBuilderRequest::STATUS_APPROVED => 'bg-emerald-100 text-emerald-800',
                        BodyBuilderRequest::STATUS_MERGED   => 'bg-blue-100 text-blue-800',
                        BodyBuilderRequest::STATUS_REJECTED => 'bg-rose-100 text-rose-800',
                        default                             => 'bg-slate-100 text-slate-700',
                    };
                    $isExpanded = $expandedId === $r->id;
                @endphp

                <div class="rounded-xl border border-slate-200 bg-white">
                    <div class="grid gap-3 px-5 py-4 sm:grid-cols-[2fr_2fr_1fr_auto] sm:items-start">
                        <div>
                            <div class="text-sm font-semibold text-slate-900">{{ $r->proposed_name }}</div>
                            <div class="mt-0.5 text-xs text-slate-500">{{ $r->proposed_address }}</div>
                            @if($r->proposed_contact_name || $r->proposed_contact_phone)
                                <div class="mt-1 text-xs text-slate-500">
                                    Contact: {{ $r->proposed_contact_name }}
                                    @if($r->proposed_contact_phone) · {{ $r->proposed_contact_phone }} @endif
                                </div>
                            @endif
                        </div>
                        <div class="text-xs text-slate-600">
                            <div><span class="text-slate-400">Requested by</span> {{ $r->dealer?->name }} ({{ $r->requestedBy?->name ?: '—' }})</div>
                            <div class="mt-0.5 text-slate-400">{{ $r->created_at?->format('j M Y') }}</div>
                            @if($r->dealer_notes)
                                <div class="mt-1 text-slate-600 italic">"{{ $r->dealer_notes }}"</div>
                            @endif
                        </div>
                        <div class="text-xs">
                            <span class="inline-flex items-center rounded-full px-2 py-0.5 font-medium {{ $statusBadge }}">
                                {{ $r->statusLabel() }}
                            </span>
                            @if($r->resolvedBodyBuilder)
                                <div class="mt-1 text-slate-600">→ <strong>{{ $r->resolvedBodyBuilder->name }}</strong></div>
                            @endif
                            @if($r->decision_notes)
                                <div class="mt-1 italic text-slate-500">"{{ $r->decision_notes }}"</div>
                            @endif
                        </div>
                        <div class="text-right">
                            @if($r->isPending())
                                <button wire:click="expand({{ $r->id }})"
                                    class="rounded-md bg-slate-900 px-3 py-1.5 text-xs font-semibold text-white hover:bg-slate-700">
                                    {{ $isExpanded ? 'Cancel' : 'Decide' }}
                                </button>
                            @else
                                <span class="text-[10px] text-slate-400">{{ $r->decidedBy?->name }}</span>
                            @endif
                        </div>
                    </div>

                    @if($r->isPending() && $isExpanded)
                        <div class="border-t border-slate-200 bg-slate-50 p-5 space-y-4">

                            @if(!empty($candidatesByRequest[$r->id]))
                                <div class="rounded-lg border border-amber-300 bg-amber-50 p-3 space-y-2">
                                    <p class="text-xs font-semibold text-amber-900">⚠ Looks like one of these existing body builders. Click to pre-fill the merge target.</p>
                                    <ul class="space-y-1">
                                        @foreach($candidatesByRequest[$r->id] as $c)
                                            <li>
                                                <button type="button" wire:click="$set('mergeTargetId', {{ $c['id'] }})"
                                                    class="w-full text-left rounded-md border {{ $mergeTargetId == $c['id'] ? 'border-blue-500 bg-blue-50' : 'border-amber-300 bg-white' }} px-3 py-2 hover:bg-amber-100">
                                                    <div class="text-sm font-medium text-slate-900">{{ $c['name'] }}</div>
                                                    <div class="text-xs text-slate-500">{{ $c['address'] ?: '— no address —' }}</div>
                                                </button>
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif

                            <div>
                                <label class="block text-xs font-medium text-slate-700">Or pick a different existing body builder to merge into</label>
                                <x-searchable-select
                                    wire:model="mergeTargetId"
                                    :options="$bbOptions"
                                    placeholder="-- search body builders --"
                                    :selected-label="optional(\App\Models\Company::find($mergeTargetId))->name"
                                />
                                @error('mergeTargetId')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                            </div>

                            <div>
                                <label class="block text-xs font-medium text-slate-700">Decision notes (optional, visible to dealer)</label>
                                <textarea wire:model="decisionNotes" rows="2"
                                    class="mt-1 block w-full rounded-md border-slate-300 text-sm"
                                    placeholder="e.g. Merged into existing entry -- they trade as 'Anchor Auto CC'."></textarea>
                            </div>

                            <div class="flex flex-wrap items-center gap-2 pt-1">
                                <button wire:click="approve({{ $r->id }})"
                                    wire:confirm="Approve as a NEW body builder?"
                                    class="rounded-md bg-emerald-600 px-3 py-2 text-xs font-semibold text-white hover:bg-emerald-700">
                                    ✓ Approve as new BB
                                </button>
                                <button wire:click="merge({{ $r->id }})"
                                    wire:confirm="Merge into the selected existing body builder?"
                                    class="rounded-md bg-blue-600 px-3 py-2 text-xs font-semibold text-white hover:bg-blue-700"
                                    @disabled(!$mergeTargetId)>
                                    ↪ Merge into existing
                                </button>
                                <button wire:click="reject({{ $r->id }})"
                                    wire:confirm="Reject this request? The dealer will see the reason."
                                    class="rounded-md bg-rose-600 px-3 py-2 text-xs font-semibold text-white hover:bg-rose-700">
                                    ✕ Reject
                                </button>
                            </div>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</div>
