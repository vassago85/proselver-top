<?php

use App\Models\DriverBusTicket;
use App\Models\Job;
use App\Models\Location;
use App\Models\User;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

/**
 * Admin surface for driver bus-ticket movements.
 *
 * Where ops books a seat on a bus to get a driver (e.g. JHB -> PE) so
 * they can collect a vehicle, this is the screen where that ticket
 * lives.  Each row is one ticket for one driver.  The lifecycle is:
 *
 *   issued ─ driver travelled + collected  ─▶ used
 *          \ driver didn't travel          ─▶ not_used (voided | charged_to_driver)
 *
 * Access: ops controller / dispatcher / accounts / owner / developer /
 * super_admin / ops_manager.  Mirrors the gate the petty-cash queue
 * uses so the two sit comfortably side by side in the section strip.
 */
new #[Layout('components.layouts.app')] class extends Component {
    #[Url] public ?int $driver = null;
    #[Url] public string $status = 'all';
    #[Url] public string $month = '';

    // Create / edit modal buffer.
    public bool $showIssue = false;
    public ?int $editingId = null;
    public ?int $formDriver = null;
    public ?int $formJob = null;
    public string $formBusCompany = '';
    public string $formReference = '';
    public ?int $formOriginLocation = null;
    public string $formOriginLabel = '';
    public ?int $formDestinationLocation = null;
    public string $formDestinationLabel = '';
    public string $formTravelDate = '';
    public string $formAmount = '';
    public string $formNotes = '';

    // Reason modal buffer (Void / Charge).
    public ?int $reasonTicketId = null;
    public string $reasonAction = ''; // void | charge
    public string $reasonText = '';

    public function mount(): void
    {
        $this->assertAuthorised();

        if ($this->month === '' || !preg_match('/^\d{4}-\d{2}$/', $this->month)) {
            $this->month = now()->format('Y-m');
        }
    }

    /**
     * Access gate.  Deliberately wider than the pay / payslip pages
     * because operations controllers and dispatchers are the ones who
     * actually book bus tickets -- accounts just reads the outcome.
     */
    private function assertAuthorised(): void
    {
        $u = auth()->user();
        $ok = $u && (
            $u->isOwner()
            || $u->isDeveloper()
            || $u->isAccounts()
            || $u->isOperationsController()
            || $u->hasAnyRole(['super_admin', 'ops_manager', 'dispatcher'])
        );
        if (!$ok) {
            abort(403, 'Bus tickets are restricted to ops / accounts.');
        }
    }

    /**
     * Which users can bus tickets be issued for? Active platform
     * drivers only -- subcontractors and off-platform drivers have
     * their own cost paths.
     */
    public function driverOptions()
    {
        return User::query()
            ->platformDrivers()
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    public function locationOptions()
    {
        return Location::query()
            ->where('is_active', true)
            ->orderBy('city')
            ->orderBy('company_name')
            ->get(['id', 'company_name', 'city'])
            ->map(fn ($l) => [
                'id'    => $l->id,
                'label' => trim(($l->city ?? '') . ($l->city && $l->company_name ? ' · ' : '') . ($l->company_name ?? '')) ?: ('Location #' . $l->id),
            ]);
    }

    /**
     * Jobs the ticket might be linked to for the currently-selected
     * driver.  We lean liberal here: any non-cancelled job assigned to
     * that driver with a scheduled_date in the recent window shows up,
     * so ops can attach a ticket to a trip that hasn't started yet.
     */
    public function jobOptions()
    {
        if (!$this->formDriver) {
            return collect();
        }

        return Job::query()
            ->where('driver_user_id', $this->formDriver)
            ->whereNotIn('status', [Job::STATUS_CANCELLED])
            ->where(function ($q) {
                $q->whereBetween('scheduled_date', [now()->subMonths(2), now()->addMonths(2)])
                    ->orWhereNull('scheduled_date');
            })
            ->orderByDesc('scheduled_date')
            ->limit(100)
            ->get(['id', 'job_number', 'scheduled_date', 'status']);
    }

    private function monthRange(): array
    {
        try {
            $anchor = Carbon::createFromFormat('!Y-m', $this->month);
        } catch (\Throwable $e) {
            $anchor = now()->startOfMonth();
        }
        return [$anchor->copy()->startOfMonth(), $anchor->copy()->endOfMonth(), $anchor];
    }

    public function openIssue(): void
    {
        $this->resetForm();
        $this->editingId = null;
        $this->formTravelDate = now()->format('Y-m-d');
        $this->showIssue = true;
    }

    public function openEdit(int $ticketId): void
    {
        $this->assertAuthorised();

        $t = DriverBusTicket::query()->whereKey($ticketId)->first();
        if (!$t) { return; }

        $this->editingId               = $t->id;
        $this->formDriver              = $t->driver_user_id;
        $this->formJob                 = $t->transport_job_id;
        $this->formBusCompany          = $t->bus_company ?? '';
        $this->formReference           = $t->reference_number ?? '';
        $this->formOriginLocation      = $t->origin_location_id;
        $this->formOriginLabel         = $t->origin_label ?? '';
        $this->formDestinationLocation = $t->destination_location_id;
        $this->formDestinationLabel    = $t->destination_label ?? '';
        $this->formTravelDate          = $t->travel_date->format('Y-m-d');
        $this->formAmount              = number_format($t->amountRand(), 2, '.', '');
        $this->formNotes               = $t->notes ?? '';
        $this->showIssue = true;
    }

    private function resetForm(): void
    {
        $this->formDriver = $this->driver; // preserve the filtered driver as a default
        $this->formJob = null;
        $this->formBusCompany = '';
        $this->formReference = '';
        $this->formOriginLocation = null;
        $this->formOriginLabel = '';
        $this->formDestinationLocation = null;
        $this->formDestinationLabel = '';
        $this->formTravelDate = '';
        $this->formAmount = '';
        $this->formNotes = '';
    }

    public function save(): void
    {
        $this->assertAuthorised();

        $this->validate([
            'formDriver'      => ['required', 'integer', 'exists:users,id'],
            'formTravelDate'  => ['required', 'date'],
            'formAmount'      => ['required', 'numeric', 'min:0'],
            'formBusCompany'  => ['nullable', 'string', 'max:120'],
            'formReference'   => ['nullable', 'string', 'max:120'],
            'formOriginLabel' => ['nullable', 'string', 'max:160'],
            'formDestinationLabel' => ['required_without:formDestinationLocation', 'nullable', 'string', 'max:160'],
            'formNotes'       => ['nullable', 'string'],
        ], [
            'formDestinationLabel.required_without' => 'Pick a destination location or type one in.',
        ]);

        $cents = (int) round(((float) $this->formAmount) * 100);

        $data = [
            'driver_user_id'          => $this->formDriver,
            'transport_job_id'        => $this->formJob,
            'bus_company'             => $this->formBusCompany ?: null,
            'reference_number'        => $this->formReference ?: null,
            'origin_location_id'      => $this->formOriginLocation,
            'origin_label'            => $this->formOriginLabel ?: null,
            'destination_location_id' => $this->formDestinationLocation,
            'destination_label'       => $this->formDestinationLabel ?: null,
            'travel_date'             => $this->formTravelDate,
            'amount_cents'            => $cents,
            'notes'                   => $this->formNotes ?: null,
        ];

        if ($this->editingId) {
            $t = DriverBusTicket::query()->whereKey($this->editingId)->first();
            if (!$t) {
                session()->flash('error', 'Ticket not found.');
                return;
            }
            // Don't let an edit clobber a resolved ticket's history --
            // once used / voided / charged, lifecycle fields are
            // immutable from the edit form.  Delete + re-issue if the
            // whole row needs to go.
            $t->fill($data)->save();
            session()->flash('success', 'Ticket updated.');
        } else {
            $data['created_by_user_id'] = auth()->id();
            DriverBusTicket::create($data);
            session()->flash('success', 'Bus ticket issued.');
        }

        $this->showIssue = false;
        $this->editingId = null;
        $this->resetForm();
    }

    public function markUsed(int $ticketId): void
    {
        $this->assertAuthorised();
        $t = DriverBusTicket::query()->whereKey($ticketId)->first();
        if ($t && !$t->markUsed(auth()->user())) {
            session()->flash('error', 'Ticket could not be marked used in its current state.');
        }
    }

    public function openReason(int $ticketId, string $action): void
    {
        if (!in_array($action, ['void', 'charge'], true)) { return; }
        $this->reasonTicketId = $ticketId;
        $this->reasonAction = $action;
        $this->reasonText = '';
    }

    public function closeReason(): void
    {
        $this->reasonTicketId = null;
        $this->reasonAction = '';
        $this->reasonText = '';
    }

    public function confirmReason(): void
    {
        $this->assertAuthorised();

        if (!$this->reasonTicketId || trim($this->reasonText) === '') {
            session()->flash('error', 'A reason is required.');
            return;
        }

        $t = DriverBusTicket::query()->whereKey($this->reasonTicketId)->first();
        if (!$t) { $this->closeReason(); return; }

        $ok = match ($this->reasonAction) {
            'void'   => $t->markVoided(auth()->user(), $this->reasonText),
            'charge' => $t->markChargedToDriver(auth()->user(), $this->reasonText),
            default  => false,
        };

        if (!$ok) {
            session()->flash('error', 'Ticket could not be resolved from its current state.');
        }

        $this->closeReason();
    }

    public function with(): array
    {
        [$from, $to, $anchor] = $this->monthRange();

        $query = DriverBusTicket::query()
            ->with([
                'driver:id,name',
                'job:id,job_number',
                'originLocation:id,company_name,city',
                'destinationLocation:id,company_name,city',
                'resolvedBy:id,name',
                'createdBy:id,name',
            ])
            ->whereBetween('travel_date', [$from, $to])
            ->orderBy('travel_date');

        if ($this->driver) {
            $query->where('driver_user_id', $this->driver);
        }

        if ($this->status === 'open') {
            $query->open();
        } elseif ($this->status === 'used') {
            $query->where('status', DriverBusTicket::STATUS_USED);
        } elseif ($this->status === 'voided') {
            $query->where('status', DriverBusTicket::STATUS_NOT_USED)
                ->where('not_used_outcome', DriverBusTicket::OUTCOME_VOIDED);
        } elseif ($this->status === 'charged') {
            $query->chargedToDriver();
        }

        $tickets = $query->get();

        $totals = [
            'count'   => $tickets->count(),
            'open'    => $tickets->where('status', DriverBusTicket::STATUS_ISSUED)->count(),
            'used'    => $tickets->where('status', DriverBusTicket::STATUS_USED)->count(),
            'charged' => (float) $tickets
                ->where('status', DriverBusTicket::STATUS_NOT_USED)
                ->where('not_used_outcome', DriverBusTicket::OUTCOME_CHARGED_TO_DRIVER)
                ->sum(fn (DriverBusTicket $t) => $t->amountRand()),
            'voided'  => (float) $tickets
                ->where('status', DriverBusTicket::STATUS_NOT_USED)
                ->where('not_used_outcome', DriverBusTicket::OUTCOME_VOIDED)
                ->sum(fn (DriverBusTicket $t) => $t->amountRand()),
            'total_spend' => (float) $tickets->sum(fn (DriverBusTicket $t) => $t->amountRand()),
        ];

        return [
            'tickets'         => $tickets,
            'totals'          => $totals,
            'from'            => $from,
            'to'              => $to,
            'anchor'          => $anchor,
            'drivers'         => $this->driverOptions(),
            'locations'       => $this->locationOptions(),
            'jobsForForm'     => $this->jobOptions(),
        ];
    }
}; ?>

<div class="space-y-4">
    <x-slot:header>Driver bus tickets</x-slot:header>

    @include('pages.admin.petty-cash._partials.section-tabs')

    @if(session('success'))
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ session('error') }}</div>
    @endif

    {{-- Filters + issue button --}}
    <div class="rounded-xl border border-slate-200 bg-white shadow-sm">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-5 py-3">
            <div>
                <h2 class="text-sm font-semibold text-slate-900">Bus tickets &mdash; {{ $anchor->format('F Y') }}</h2>
                <p class="text-xs text-slate-500">
                    {{ $totals['count'] }} ticket{{ $totals['count'] === 1 ? '' : 's' }} in window
                    &middot; {{ $totals['open'] }} open
                    &middot; R {{ number_format($totals['total_spend'], 2) }} spent
                </p>
            </div>
            <div class="flex items-center gap-3">
                <label class="flex items-center gap-2 text-xs text-slate-600">
                    <span class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Driver</span>
                    <select wire:model.live="driver" class="rounded-md border-slate-300 text-xs shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        <option value="">All drivers</option>
                        @foreach($drivers as $d)
                            <option value="{{ $d->id }}">{{ $d->name }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="flex items-center gap-2 text-xs text-slate-600">
                    <span class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Status</span>
                    <select wire:model.live="status" class="rounded-md border-slate-300 text-xs shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        <option value="all">All</option>
                        <option value="open">Open (issued)</option>
                        <option value="used">Used</option>
                        <option value="charged">Charged to driver</option>
                        <option value="voided">Voided</option>
                    </select>
                </label>
                <label class="flex items-center gap-2 text-xs text-slate-600">
                    <span class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Month</span>
                    <input type="month" wire:model.live="month"
                        class="rounded-md border-slate-300 text-xs shadow-sm focus:border-blue-500 focus:ring-blue-500">
                </label>
                <button type="button"
                    wire:click="openIssue"
                    class="inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-blue-700">
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    Issue ticket
                </button>
            </div>
        </div>

        {{-- Headline totals --}}
        <div class="grid grid-cols-2 gap-3 border-b border-slate-100 px-5 py-3 sm:grid-cols-4">
            <div class="rounded-lg border border-amber-200 bg-amber-50 p-3">
                <p class="text-[10px] font-semibold uppercase tracking-wider text-amber-800">Open tickets</p>
                <p class="mt-1 text-lg font-bold text-amber-900 tabular-nums">{{ $totals['open'] }}</p>
            </div>
            <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-3">
                <p class="text-[10px] font-semibold uppercase tracking-wider text-emerald-700">Used</p>
                <p class="mt-1 text-lg font-bold text-emerald-900 tabular-nums">{{ $totals['used'] }}</p>
            </div>
            <div class="rounded-lg border border-rose-200 bg-rose-50 p-3">
                <p class="text-[10px] font-semibold uppercase tracking-wider text-rose-700">Charged to driver</p>
                <p class="mt-1 text-lg font-bold text-rose-900 tabular-nums">R {{ number_format($totals['charged'], 2) }}</p>
            </div>
            <div class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Voided (write-off)</p>
                <p class="mt-1 text-lg font-bold text-slate-900 tabular-nums">R {{ number_format($totals['voided'], 2) }}</p>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-xs">
                <thead class="bg-slate-50 text-[10px] uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-3 py-2 text-left">Travel</th>
                        <th class="px-3 py-2 text-left">Driver</th>
                        <th class="px-3 py-2 text-left">From &rarr; To</th>
                        <th class="px-3 py-2 text-left">Linked job</th>
                        <th class="px-3 py-2 text-left">Carrier</th>
                        <th class="px-3 py-2 text-right">Amount</th>
                        <th class="px-3 py-2 text-center">Status</th>
                        <th class="px-3 py-2 text-left">Reason / resolved by</th>
                        <th class="px-3 py-2 text-center">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($tickets as $t)
                        <tr class="hover:bg-slate-50">
                            <td class="px-3 py-2 text-slate-700">{{ $t->travel_date->format('d M Y') }}</td>
                            <td class="px-3 py-2 text-slate-700">
                                <a href="{{ route('admin.drivers.payslip', ['user' => $t->driver_user_id, 'month' => $month]) }}"
                                    class="text-blue-600 hover:underline">
                                    {{ $t->driver?->name ?? '—' }}
                                </a>
                            </td>
                            <td class="px-3 py-2 text-slate-700">
                                {{ $t->originForDisplay() }} &rarr; {{ $t->destinationForDisplay() }}
                            </td>
                            <td class="px-3 py-2 font-mono text-[11px] text-slate-700">
                                @if($t->job)
                                    <a href="{{ route('admin.orders.show', $t->job) }}" class="text-blue-600 hover:underline">{{ $t->job->job_number }}</a>
                                @else
                                    —
                                @endif
                            </td>
                            <td class="px-3 py-2 text-slate-600">
                                {{ $t->bus_company ?: '—' }}
                                @if($t->reference_number)
                                    <div class="text-[10px] text-slate-400">{{ $t->reference_number }}</div>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums text-slate-800">{{ $t->amountForDisplay() }}</td>
                            <td class="px-3 py-2 text-center">
                                <span class="inline-flex rounded-full border px-2 py-0.5 text-[10px] font-semibold {{ $t->statusBadgeClasses() }}">
                                    {{ $t->statusLabel() }}
                                    @if($t->outcomeLabel()) &middot; {{ $t->outcomeLabel() }}@endif
                                </span>
                            </td>
                            <td class="px-3 py-2 text-slate-600">
                                @if($t->not_used_reason)
                                    <div class="max-w-xs text-[11px]">{{ $t->not_used_reason }}</div>
                                @endif
                                @if($t->resolvedBy)
                                    <div class="text-[10px] text-slate-400">
                                        by {{ $t->resolvedBy->name }}
                                        @if($t->resolved_at) &middot; {{ $t->resolved_at->format('d M') }}@endif
                                    </div>
                                @elseif($t->createdBy)
                                    <div class="text-[10px] text-slate-400">issued by {{ $t->createdBy->name }}</div>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-center">
                                @if($t->isOpen())
                                    <div class="flex items-center justify-center gap-1.5">
                                        <button type="button"
                                            wire:click="markUsed({{ $t->id }})"
                                            class="rounded-md border border-emerald-300 bg-emerald-50 px-2 py-0.5 text-[10px] font-semibold text-emerald-700 hover:bg-emerald-100">
                                            Used
                                        </button>
                                        <button type="button"
                                            wire:click="openReason({{ $t->id }}, 'void')"
                                            class="rounded-md border border-slate-300 bg-white px-2 py-0.5 text-[10px] font-semibold text-slate-700 hover:bg-slate-50">
                                            Void
                                        </button>
                                        <button type="button"
                                            wire:click="openReason({{ $t->id }}, 'charge')"
                                            class="rounded-md border border-rose-300 bg-rose-50 px-2 py-0.5 text-[10px] font-semibold text-rose-700 hover:bg-rose-100">
                                            Charge
                                        </button>
                                        <button type="button"
                                            wire:click="openEdit({{ $t->id }})"
                                            class="text-[10px] font-medium text-blue-600 hover:underline">
                                            Edit
                                        </button>
                                    </div>
                                @else
                                    <span class="text-[10px] text-slate-400">resolved</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="px-3 py-10 text-center text-sm text-slate-500">
                                No bus tickets match the current filters.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Issue / Edit modal --}}
    @if($showIssue)
        <div class="fixed inset-0 z-40 flex items-center justify-center bg-slate-900/50 p-4" wire:click.self="$set('showIssue', false)">
            <div class="w-full max-w-xl rounded-xl bg-white p-6 shadow-xl">
                <div class="flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-slate-900">
                        {{ $editingId ? 'Edit bus ticket' : 'Issue bus ticket' }}
                    </h3>
                    <button type="button" wire:click="$set('showIssue', false)" class="text-slate-400 hover:text-slate-700">&times;</button>
                </div>
                <form wire:submit="save" class="mt-4 space-y-3">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-[11px] font-semibold text-slate-700">Driver *</label>
                            <select wire:model.live="formDriver" class="mt-1 w-full rounded-md border-slate-300 text-xs shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <option value="">—</option>
                                @foreach($drivers as $d)
                                    <option value="{{ $d->id }}">{{ $d->name }}</option>
                                @endforeach
                            </select>
                            @error('formDriver')<p class="mt-1 text-[10px] text-rose-600">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label class="block text-[11px] font-semibold text-slate-700">Linked job (optional)</label>
                            <select wire:model="formJob" class="mt-1 w-full rounded-md border-slate-300 text-xs shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <option value="">—</option>
                                @foreach($jobsForForm as $j)
                                    <option value="{{ $j->id }}">
                                        {{ $j->job_number }}
                                        @if($j->scheduled_date) &middot; {{ \Illuminate\Support\Carbon::parse($j->scheduled_date)->format('d M') }}@endif
                                        &middot; {{ $j->status }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-[11px] font-semibold text-slate-700">Travel date *</label>
                            <input type="date" wire:model="formTravelDate" class="mt-1 w-full rounded-md border-slate-300 text-xs shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            @error('formTravelDate')<p class="mt-1 text-[10px] text-rose-600">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label class="block text-[11px] font-semibold text-slate-700">Amount (R) *</label>
                            <input type="number" step="0.01" min="0" wire:model="formAmount" class="mt-1 w-full rounded-md border-slate-300 text-xs tabular-nums shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            @error('formAmount')<p class="mt-1 text-[10px] text-rose-600">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label class="block text-[11px] font-semibold text-slate-700">Bus company</label>
                            <input type="text" wire:model="formBusCompany" maxlength="120" class="mt-1 w-full rounded-md border-slate-300 text-xs shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="e.g. Intercape">
                        </div>
                        <div>
                            <label class="block text-[11px] font-semibold text-slate-700">Reference</label>
                            <input type="text" wire:model="formReference" maxlength="120" class="mt-1 w-full rounded-md border-slate-300 text-xs shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="booking ref">
                        </div>
                        <div class="sm:col-span-2 border-t border-slate-100 pt-3">
                            <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">From</p>
                        </div>
                        <div>
                            <label class="block text-[11px] font-semibold text-slate-700">Origin location (pick)</label>
                            <select wire:model="formOriginLocation" class="mt-1 w-full rounded-md border-slate-300 text-xs shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <option value="">—</option>
                                @foreach($locations as $l)
                                    <option value="{{ $l['id'] }}">{{ $l['label'] }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-[11px] font-semibold text-slate-700">Origin label (free text)</label>
                            <input type="text" wire:model="formOriginLabel" maxlength="160" class="mt-1 w-full rounded-md border-slate-300 text-xs shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="e.g. Park Station, Johannesburg">
                        </div>
                        <div class="sm:col-span-2 border-t border-slate-100 pt-3">
                            <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">To</p>
                        </div>
                        <div>
                            <label class="block text-[11px] font-semibold text-slate-700">Destination location (pick)</label>
                            <select wire:model="formDestinationLocation" class="mt-1 w-full rounded-md border-slate-300 text-xs shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <option value="">—</option>
                                @foreach($locations as $l)
                                    <option value="{{ $l['id'] }}">{{ $l['label'] }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-[11px] font-semibold text-slate-700">Destination label (free text)</label>
                            <input type="text" wire:model="formDestinationLabel" maxlength="160" class="mt-1 w-full rounded-md border-slate-300 text-xs shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="e.g. Port Elizabeth terminal">
                            @error('formDestinationLabel')<p class="mt-1 text-[10px] text-rose-600">{{ $message }}</p>@enderror
                        </div>
                        <div class="sm:col-span-2">
                            <label class="block text-[11px] font-semibold text-slate-700">Notes</label>
                            <textarea wire:model="formNotes" rows="2" class="mt-1 w-full rounded-md border-slate-300 text-xs shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="optional"></textarea>
                        </div>
                    </div>
                    <div class="flex items-center justify-end gap-2 pt-2">
                        <button type="button" wire:click="$set('showIssue', false)" class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50">Cancel</button>
                        <button type="submit" class="rounded-md bg-blue-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-blue-700">
                            {{ $editingId ? 'Save changes' : 'Issue ticket' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    {{-- Reason modal (Void / Charge driver) --}}
    @if($reasonTicketId)
        <div class="fixed inset-0 z-40 flex items-center justify-center bg-slate-900/50 p-4" wire:click.self="closeReason">
            <div class="w-full max-w-md rounded-xl bg-white p-5 shadow-xl">
                <h4 class="text-sm font-semibold text-slate-900">
                    {{ $reasonAction === 'void' ? 'Void ticket' : 'Charge driver for ticket' }}
                </h4>
                <p class="mt-1 text-xs text-slate-500">
                    Capture the reason -- it stays on the ticket for the owner audit.
                </p>
                <textarea wire:model="reasonText"
                    rows="4"
                    placeholder="What happened?"
                    class="mt-3 w-full rounded-md border-slate-300 py-2 text-xs shadow-sm focus:border-blue-500 focus:ring-blue-500"></textarea>
                <div class="mt-4 flex items-center justify-end gap-2">
                    <button type="button" wire:click="closeReason"
                        class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50">
                        Cancel
                    </button>
                    <button type="button" wire:click="confirmReason"
                        class="{{ $reasonAction === 'void' ? 'bg-slate-800 hover:bg-slate-900' : 'bg-rose-600 hover:bg-rose-700' }} rounded-md px-3 py-1.5 text-xs font-semibold text-white">
                        Confirm
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
