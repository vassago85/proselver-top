<?php

use App\Models\DealerStock;
use App\Models\Role;
use App\Models\User;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/*
 * Per-vehicle stock view. The four actions the dealer can drive
 * from this page:
 *
 *   1. Mark as sold       -- captures salesperson + customer details,
 *                            stamps sold_at, flips status to SOLD.
 *   2. Send out on demo   -- captures customer + due-back date,
 *                            stamps demo_started_at, swings location
 *                            to ON_DEMO and status to DEMO.
 *   3. Return from demo   -- clears the demo block, restores the
 *                            previous bucket (or premises if unknown).
 *   4. Archive            -- soft-archives the row so it stops
 *                            showing up on dashboards / lists.
 *
 * Each action is an isolated method protected by manage_dealer_stock
 * and a tenancy check ($stock->dealer_company_id in
 * visibleCompanyIds()).
 */
new #[Layout('components.layouts.app')] class extends Component {
    public DealerStock $stock;

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

    public function mount(DealerStock $dealerStock): void
    {
        $user = auth()->user();
        abort_unless($user?->hasPermission('view_dealer_stock'), 403);
        abort_unless(
            in_array($dealerStock->dealer_company_id, $user->visibleCompanyIds(), true),
            403,
            'This vehicle is not on your dealership.'
        );

        $this->stock = $dealerStock;
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

    public function toggleSaleForm(): void
    {
        $this->showSaleForm = !$this->showSaleForm;
        $this->showDemoForm = false;
    }

    public function toggleDemoForm(): void
    {
        $this->showDemoForm = !$this->showDemoForm;
        $this->showSaleForm = false;
    }

    public function markSold(): void
    {
        $this->ensureManage();

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
        ]);

        session()->flash('success', "Marked {$this->stock->vin} as sold to {$this->sale_customer_name}.");
        $this->showSaleForm = false;
    }

    /**
     * Reverse a sale that hasn't been delivered yet.
     *
     * Commercial deals get re-shuffled all the time -- a chassis is
     * swapped for another for logistics reasons, a customer changes
     * spec, finance falls through -- so a "sold" mark must stay easy
     * to undo right up until the vehicle is physically delivered.
     * Once delivered_at is stamped (either here via Mark as delivered
     * or by the movement linker on a delivery job) the sale is final
     * and this action is refused.
     */
    public function reverseSale(): void
    {
        $this->ensureManage();

        abort_unless($this->stock->status === DealerStock::STATUS_SOLD, 422, 'This vehicle is not marked sold.');
        abort_unless($this->stock->delivered_at === null, 422, 'A delivered sale cannot be reversed here.');

        $this->stock->update([
            'status'              => DealerStock::STATUS_AVAILABLE,
            'salesperson_user_id' => null,
            'sale_customer_name'  => null,
            'sale_customer_phone' => null,
            'sale_customer_email' => null,
            'sold_at'             => null,
        ]);

        session()->flash('success', "Sale reversed — {$this->stock->vin} is back in available stock.");
    }

    /**
     * Lock the sale in: the customer has taken delivery, so stamp
     * delivered_at and move the unit into the delivered bucket. After
     * this the sale can no longer be reversed from this page.
     */
    public function markDelivered(): void
    {
        $this->ensureManage();

        abort_unless($this->stock->status === DealerStock::STATUS_SOLD, 422, 'Only a sold vehicle can be delivered.');

        $this->stock->update([
            'previous_location_type' => $this->stock->current_location_type,
            'current_location_type'  => DealerStock::LOCATION_DELIVERED,
            'delivered_at'           => now(),
        ]);

        session()->flash('success', "{$this->stock->vin} marked as delivered. The sale is now final.");
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

        return [
            'canManageStock' => $user->hasPermission('manage_dealer_stock'),
            'salesPeople'    => $salesPeople,
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
                Bucket: <strong>{{ str_replace('_', ' ', $stock->current_location_type) }}</strong>
                @if($stock->currentLocation)
                    · at {{ $stock->currentLocation->company_name }}{{ $stock->currentLocation->city ? ', ' . $stock->currentLocation->city : '' }}
                @endif
            </p>
            <p class="text-sm text-slate-500 mt-1">Status: {{ $stock->status }}</p>

            @if($stock->status === DealerStock::STATUS_SOLD)
                <hr class="my-4 border-slate-100">
                <h3 class="text-sm font-semibold text-slate-900 mb-2">Sale</h3>
                <dl class="grid grid-cols-2 gap-3 text-sm">
                    <div><dt class="text-xs text-slate-500">Customer</dt><dd class="text-slate-900">{{ $stock->sale_customer_name }}</dd></div>
                    <div><dt class="text-xs text-slate-500">Phone</dt><dd class="text-slate-900">{{ $stock->sale_customer_phone ?? '—' }}</dd></div>
                    <div><dt class="text-xs text-slate-500">Email</dt><dd class="text-slate-900">{{ $stock->sale_customer_email ?? '—' }}</dd></div>
                    <div><dt class="text-xs text-slate-500">Sold by</dt><dd class="text-slate-900">{{ $stock->salesperson?->name ?? '—' }}</dd></div>
                    <div><dt class="text-xs text-slate-500">Sold at</dt><dd class="text-slate-900">{{ optional($stock->sold_at)->format('d M Y H:i') ?? '—' }}</dd></div>
                    <div><dt class="text-xs text-slate-500">Delivered</dt><dd class="text-slate-900">{{ $stock->delivered_at ? $stock->delivered_at->format('d M Y H:i') : 'Not yet — sale still reversible' }}</dd></div>
                </dl>
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

            @if(!$canManageStock)
                <p class="text-sm text-slate-500">You don't have permission to mutate this vehicle.</p>
            @else
                <div class="flex flex-col gap-2">
                    @if($stock->status !== DealerStock::STATUS_SOLD && $stock->status !== DealerStock::STATUS_ARCHIVED)
                        <button wire:click="toggleSaleForm" class="rounded-lg bg-emerald-600 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-500">
                            {{ $showSaleForm ? 'Cancel sale' : 'Mark as sold' }}
                        </button>
                    @endif

                    @if($stock->status !== DealerStock::STATUS_DEMO && $stock->status !== DealerStock::STATUS_SOLD && $stock->status !== DealerStock::STATUS_ARCHIVED)
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

                    {{-- Sold-but-not-delivered: keep the sale easily
                         reversible (chassis swaps / spec changes happen
                         right up to delivery), and let the dealer lock it
                         in once the customer has taken the vehicle. --}}
                    @if($stock->status === DealerStock::STATUS_SOLD && $stock->delivered_at === null)
                        <button wire:click="markDelivered"
                                wire:confirm="Confirm {{ $stock->vin }} has been delivered? This finalises the sale and it can no longer be reversed here."
                                class="rounded-lg bg-blue-600 px-3 py-2 text-sm font-semibold text-white hover:bg-blue-500">
                            Mark as delivered
                        </button>
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

                @if($showSaleForm)
                    <form wire:submit="markSold" class="mt-5 space-y-3 border-t border-slate-100 pt-4">
                        <h3 class="text-sm font-semibold text-slate-900">Sale details</h3>

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
