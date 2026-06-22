<?php

use App\Models\BodyBuilderDealerLink;
use App\Models\BodyBuilderRequest;
use App\Models\Company;
use App\Services\BodyBuilderRequestService;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/*
 * Dealer-facing "request a new body builder" form.
 *
 * As the dealer types the proposed name we run a fuzzy match against
 * existing body_builder Companies and surface up to 5 likely duplicates
 * inline -- one click on a candidate links the dealer to that BB and
 * closes the form, saving an ops round-trip when the dealer just
 * couldn't find the existing entry.  Submit only creates a request row
 * (ops still has to approve before the BB lands in the directory).
 */
new #[Layout('components.layouts.app')] class extends Component
{
    public ?Company $company = null;

    public string $proposed_name = '';
    public string $proposed_address = '';
    public string $proposed_city = '';
    public string $proposed_province = '';
    public string $proposed_contact_name = '';
    public string $proposed_contact_phone = '';
    public string $proposed_contact_email = '';
    public string $dealer_notes = '';

    public function mount(): void
    {
        $this->company = auth()->user()?->company();
        abort_unless($this->company, 403);
        abort_unless(auth()->user()->canManageBbLinks(), 403, 'You do not have permission to manage body-builder links.');
        abort_if($this->company->isOem(), 403, 'OEM accounts do not manage body-builder links.');
    }

    /**
     * Live dedupe hints -- recomputed on every keystroke after the
     * dealer reaches 3 characters in the name.  Kept on the component
     * (not in the service) so the Volt re-render picks it up via
     * `with()` without an extra wire request.
     */
    public function getCandidatesProperty(): array
    {
        if (mb_strlen(trim($this->proposed_name)) < 3) {
            return [];
        }
        return app(BodyBuilderRequestService::class)
            ->findDedupeCandidates($this->proposed_name, $this->proposed_address ?: null);
    }

    /**
     * The dealer clicked one of the dedupe hint suggestions -- the BB
     * already exists, just link to it directly without going through
     * the ops queue.  No new BodyBuilderRequest row is created because
     * the request never needed to exist.
     */
    public function linkExisting(int $bbCompanyId): void
    {
        $bb = Company::find($bbCompanyId);
        if (!$bb || $bb->type !== Company::TYPE_BODY_BUILDER) {
            session()->flash('error', 'That company is no longer available.');
            return;
        }

        BodyBuilderDealerLink::updateOrCreate(
            [
                'dealer_company_id'       => $this->company->id,
                'body_builder_company_id' => $bb->id,
            ],
            [
                'linked_by_user_id' => auth()->id(),
                'is_active'         => true,
                'notes'             => $this->dealer_notes ?: 'Linked from "request a new body builder" duplicate-match.',
            ],
        );

        session()->flash('success', "Linked {$bb->name}. They can now confirm vehicles delivered to their workshops.");
        $this->redirect(route('customer.body-builders.index'), navigate: true);
    }

    public function submit(): void
    {
        $this->validate([
            'proposed_name'         => 'required|string|min:2|max:200',
            'proposed_address'      => 'required|string|min:3|max:500',
            'proposed_city'         => 'nullable|string|max:120',
            'proposed_province'     => 'nullable|string|max:120',
            'proposed_contact_name' => 'nullable|string|max:120',
            'proposed_contact_phone'=> 'nullable|string|max:60',
            'proposed_contact_email'=> 'nullable|email|max:160',
            'dealer_notes'          => 'nullable|string|max:1000',
        ]);

        BodyBuilderRequest::create([
            'dealer_company_id'      => $this->company->id,
            'requested_by_user_id'   => auth()->id(),
            'proposed_name'          => trim($this->proposed_name),
            'proposed_address'       => trim($this->proposed_address),
            'proposed_city'          => $this->proposed_city ?: null,
            'proposed_province'      => $this->proposed_province ?: null,
            'proposed_contact_name'  => $this->proposed_contact_name ?: null,
            'proposed_contact_phone' => $this->proposed_contact_phone ?: null,
            'proposed_contact_email' => $this->proposed_contact_email ?: null,
            'dealer_notes'           => $this->dealer_notes ?: null,
            'status'                 => BodyBuilderRequest::STATUS_PENDING,
        ]);

        session()->flash('success', 'Request submitted. ProSelver ops will review and link the body builder to your account once approved.');
        $this->redirect(route('customer.body-builders.requests.index'), navigate: true);
    }

    public function with(): array
    {
        return [
            'candidates' => $this->getCandidatesProperty(),
        ];
    }
}; ?>

<div class="space-y-4">
    <x-slot:header>Request a body builder</x-slot:header>

    @if(session('error'))
        <div class="rounded-md bg-rose-50 border border-rose-200 px-3 py-2 text-sm text-rose-800">{{ session('error') }}</div>
    @endif

    <div class="rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-900">
        Can't find the fitment shop you need to send vehicles to? Submit it here and ProSelver ops will add it (or point you at the
        existing entry if it's already in the directory). Once approved, the body builder is automatically linked to your account.
    </div>

    <form wire:submit.prevent="submit" class="grid gap-4 lg:grid-cols-3">
        <div class="lg:col-span-2 rounded-xl border border-slate-200 bg-white p-5 space-y-3">
            <div>
                <label class="block text-xs font-medium text-slate-700">Body builder name <span class="text-rose-500">*</span></label>
                <input type="text" wire:model.live.debounce.300ms="proposed_name"
                    class="mt-1 block w-full rounded-md border-slate-300 text-sm"
                    placeholder="e.g. Anchor Auto Body Builders" required>
                @error('proposed_name')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
            </div>

            {{-- Dedupe hints surface as soon as the name has 3+ chars.
                 Clicking a candidate uses the existing BB instead of
                 submitting a request, saving everyone time. --}}
            @if(!empty($candidates))
                <div class="rounded-lg border border-amber-200 bg-amber-50 p-3 space-y-2">
                    <p class="text-xs font-semibold text-amber-900">
                        ⚠ Possible existing body builders.  Click to link instead of submitting a new request.
                    </p>
                    <ul class="space-y-1">
                        @foreach($candidates as $c)
                            <li>
                                <button type="button" wire:click="linkExisting({{ $c['id'] }})"
                                    class="w-full text-left rounded-md border border-amber-300 bg-white px-3 py-2 hover:bg-amber-100">
                                    <div class="text-sm font-medium text-slate-900">{{ $c['name'] }}</div>
                                    <div class="text-xs text-slate-500">{{ $c['address'] ?: '— no address on file' }}</div>
                                </button>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div>
                <label class="block text-xs font-medium text-slate-700">Workshop address <span class="text-rose-500">*</span></label>
                <textarea wire:model.live.debounce.500ms="proposed_address" rows="2"
                    class="mt-1 block w-full rounded-md border-slate-300 text-sm"
                    placeholder="Street, suburb, city" required></textarea>
                @error('proposed_address')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
            </div>

            <div class="grid gap-3 sm:grid-cols-2">
                <div>
                    <label class="block text-xs font-medium text-slate-700">City</label>
                    <input type="text" wire:model="proposed_city" class="mt-1 block w-full rounded-md border-slate-300 text-sm" placeholder="Pretoria">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-700">Province</label>
                    <input type="text" wire:model="proposed_province" class="mt-1 block w-full rounded-md border-slate-300 text-sm" placeholder="Gauteng">
                </div>
            </div>

            <div class="grid gap-3 sm:grid-cols-3">
                <div>
                    <label class="block text-xs font-medium text-slate-700">Contact name</label>
                    <input type="text" wire:model="proposed_contact_name" class="mt-1 block w-full rounded-md border-slate-300 text-sm" placeholder="Workshop manager">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-700">Contact phone</label>
                    <input type="tel" wire:model="proposed_contact_phone" class="mt-1 block w-full rounded-md border-slate-300 text-sm" placeholder="011 123 4567">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-700">Contact email</label>
                    <input type="email" wire:model="proposed_contact_email" class="mt-1 block w-full rounded-md border-slate-300 text-sm" placeholder="workshop@example.co.za">
                </div>
            </div>

            <div>
                <label class="block text-xs font-medium text-slate-700">Notes for ops</label>
                <textarea wire:model="dealer_notes" rows="3"
                    class="mt-1 block w-full rounded-md border-slate-300 text-sm"
                    placeholder="Anything ProSelver should know — preferred contact, fitment types, scheduling quirks…"></textarea>
            </div>

            <div class="flex items-center justify-between pt-2">
                <a href="{{ route('customer.body-builders.index') }}" class="text-sm text-slate-600 hover:text-slate-900">← Back</a>
                <button type="submit" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700">
                    Submit request
                </button>
            </div>
        </div>

        <aside class="rounded-xl border border-slate-200 bg-slate-50 p-5 text-xs text-slate-600 space-y-2 self-start">
            <h3 class="text-sm font-semibold text-slate-900">What happens next?</h3>
            <ol class="list-decimal list-inside space-y-1.5">
                <li>Your request lands in the ProSelver ops queue.</li>
                <li>Ops checks the directory for an existing entry. If they find one, they'll merge your request into it.</li>
                <li>If it's new, they'll add the body builder and seed the workshop address.</li>
                <li>Either way, the body builder is auto-linked to your dealership — you'll see them in <strong>Linked Body Builders</strong> straight away.</li>
            </ol>
        </aside>
    </form>
</div>
