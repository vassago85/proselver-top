<?php

use App\Models\Company;
use App\Models\DealerStock;
use App\Models\DealerStockFitment;
use App\Models\Role;
use App\Models\User;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/*
 * Per-vehicle stock view -- the single source of truth for one VIN.
 * Drives the dealer's commercial lifecycle:
 *
 *   1. Reserve            -- assigns a salesperson + customer while
 *                            the vehicle is still on the floor.
 *                            status=RESERVED, stamps reserved_at.
 *   2. Mark as sold       -- pre-fills from reserve (if any), captures
 *                            final sale details, stamps sold_at. End
 *                            of the commercial lifecycle.
 *   3. Reverse sale       -- undo a sale while the row is still on
 *                            the active ledger.
 *   4. Send / return demo -- temporary on-demo loop with customer +
 *                            due-back date.
 *   5. Archive            -- the dealer closes the row once the sale
 *                            is final and the vehicle has left their
 *                            books.
 *
 * delivered_at + LOCATION_DELIVERED still exist on the model so the
 * movement linker can record "transport job arrived at a dealer
 * destination", but the dealer no longer has a manual "Mark as
 * delivered" step -- if it's sold, it's sold.
 *
 * Each action is an isolated method protected by manage_dealer_stock
 * and a tenancy check ($stock->dealer_company_id in
 * visibleCompanyIds()).
 */
new #[Layout('components.layouts.app')] class extends Component {
    public DealerStock $stock;

    public bool $showReserveForm = false;
    public bool $showSaleForm = false;
    public ?int $salesperson_user_id = null;
    public string $sale_customer_name = '';
    public string $sale_customer_phone = '';
    public string $sale_customer_email = '';

    public bool $showDemoForm = false;
    public string $demo_customer_name = '';
    public string $demo_customer_phone = '';
    public string $demo_customer_email = '';
    public string $demo_due_back_at = '';

    // --- Fitment chain (multi-BB build process) ----------------------
    // A vehicle can pass through several BBs in sequence: dropside ->
    // crane, fridge body -> fridge unit, etc.  Each leg has its own
    // notes / shared-with-BB toggles, so the dealer can choose to
    // share the end customer with the fridge body supplier but NOT
    // the crane supplier (for example).
    //
    // The form below is an inline "Add fitment step" form.  Editing
    // an existing leg happens in-place via editFitment / saveFitment.
    public bool $showFitmentForm = false;
    public ?int $editingFitmentId = null;
    public ?int $fitment_body_builder_id = null;
    public string $fitment_type = '';
    public string $fitment_notes = '';
    public bool $fitment_share_with_bb = false;
    public string $fitment_share_salesperson = '';
    public string $fitment_share_end_customer = '';

    public function mount(DealerStock $dealerStock): void
    {
        $user = auth()->user();
        // Dealer-tenant only -- the vehicle card surfaces Mark sold,
        // Reserve, On demo, Archive.  These are dealer commercial
        // actions; an OEM tenant has no use for them.  404 (not 403)
        // so the page doesn't even exist from their portal.
        abort_unless($user?->company()?->isDealer(), 404);
        abort_unless($user?->hasPermission('view_dealer_stock'), 403);
        abort_unless(
            in_array($dealerStock->dealer_company_id, $user->visibleCompanyIds(), true),
            403,
            'This vehicle is not on your dealership.'
        );

        $this->stock = $dealerStock;

        // Pre-fill the sale + reserve form fields with any existing
        // commercial data on the row so reserving and then selling is
        // one continuous flow rather than re-typing the customer.
        $this->salesperson_user_id  = $dealerStock->salesperson_user_id;
        $this->sale_customer_name   = (string) ($dealerStock->sale_customer_name  ?? '');
        $this->sale_customer_phone  = (string) ($dealerStock->sale_customer_phone ?? '');
        $this->sale_customer_email  = (string) ($dealerStock->sale_customer_email ?? '');
    }

    // --- Fitment chain actions ---------------------------------------

    public function toggleFitmentForm(): void
    {
        $this->showFitmentForm = !$this->showFitmentForm;
        if ($this->showFitmentForm) {
            $this->resetFitmentForm();
        }
    }

    protected function resetFitmentForm(): void
    {
        $this->editingFitmentId          = null;
        $this->fitment_body_builder_id   = null;
        $this->fitment_type              = '';
        $this->fitment_notes             = '';
        $this->fitment_share_with_bb     = false;
        $this->fitment_share_salesperson = '';
        $this->fitment_share_end_customer = '';
    }

    public function editFitment(int $fitmentId): void
    {
        $this->ensureManage();
        $leg = $this->stock->fitments()->findOrFail($fitmentId);

        $this->editingFitmentId           = $leg->id;
        $this->fitment_body_builder_id    = $leg->body_builder_company_id;
        $this->fitment_type               = (string) ($leg->fitment_type ?? '');
        $this->fitment_notes              = (string) ($leg->notes ?? '');
        $this->fitment_share_with_bb      = (bool) $leg->share_with_bb;
        $this->fitment_share_salesperson  = (string) ($leg->share_salesperson ?? '');
        $this->fitment_share_end_customer = (string) ($leg->share_end_customer ?? '');
        $this->showFitmentForm            = true;
    }

    public function saveFitment(): void
    {
        $this->ensureManage();

        $this->validate([
            'fitment_body_builder_id'    => 'required|integer|exists:companies,id',
            'fitment_type'               => 'nullable|string|max:80',
            'fitment_notes'              => 'nullable|string|max:2000',
            'fitment_share_with_bb'      => 'boolean',
            'fitment_share_salesperson'  => 'nullable|string|max:120',
            'fitment_share_end_customer' => 'nullable|string|max:200',
        ]);

        $payload = [
            'body_builder_company_id' => $this->fitment_body_builder_id,
            'fitment_type'            => $this->fitment_type ?: null,
            'notes'                   => $this->fitment_notes ?: null,
            'share_with_bb'           => $this->fitment_share_with_bb,
            'share_salesperson'       => $this->fitment_share_salesperson ?: null,
            'share_end_customer'      => $this->fitment_share_end_customer ?: null,
        ];

        if ($this->editingFitmentId) {
            $this->stock->fitments()->whereKey($this->editingFitmentId)->update($payload);
            session()->flash('success', 'Fitment step updated.');
        } else {
            $nextSequence = ((int) $this->stock->fitments()->max('sequence')) + 1;
            $this->stock->fitments()->create($payload + [
                'sequence' => $nextSequence,
                'status'   => DealerStockFitment::STATUS_PLANNED,
            ]);
            session()->flash('success', 'Fitment step added to the build chain.');
        }

        $this->showFitmentForm = false;
        $this->resetFitmentForm();
    }

    public function startFitment(int $fitmentId): void
    {
        $this->ensureManage();
        $leg = $this->stock->fitments()->findOrFail($fitmentId);

        // Only one leg can be in progress at a time; if another is
        // active, complete it implicitly so the chain stays sane.
        $this->stock->fitments()
            ->where('status', DealerStockFitment::STATUS_IN_PROGRESS)
            ->where('id', '!=', $leg->id)
            ->update([
                'status'       => DealerStockFitment::STATUS_COMPLETED,
                'completed_at' => now(),
            ]);

        $leg->update([
            'status'     => DealerStockFitment::STATUS_IN_PROGRESS,
            'started_at' => $leg->started_at ?? now(),
        ]);

        session()->flash('success', 'Fitment step marked in progress.');
    }

    public function completeFitment(int $fitmentId): void
    {
        $this->ensureManage();
        $leg = $this->stock->fitments()->findOrFail($fitmentId);

        $leg->update([
            'status'       => DealerStockFitment::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);

        session()->flash('success', 'Fitment step completed.');
    }

    public function cancelFitment(int $fitmentId): void
    {
        $this->ensureManage();
        $leg = $this->stock->fitments()->findOrFail($fitmentId);

        $leg->update(['status' => DealerStockFitment::STATUS_CANCELLED]);

        session()->flash('success', 'Fitment step cancelled.');
    }

    public function deleteFitment(int $fitmentId): void
    {
        $this->ensureManage();
        $leg = $this->stock->fitments()->findOrFail($fitmentId);

        // Refuse to delete legs that have actually happened.  Only a
        // planned-but-never-started leg may be removed outright.
        abort_unless($leg->status === DealerStockFitment::STATUS_PLANNED, 422, 'Only planned fitment steps can be deleted; cancel completed legs instead.');

        $leg->delete();

        session()->flash('success', 'Planned fitment step removed.');
    }

    protected function ensureManage(): User
    {
        $user = auth()->user();
        abort_unless($user?->hasPermission('manage_dealer_stock'), 403, 'You may not manage stock.');
        abort_unless(
            in_array($this->stock->dealer_company_id, $user->visibleCompanyIds(), true),
            403
        );
        return $user;
    }

    public function toggleReserveForm(): void
    {
        $this->showReserveForm = !$this->showReserveForm;
        $this->showSaleForm = false;
        $this->showDemoForm = false;
    }

    public function toggleSaleForm(): void
    {
        $this->showSaleForm = !$this->showSaleForm;
        $this->showReserveForm = false;
        $this->showDemoForm = false;
    }

    public function toggleDemoForm(): void
    {
        $this->showDemoForm = !$this->showDemoForm;
        $this->showReserveForm = false;
        $this->showSaleForm = false;
    }

    /**
     * Reserve / re-reserve a vehicle: assign salesperson + customer
     * while the unit is still on the floor.  Acts as a commercial
     * hold ahead of the final sale.  Phone + email optional; name
     * is the minimum we need to know "who is this being held for".
     *
     * Re-running this on an already-reserved row updates the
     * assignment without resetting reserved_at -- that timestamp
     * captures the original reserve event and stays put until the
     * reserve is cleared.
     */
    public function reserveStock(): void
    {
        $this->ensureManage();

        abort_unless(
            in_array($this->stock->status, [DealerStock::STATUS_AVAILABLE, DealerStock::STATUS_RESERVED], true),
            422,
            'Only available stock can be reserved.'
        );

        $this->validate([
            'salesperson_user_id'  => 'nullable|integer|exists:users,id',
            'sale_customer_name'   => 'required|string|max:255',
            'sale_customer_phone'  => 'nullable|string|max:50',
            'sale_customer_email'  => 'nullable|email|max:255',
        ]);

        $this->stock->update([
            'status'              => DealerStock::STATUS_RESERVED,
            'salesperson_user_id' => $this->salesperson_user_id ?: null,
            'sale_customer_name'  => $this->sale_customer_name,
            'sale_customer_phone' => $this->sale_customer_phone ?: null,
            'sale_customer_email' => $this->sale_customer_email ?: null,
            // Preserve the original reserve timestamp on edits.
            'reserved_at'         => $this->stock->reserved_at ?: now(),
        ]);

        session()->flash('success', "Reserved {$this->stock->vin} for {$this->sale_customer_name}.");
        $this->showReserveForm = false;
    }

    /**
     * Clear a reserve -- the customer fell off, or the dealer is
     * cancelling the hold.  Returns the unit to AVAILABLE and wipes
     * the customer fields so the next reserve starts clean.
     */
    public function clearReserve(): void
    {
        $this->ensureManage();

        abort_unless($this->stock->status === DealerStock::STATUS_RESERVED, 422, 'This vehicle is not reserved.');

        $this->stock->update([
            'status'              => DealerStock::STATUS_AVAILABLE,
            'salesperson_user_id' => null,
            'sale_customer_name'  => null,
            'sale_customer_phone' => null,
            'sale_customer_email' => null,
            'reserved_at'         => null,
        ]);

        // Reset the form fields so the page doesn't re-show stale
        // customer data after a clear.
        $this->salesperson_user_id = null;
        $this->sale_customer_name  = '';
        $this->sale_customer_phone = '';
        $this->sale_customer_email = '';

        session()->flash('success', "Reserve cleared — {$this->stock->vin} is back in available stock.");
    }

    public function markSold(): void
    {
        $this->ensureManage();

        abort_unless(
            in_array($this->stock->status, [DealerStock::STATUS_AVAILABLE, DealerStock::STATUS_RESERVED], true),
            422,
            'Only available or reserved stock can be marked sold.'
        );

        $this->validate([
            'salesperson_user_id'  => 'nullable|integer|exists:users,id',
            'sale_customer_name'   => 'required|string|max:255',
            'sale_customer_phone'  => 'nullable|string|max:50',
            'sale_customer_email'  => 'nullable|email|max:255',
        ]);

        $this->stock->update([
            'status'              => DealerStock::STATUS_SOLD,
            'salesperson_user_id' => $this->salesperson_user_id ?: null,
            'sale_customer_name'  => $this->sale_customer_name,
            'sale_customer_phone' => $this->sale_customer_phone ?: null,
            'sale_customer_email' => $this->sale_customer_email ?: null,
            'sold_at'             => now(),
            // reserved_at stays as a historical marker if it was set --
            // the timeline panel uses it to render Reserved -> Sold.
        ]);

        session()->flash('success', "Marked {$this->stock->vin} as sold to {$this->sale_customer_name}.");
        $this->showSaleForm = false;
    }

    /**
     * Reverse a sale.  Commercial deals get re-shuffled all the time
     * -- a chassis is swapped, a customer changes spec, finance falls
     * through -- so a "sold" mark stays easy to undo while the row is
     * still on the active ledger.  Archived rows are off-ledger and
     * are not reversible here.
     */
    public function reverseSale(): void
    {
        $this->ensureManage();

        abort_unless($this->stock->status === DealerStock::STATUS_SOLD, 422, 'This vehicle is not marked sold.');

        $this->stock->update([
            'status'              => DealerStock::STATUS_AVAILABLE,
            'salesperson_user_id' => null,
            'sale_customer_name'  => null,
            'sale_customer_phone' => null,
            'sale_customer_email' => null,
            'sold_at'             => null,
            'reserved_at'         => null,
        ]);

        $this->salesperson_user_id = null;
        $this->sale_customer_name  = '';
        $this->sale_customer_phone = '';
        $this->sale_customer_email = '';

        session()->flash('success', "Sale reversed — {$this->stock->vin} is back in available stock.");
    }

    public function sendOnDemo(): void
    {
        $this->ensureManage();

        $this->validate([
            'demo_customer_name'   => 'required|string|max:255',
            'demo_customer_phone'  => 'nullable|string|max:50',
            'demo_customer_email'  => 'nullable|email|max:255',
            'demo_due_back_at'     => 'nullable|date|after:today',
        ]);

        $this->stock->update([
            'status'                => DealerStock::STATUS_DEMO,
            'previous_location_type' => $this->stock->current_location_type,
            'current_location_type' => DealerStock::LOCATION_ON_DEMO,
            'demo_customer_name'    => $this->demo_customer_name,
            'demo_customer_phone'   => $this->demo_customer_phone ?: null,
            'demo_customer_email'   => $this->demo_customer_email ?: null,
            'demo_started_at'       => now(),
            'demo_due_back_at'      => $this->demo_due_back_at ?: null,
        ]);

        session()->flash('success', "Sent {$this->stock->vin} out on demo with {$this->demo_customer_name}.");
        $this->showDemoForm = false;
    }

    public function returnFromDemo(): void
    {
        $this->ensureManage();

        // "Return from demo" restores the previous bucket so the
        // vehicle shows up on the right card again.  Default to
        // premises if we never captured a previous bucket (e.g. the
        // demo was set without going through this page).
        $restoreBucket = $this->stock->previous_location_type ?? DealerStock::LOCATION_PREMISES;

        $this->stock->update([
            'status'                => DealerStock::STATUS_AVAILABLE,
            'current_location_type' => $restoreBucket,
            'previous_location_type' => null,
            'demo_customer_name'    => null,
            'demo_customer_phone'   => null,
            'demo_customer_email'   => null,
            'demo_started_at'       => null,
            'demo_due_back_at'      => null,
        ]);

        session()->flash('success', "{$this->stock->vin} is back from demo.");
    }

    public function archive(): void
    {
        $this->ensureManage();

        $this->stock->update([
            'status'      => DealerStock::STATUS_ARCHIVED,
            'archived_at' => now(),
        ]);

        session()->flash('success', "Archived {$this->stock->vin}.");
        $this->redirect(route('customer.stock.index'), navigate: true);
    }

    public function with(): array
    {
        $user = auth()->user();

        // Salesperson picker: any user attached to this dealership
        // who carries a sales_* role.  We accept any role that
        // starts with sales_ to avoid hard-coding the exact list.
        $salesPeople = User::query()
            ->where('is_active', true)
            ->whereHas('companies', fn ($c) => $c->where('companies.id', $this->stock->dealer_company_id))
            ->whereHas('roles', fn ($r) => $r->where(function ($q) {
                $q->where('slug', 'like', 'sales\\_%')
                    ->orWhere('slug', 'customer_admin')
                    ->orWhere('slug', 'customer_owner');
            }))
            ->orderBy('name')
            ->get(['id', 'name']);

        // Eager-load the current job + locations so the timeline panel
        // doesn't N+1 when rendering pickup / delivery names, plus the
        // full fitment chain (ordered) with its BB names.
        $this->stock->loadMissing([
            'currentJob:id,job_number,status,scheduled_date,pickup_location_id,delivery_location_id,vin',
            'currentJob.pickupLocation:id,company_name,city',
            'currentJob.deliveryLocation:id,company_name,city',
            'salesperson:id,name',
            'currentLocation:id,company_name,city,company_id',
            'fitments.bodyBuilder:id,name',
        ]);

        // Body-builder picker for the fitment form.  Includes every BB
        // the dealer has explicitly linked + any BB the vehicle has
        // already passed through (so historical legs are editable
        // even if the link was later unpaused), ordered by name.
        $bodyBuilderOptions = Company::query()
            ->where('type', Company::TYPE_BODY_BUILDER)
            ->where(function ($q) {
                $q->whereHas('linkedDealers', fn ($l) => $l->where('companies.id', $this->stock->dealer_company_id))
                  ->orWhereIn('id', $this->stock->fitments->pluck('body_builder_company_id')->filter()->all());
            })
            ->orderBy('name')
            ->get(['id', 'name']);

        return [
            'canManageStock'     => $user->hasPermission('manage_dealer_stock'),
            'salesPeople'        => $salesPeople,
            'bodyBuilderOptions' => $bodyBuilderOptions,
            'bucketLabels'   => [
                DealerStock::LOCATION_PREMISES     => 'At premises',
                DealerStock::LOCATION_BODY_BUILDER => 'Body builder',
                DealerStock::LOCATION_STORAGE      => 'Other storage',
                DealerStock::LOCATION_IN_TRANSIT   => 'In transit',
                DealerStock::LOCATION_ON_DEMO      => 'On demo',
                DealerStock::LOCATION_DELIVERED    => 'Delivered to dealer',
            ],
        ];
    }
};
?>

<div>
    <x-slot:header>
        <div class="flex items-center gap-2">
            <a href="{{ route('customer.stock.index') }}" class="text-slate-400 hover:text-slate-600">←</a>
            <span>{{ $stock->brand?->name }} {{ $stock->model_name }}</span>
            <span class="text-sm text-slate-500">· {{ $stock->vin }}</span>
        </div>
    </x-slot:header>

    @if(session('success'))
        <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('success') }}</div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
        {{-- Vehicle details --}}
        <div class="lg:col-span-2 rounded-xl border border-slate-200 bg-white p-6">
            <h2 class="text-base font-semibold text-slate-900 mb-3">Vehicle</h2>
            <dl class="grid grid-cols-2 gap-3 text-sm">
                <div><dt class="text-xs text-slate-500">Brand</dt><dd class="text-slate-900">{{ $stock->brand?->name ?? '—' }}</dd></div>
                <div><dt class="text-xs text-slate-500">Model</dt><dd class="text-slate-900">{{ $stock->model_name ?? '—' }}</dd></div>
                <div><dt class="text-xs text-slate-500">Variant</dt><dd class="text-slate-900">{{ $stock->variant ?? '—' }}</dd></div>
                <div><dt class="text-xs text-slate-500">Suffix</dt><dd class="text-slate-900">{{ $stock->suffix ?? '—' }}</dd></div>
                <div><dt class="text-xs text-slate-500">VIN</dt><dd class="font-mono text-slate-900">{{ $stock->vin }}</dd></div>
                <div><dt class="text-xs text-slate-500">Engine number</dt><dd class="font-mono text-slate-900">{{ $stock->engine_number ?? '—' }}</dd></div>
                <div><dt class="text-xs text-slate-500">Colour</dt><dd class="text-slate-900">{{ $stock->colour ?? '—' }}</dd></div>
                <div><dt class="text-xs text-slate-500">Registration</dt><dd class="font-mono text-slate-900">{{ $stock->registration ?? '—' }}</dd></div>
                <div><dt class="text-xs text-slate-500">Model year</dt><dd class="text-slate-900">{{ $stock->model_year ?? '—' }}</dd></div>
                <div><dt class="text-xs text-slate-500">Dealership</dt><dd class="text-slate-900">{{ $stock->dealerCompany?->name ?? '—' }}</dd></div>
            </dl>

            <hr class="my-4 border-slate-100">

            <h3 class="text-sm font-semibold text-slate-900 mb-2">Where</h3>
            <p class="text-sm text-slate-700">
                Bucket: <strong>{{ $bucketLabels[$stock->current_location_type] ?? str_replace('_', ' ', $stock->current_location_type) }}</strong>
                @if($stock->currentLocation)
                    · at {{ $stock->currentLocation->company_name }}{{ $stock->currentLocation->city ? ', ' . $stock->currentLocation->city : '' }}
                @endif
            </p>
            <p class="text-sm text-slate-500 mt-1">Status: <strong>{{ ucfirst($stock->status) }}</strong></p>

            {{-- Reserved details surface as soon as the unit is held,
                 so the dealer doesn't have to dig into a sale form just
                 to see who the vehicle is being kept for. --}}
            @if($stock->status === DealerStock::STATUS_RESERVED)
                <hr class="my-4 border-slate-100">
                <h3 class="text-sm font-semibold text-slate-900 mb-2">Reserved</h3>
                <dl class="grid grid-cols-2 gap-3 text-sm">
                    <div><dt class="text-xs text-slate-500">Customer</dt><dd class="text-slate-900">{{ $stock->sale_customer_name }}</dd></div>
                    <div><dt class="text-xs text-slate-500">Phone</dt><dd class="text-slate-900">{{ $stock->sale_customer_phone ?? '—' }}</dd></div>
                    <div><dt class="text-xs text-slate-500">Email</dt><dd class="text-slate-900">{{ $stock->sale_customer_email ?? '—' }}</dd></div>
                    <div><dt class="text-xs text-slate-500">Salesperson</dt><dd class="text-slate-900">{{ $stock->salesperson?->name ?? '—' }}</dd></div>
                    <div><dt class="text-xs text-slate-500">Reserved at</dt><dd class="text-slate-900">{{ optional($stock->reserved_at)->format('d M Y H:i') ?? '—' }}</dd></div>
                </dl>
            @endif

            @if($stock->status === DealerStock::STATUS_SOLD)
                <hr class="my-4 border-slate-100">
                <h3 class="text-sm font-semibold text-slate-900 mb-2">Sale</h3>
                <dl class="grid grid-cols-2 gap-3 text-sm">
                    <div><dt class="text-xs text-slate-500">Customer</dt><dd class="text-slate-900">{{ $stock->sale_customer_name }}</dd></div>
                    <div><dt class="text-xs text-slate-500">Phone</dt><dd class="text-slate-900">{{ $stock->sale_customer_phone ?? '—' }}</dd></div>
                    <div><dt class="text-xs text-slate-500">Email</dt><dd class="text-slate-900">{{ $stock->sale_customer_email ?? '—' }}</dd></div>
                    <div><dt class="text-xs text-slate-500">Sold by</dt><dd class="text-slate-900">{{ $stock->salesperson?->name ?? '—' }}</dd></div>
                    <div><dt class="text-xs text-slate-500">Sold at</dt><dd class="text-slate-900">{{ optional($stock->sold_at)->format('d M Y H:i') ?? '—' }}</dd></div>
                </dl>
                <p class="mt-3 text-xs text-slate-500">Once the vehicle has left your books, archive this row to clear it from the active ledger.</p>
            @endif

            {{-- Commercial lifecycle.  Three steps -- Available,
                 Reserved, Sold.  "Sold" is the end of the line; once
                 the vehicle has left the dealer's books the row is
                 archived (separate action, separate concept). --}}
            <hr class="my-4 border-slate-100">
            <h3 class="text-sm font-semibold text-slate-900 mb-3">Lifecycle</h3>
            @php
                $reserved   = $stock->reserved_at;
                $sold       = $stock->sold_at;
                $currentJob = $stock->currentJob;
                $steps = [
                    [
                        'label'  => 'Available',
                        'done'   => true,
                        'detail' => 'On the books · ' . optional($stock->created_at)->format('d M Y'),
                    ],
                    [
                        'label'  => 'Reserved',
                        'done'   => (bool) $reserved,
                        'detail' => $reserved
                            ? $reserved->format('d M Y') . ($stock->sale_customer_name ? ' · ' . $stock->sale_customer_name : '')
                            : 'Not yet — Reserve to assign a customer',
                    ],
                    [
                        'label'  => 'Sold',
                        'done'   => (bool) $sold,
                        'detail' => $sold
                            ? $sold->format('d M Y') . ($stock->salesperson?->name ? ' · ' . $stock->salesperson->name : '')
                            : 'Not yet',
                    ],
                ];
            @endphp
            <ol class="space-y-2.5">
                @foreach($steps as $step)
                    <li class="flex items-start gap-3">
                        <span @class([
                            'mt-0.5 inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full border text-[10px] font-bold',
                            'bg-emerald-600 border-emerald-600 text-white' => $step['done'],
                            'bg-white border-slate-300 text-slate-400'      => !$step['done'],
                        ])>
                            @if($step['done'])
                                ✓
                            @else
                                ·
                            @endif
                        </span>
                        <div class="flex-1 min-w-0">
                            <p @class([
                                'text-sm font-medium',
                                'text-slate-900' => $step['done'],
                                'text-slate-500' => !$step['done'],
                            ])>{{ $step['label'] }}</p>
                            <p class="text-xs text-slate-500">{{ $step['detail'] }}</p>
                        </div>
                    </li>
                @endforeach
            </ol>

            {{-- Active ProSelver movement (transport job).  Shown
                 separately because it can happen at any commercial
                 status: a chassis can be in transit before it's even
                 reserved (manufacturer drop) or after a sale (delivery
                 to the customer). --}}
            <hr class="my-4 border-slate-100">
            <h3 class="text-sm font-semibold text-slate-900 mb-2">Transport movement</h3>
            @if($currentJob)
                <div class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm">
                    <div class="flex items-center justify-between gap-3">
                        <div class="min-w-0">
                            <a href="{{ route('customer.orders.show', $currentJob) }}" class="font-semibold text-blue-700 hover:text-blue-900">
                                {{ $currentJob->job_number ?? 'Movement' }}
                            </a>
                            <span class="ml-2 text-xs text-slate-500">{{ ucfirst(str_replace('_', ' ', $currentJob->status ?? 'pending')) }}</span>
                        </div>
                        @if($currentJob->scheduled_date)
                            <span class="text-xs text-slate-500">{{ \Illuminate\Support\Carbon::parse($currentJob->scheduled_date)->format('d M Y') }}</span>
                        @endif
                    </div>
                    <p class="mt-1 text-xs text-slate-600 truncate">
                        {{ $currentJob->pickupLocation?->company_name ?? '—' }}
                        →
                        {{ $currentJob->deliveryLocation?->company_name ?? '—' }}
                    </p>
                </div>
            @else
                <p class="text-sm text-slate-500">No active transport job linked to this VIN.</p>
            @endif

            {{-- Fitment chain.  Each leg is an ordered stop in the
                 build process (dropside, crane, fridge body, fridge
                 unit, paint, ...).  Each leg has its own notes,
                 share-with-BB toggle and shared salesperson + end
                 customer, so the dealer can decide per-leg what to
                 disclose. --}}
            @if($canManageStock || $stock->fitments->isNotEmpty())
                <hr class="my-4 border-slate-100">
                <div class="flex items-start justify-between gap-3 mb-3">
                    <div>
                        <h3 class="text-sm font-semibold text-slate-900">Fitment chain</h3>
                        <p class="text-xs text-slate-500 mt-0.5">Plan one or more body-builder / fitment stops for this vehicle. Each step has its own notes and sharing controls.</p>
                    </div>
                    @if($canManageStock)
                        <button wire:click="toggleFitmentForm"
                                class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                            {{ $showFitmentForm ? 'Close' : ($editingFitmentId ? 'Edit step' : '+ Add fitment step') }}
                        </button>
                    @endif
                </div>

                @if($stock->fitments->isEmpty())
                    <p class="rounded-md border border-dashed border-slate-300 bg-slate-50 px-3 py-2 text-xs text-slate-500">No fitment steps planned yet. Add one to track build chain stops separately.</p>
                @else
                    <ol class="space-y-2">
                        @foreach($stock->fitments as $leg)
                            @php
                                $statusClass = match($leg->status) {
                                    DealerStockFitment::STATUS_PLANNED     => 'border-slate-200 bg-slate-50 text-slate-700',
                                    DealerStockFitment::STATUS_IN_PROGRESS => 'border-amber-300 bg-amber-50 text-amber-800',
                                    DealerStockFitment::STATUS_COMPLETED   => 'border-emerald-300 bg-emerald-50 text-emerald-800',
                                    DealerStockFitment::STATUS_CANCELLED   => 'border-rose-200 bg-rose-50 text-rose-700',
                                    default                                => 'border-slate-200 bg-white text-slate-700',
                                };
                            @endphp
                            <li class="rounded-lg border border-slate-200 bg-white p-3">
                                <div class="flex flex-wrap items-start justify-between gap-2">
                                    <div class="min-w-0">
                                        <div class="flex items-center gap-2 text-sm font-semibold text-slate-900">
                                            <span class="inline-flex h-5 min-w-[1.5rem] items-center justify-center rounded-full bg-slate-100 px-1.5 text-[11px] font-bold text-slate-600">{{ $leg->sequence }}</span>
                                            <span>{{ $leg->bodyBuilder?->name ?? 'Unknown body builder' }}</span>
                                            @if($leg->fitment_type)
                                                <span class="text-xs font-normal text-slate-500">— {{ $leg->fitment_type }}</span>
                                            @endif
                                        </div>
                                        <div class="mt-1 flex flex-wrap items-center gap-2 text-[11px] text-slate-500">
                                            <span class="inline-flex items-center rounded-full border px-2 py-0.5 font-semibold {{ $statusClass }}">{{ $leg->statusLabel() }}</span>
                                            @if($leg->started_at)
                                                <span>Started {{ $leg->started_at->format('d M Y') }}</span>
                                            @endif
                                            @if($leg->completed_at)
                                                <span>· Completed {{ $leg->completed_at->format('d M Y') }}</span>
                                            @endif
                                            @if($leg->internal_job_number)
                                                <span>· BB job #: <strong class="text-slate-700">{{ $leg->internal_job_number }}</strong></span>
                                            @endif
                                            @if($leg->share_with_bb)
                                                <span class="rounded-full bg-blue-100 px-1.5 py-0.5 text-[10px] font-semibold text-blue-800">Shared with BB</span>
                                            @endif
                                        </div>
                                        @if($leg->notes)
                                            <p class="mt-2 whitespace-pre-line text-xs text-slate-700">{{ $leg->notes }}</p>
                                        @endif
                                        @if($leg->share_with_bb && ($leg->share_salesperson || $leg->share_end_customer))
                                            <dl class="mt-2 grid grid-cols-2 gap-2 text-[11px] text-slate-600">
                                                @if($leg->share_salesperson)
                                                    <div><dt class="text-slate-400">Shared salesperson</dt><dd>{{ $leg->share_salesperson }}</dd></div>
                                                @endif
                                                @if($leg->share_end_customer)
                                                    <div><dt class="text-slate-400">Shared end customer</dt><dd>{{ $leg->share_end_customer }}</dd></div>
                                                @endif
                                            </dl>
                                        @endif
                                    </div>
                                    @if($canManageStock)
                                        <div class="flex flex-wrap items-center gap-1">
                                            @if($leg->status === DealerStockFitment::STATUS_PLANNED)
                                                <button wire:click="startFitment({{ $leg->id }})"
                                                        class="rounded border border-amber-300 bg-white px-2 py-1 text-[11px] font-semibold text-amber-700 hover:bg-amber-50">Start</button>
                                            @endif
                                            @if($leg->status === DealerStockFitment::STATUS_IN_PROGRESS)
                                                <button wire:click="completeFitment({{ $leg->id }})"
                                                        wire:confirm="Mark this fitment step complete?"
                                                        class="rounded border border-emerald-300 bg-white px-2 py-1 text-[11px] font-semibold text-emerald-700 hover:bg-emerald-50">Complete</button>
                                            @endif
                                            @if(in_array($leg->status, [DealerStockFitment::STATUS_PLANNED, DealerStockFitment::STATUS_IN_PROGRESS], true))
                                                <button wire:click="editFitment({{ $leg->id }})"
                                                        class="rounded border border-slate-300 bg-white px-2 py-1 text-[11px] font-semibold text-slate-700 hover:bg-slate-50">Edit</button>
                                                <button wire:click="cancelFitment({{ $leg->id }})"
                                                        wire:confirm="Cancel this fitment step?"
                                                        class="rounded border border-rose-200 bg-white px-2 py-1 text-[11px] font-semibold text-rose-700 hover:bg-rose-50">Cancel</button>
                                            @endif
                                            @if($leg->status === DealerStockFitment::STATUS_PLANNED)
                                                <button wire:click="deleteFitment({{ $leg->id }})"
                                                        wire:confirm="Delete this planned step? This cannot be undone."
                                                        class="rounded border border-rose-300 bg-white px-2 py-1 text-[11px] font-semibold text-rose-700 hover:bg-rose-50">Delete</button>
                                            @endif
                                        </div>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ol>
                @endif

                @if($canManageStock && $showFitmentForm)
                    <form wire:submit="saveFitment" class="mt-3 rounded-lg border border-slate-300 bg-slate-50 p-3">
                        <h4 class="text-sm font-semibold text-slate-900 mb-2">{{ $editingFitmentId ? 'Edit fitment step' : 'New fitment step' }}</h4>

                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div>
                                <label class="block text-xs font-medium text-slate-600 mb-1">Body builder *</label>
                                <select wire:model="fitment_body_builder_id" class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                                    <option value="">— select body builder —</option>
                                    @foreach($bodyBuilderOptions as $bb)
                                        <option value="{{ $bb->id }}">{{ $bb->name }}</option>
                                    @endforeach
                                </select>
                                @error('fitment_body_builder_id')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-slate-600 mb-1">Fitment type</label>
                                <input wire:model="fitment_type" type="text" list="fitment-type-suggestions"
                                       class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm"
                                       placeholder="e.g. Dropside body, Crane mount, Fridge unit">
                                <datalist id="fitment-type-suggestions">
                                    @foreach(DealerStockFitment::SUGGESTED_TYPES as $type)
                                        <option value="{{ $type }}"></option>
                                    @endforeach
                                </datalist>
                            </div>
                        </div>

                        <div class="mt-3">
                            <label class="block text-xs font-medium text-slate-600 mb-1">Build notes / instructions</label>
                            <textarea wire:model="fitment_notes" rows="3" class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm" placeholder="What this BB is fitting (body type, colour, accessories, deadlines...)"></textarea>
                        </div>

                        <div class="mt-3 rounded-md border border-slate-200 bg-white p-3">
                            <label class="inline-flex items-center gap-2 text-xs">
                                <input type="checkbox" wire:model.live="fitment_share_with_bb" class="h-4 w-4 rounded border-slate-300 text-blue-600">
                                <span class="font-medium text-slate-700">Share these details with this body builder</span>
                            </label>
                            <p class="mt-1 text-[11px] text-slate-500">When ON, the BB's yard portal shows the salesperson + end customer below. Leave OFF to keep them confidential for this leg.</p>

                            @if($fitment_share_with_bb)
                                <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                                    <div>
                                        <label class="block text-xs font-medium text-slate-600 mb-1">Salesperson (shown to BB)</label>
                                        <input wire:model="fitment_share_salesperson" type="text"
                                               class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm"
                                               placeholder="e.g. {{ $stock->salesperson?->name ?: 'Sales rep' }}">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-slate-600 mb-1">End customer (shown to BB)</label>
                                        <input wire:model="fitment_share_end_customer" type="text"
                                               class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm"
                                               placeholder="e.g. ABC Logistics">
                                    </div>
                                </div>
                            @endif
                        </div>

                        <div class="mt-3 flex items-center gap-2">
                            <button type="submit" class="rounded-lg bg-slate-900 px-3 py-1.5 text-xs font-semibold text-white hover:bg-slate-700">
                                {{ $editingFitmentId ? 'Save changes' : 'Add to chain' }}
                            </button>
                            <button type="button" wire:click="toggleFitmentForm" class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">Cancel</button>
                        </div>
                    </form>
                @endif
            @endif

            @if($stock->status === DealerStock::STATUS_DEMO)
                <hr class="my-4 border-slate-100">
                <h3 class="text-sm font-semibold text-slate-900 mb-2">On demo</h3>
                <dl class="grid grid-cols-2 gap-3 text-sm">
                    <div><dt class="text-xs text-slate-500">Customer</dt><dd class="text-slate-900">{{ $stock->demo_customer_name }}</dd></div>
                    <div><dt class="text-xs text-slate-500">Phone</dt><dd class="text-slate-900">{{ $stock->demo_customer_phone ?? '—' }}</dd></div>
                    <div><dt class="text-xs text-slate-500">Started</dt><dd class="text-slate-900">{{ optional($stock->demo_started_at)->format('d M Y H:i') ?? '—' }}</dd></div>
                    <div><dt class="text-xs text-slate-500">Due back</dt><dd class="text-slate-900">{{ optional($stock->demo_due_back_at)->format('d M Y') ?? '—' }}</dd></div>
                </dl>
            @endif
        </div>

        {{-- Actions --}}
        <div class="rounded-xl border border-slate-200 bg-white p-6">
            <h2 class="text-base font-semibold text-slate-900 mb-3">Actions</h2>

            {{-- Book delivery is the most common action for a unit
                 already on the books, so it sits at the top of the
                 action stack and stays visible regardless of who can
                 mutate the row (it just opens the order create
                 form; permission checks live there).  Hidden once the
                 unit is in active transit or archived. --}}
            @if($stock->status !== DealerStock::STATUS_ARCHIVED
                && $stock->current_location_type !== DealerStock::LOCATION_IN_TRANSIT
                && $stock->current_location_type !== DealerStock::LOCATION_DELIVERED)
                @php
                    $bookParams = array_filter([
                        'vin'                => $stock->vin,
                        'pickup_location_id' => $stock->current_location_id,
                        'brand_id'           => $stock->brand_id,
                        'model_name'         => $stock->model_name,
                    ]);
                @endphp
                <a href="{{ route('customer.orders.create', $bookParams) }}"
                   class="mb-3 inline-flex items-center justify-center gap-2 rounded-lg bg-blue-600 px-3 py-2.5 text-sm font-semibold text-white hover:bg-blue-500 shadow-sm">
                    <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"><path d="M14 18V6a1 1 0 0 0-1-1H3a1 1 0 0 0-1 1v11a1 1 0 0 0 1 1h1"/><path d="M14 9h4l4 4v4a1 1 0 0 1-1 1h-1"/><circle cx="7" cy="18" r="2"/><circle cx="17" cy="18" r="2"/></svg>
                    Book delivery
                </a>
            @endif

            @if(!$canManageStock)
                <p class="text-sm text-slate-500">You don't have permission to mutate this vehicle.</p>
            @else
                <div class="flex flex-col gap-2">
                    @if(in_array($stock->status, [DealerStock::STATUS_AVAILABLE, DealerStock::STATUS_RESERVED], true))
                        <button wire:click="toggleReserveForm" class="rounded-lg bg-amber-600 px-3 py-2 text-sm font-semibold text-white hover:bg-amber-500">
                            @if($showReserveForm)
                                Cancel
                            @elseif($stock->status === DealerStock::STATUS_RESERVED)
                                Edit reserve
                            @else
                                Reserve
                            @endif
                        </button>
                    @endif

                    @if($stock->status === DealerStock::STATUS_RESERVED)
                        <button wire:click="clearReserve"
                                wire:confirm="Clear the reserve on {{ $stock->vin }}? It returns to available stock and the customer details are cleared."
                                class="rounded-lg border border-amber-300 bg-white px-3 py-2 text-sm font-semibold text-amber-700 hover:bg-amber-50">
                            Clear reserve
                        </button>
                    @endif

                    @if($stock->status !== DealerStock::STATUS_SOLD && $stock->status !== DealerStock::STATUS_ARCHIVED && $stock->status !== DealerStock::STATUS_DEMO)
                        <button wire:click="toggleSaleForm" class="rounded-lg bg-emerald-600 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-500">
                            {{ $showSaleForm ? 'Cancel sale' : ($stock->status === DealerStock::STATUS_RESERVED ? 'Mark sold (from reserve)' : 'Mark as sold') }}
                        </button>
                    @endif

                    @if($stock->status === DealerStock::STATUS_AVAILABLE)
                        <button wire:click="toggleDemoForm" class="rounded-lg bg-teal-600 px-3 py-2 text-sm font-semibold text-white hover:bg-teal-500">
                            {{ $showDemoForm ? 'Cancel demo' : 'Send out on demo' }}
                        </button>
                    @endif

                    @if($stock->status === DealerStock::STATUS_DEMO)
                        <button wire:click="returnFromDemo"
                                wire:confirm="Mark {{ $stock->vin }} as returned from demo?"
                                class="rounded-lg bg-blue-600 px-3 py-2 text-sm font-semibold text-white hover:bg-blue-500">
                            Return from demo
                        </button>
                    @endif

                    {{-- Sold rows keep an easy "reverse sale" escape
                         hatch while still on the active ledger -- chassis
                         swaps and spec changes happen even after the deal
                         is closed.  Once the vehicle has left the books
                         the dealer archives the row to close it out. --}}
                    @if($stock->status === DealerStock::STATUS_SOLD)
                        <button wire:click="reverseSale"
                                wire:confirm="Reverse the sale of {{ $stock->vin }}? It returns to available stock and the customer details are cleared."
                                class="rounded-lg border border-amber-300 bg-white px-3 py-2 text-sm font-semibold text-amber-700 hover:bg-amber-50">
                            Reverse sale
                        </button>
                    @endif

                    {{-- Delivery note is available for any live unit, not
                         just sold ones — most vehicles are handed over
                         straight from the dealer's own premises without a
                         transport job, so the dealer prints the note on
                         delivery regardless of the sale status here. --}}
                    @if($stock->status !== DealerStock::STATUS_ARCHIVED)
                        <a href="{{ route('dealer-stock.sale-delivery-note.download', $stock) }}" target="_blank" rel="noopener"
                           class="inline-flex items-center justify-center gap-2 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect width="12" height="8" x="6" y="14"/></svg>
                            Print delivery note
                        </a>
                    @endif

                    @if($stock->status !== DealerStock::STATUS_ARCHIVED)
                        <button wire:click="archive"
                                wire:confirm="Archive {{ $stock->vin }}? It will stop appearing in the active stock list."
                                class="rounded-lg border border-red-300 bg-white px-3 py-2 text-sm font-semibold text-red-700 hover:bg-red-50">
                            Archive
                        </button>
                    @endif
                </div>

                @if($showReserveForm)
                    <form wire:submit="reserveStock" class="mt-5 space-y-3 border-t border-slate-100 pt-4">
                        <h3 class="text-sm font-semibold text-slate-900">Reserve for customer</h3>
                        <p class="-mt-1 text-xs text-slate-500">Hold this vehicle for a buyer before final sale. You can update or clear the reserve at any time.</p>

                        <div>
                            <label class="block text-xs font-medium text-slate-600 mb-1">Salesperson</label>
                            <select wire:model="salesperson_user_id" class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                                <option value="">— select —</option>
                                @foreach($salesPeople as $sp)
                                    <option value="{{ $sp->id }}">{{ $sp->name }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-slate-600 mb-1">Customer name <span class="text-red-500">*</span></label>
                            <input wire:model="sale_customer_name" type="text" class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                            @error('sale_customer_name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-slate-600 mb-1">Phone</label>
                            <input wire:model="sale_customer_phone" type="text" class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm" placeholder="Optional">
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-slate-600 mb-1">Email</label>
                            <input wire:model="sale_customer_email" type="email" class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm" placeholder="Optional">
                            @error('sale_customer_email') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>

                        <button type="submit" class="w-full rounded-lg bg-amber-700 px-3 py-2 text-sm font-semibold text-white hover:bg-amber-600">
                            {{ $stock->status === DealerStock::STATUS_RESERVED ? 'Update reserve' : 'Save reserve' }}
                        </button>
                    </form>
                @endif

                @if($showSaleForm)
                    <form wire:submit="markSold" class="mt-5 space-y-3 border-t border-slate-100 pt-4">
                        <h3 class="text-sm font-semibold text-slate-900">Sale details</h3>
                        @if($stock->status === DealerStock::STATUS_RESERVED)
                            <p class="-mt-1 text-xs text-slate-500">Pre-filled from the existing reserve — adjust if anything has changed.</p>
                        @endif

                        <div>
                            <label class="block text-xs font-medium text-slate-600 mb-1">Salesperson</label>
                            <select wire:model="salesperson_user_id" class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                                <option value="">— select —</option>
                                @foreach($salesPeople as $sp)
                                    <option value="{{ $sp->id }}">{{ $sp->name }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-slate-600 mb-1">Customer name <span class="text-red-500">*</span></label>
                            <input wire:model="sale_customer_name" type="text" class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                            @error('sale_customer_name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-slate-600 mb-1">Phone</label>
                            <input wire:model="sale_customer_phone" type="text" class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-slate-600 mb-1">Email</label>
                            <input wire:model="sale_customer_email" type="email" class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                            @error('sale_customer_email') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>

                        <button type="submit" class="w-full rounded-lg bg-emerald-700 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-600">
                            Save sale
                        </button>
                    </form>
                @endif

                @if($showDemoForm)
                    <form wire:submit="sendOnDemo" class="mt-5 space-y-3 border-t border-slate-100 pt-4">
                        <h3 class="text-sm font-semibold text-slate-900">Demo details</h3>

                        <div>
                            <label class="block text-xs font-medium text-slate-600 mb-1">Customer name <span class="text-red-500">*</span></label>
                            <input wire:model="demo_customer_name" type="text" class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                            @error('demo_customer_name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-slate-600 mb-1">Phone</label>
                            <input wire:model="demo_customer_phone" type="text" class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-slate-600 mb-1">Email</label>
                            <input wire:model="demo_customer_email" type="email" class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                            @error('demo_customer_email') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-slate-600 mb-1">Due back</label>
                            <input wire:model="demo_due_back_at" type="date" class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                            @error('demo_due_back_at') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>

                        <button type="submit" class="w-full rounded-lg bg-teal-700 px-3 py-2 text-sm font-semibold text-white hover:bg-teal-600">
                            Send out on demo
                        </button>
                    </form>
                @endif
            @endif
        </div>
    </div>
</div>
