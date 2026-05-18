<?php

use App\Models\MovementRequest;
use App\Services\MovementRequestService;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Illuminate\Support\Facades\Gate;

new #[Layout('components.layouts.app')] class extends Component
{
    public MovementRequest $req;
    public string $cancelNotes = '';

    public function mount(string $request): void
    {
        $this->req = MovementRequest::where('uuid', $request)
            ->with([
                'targetCompany:id,name', 'requestingCompany:id,name',
                'sourceJob', 'createdJob:id,job_number,uuid',
                'pickupLocation:id,company_name,city',
                'deliveryLocation:id,company_name,city',
                'brand:id,name', 'vehicleClass:id,name',
                'requestingUser:id,name', 'decidedBy:id,name',
            ])
            ->firstOrFail();

        abort_unless(Gate::allows('view', $this->req), 403);
    }

    public function cancel(MovementRequestService $service): void
    {
        $user = auth()->user();
        if (! Gate::forUser($user)->allows('cancel', $this->req)) {
            session()->flash('error', 'You cannot cancel this request.');
            return;
        }

        try {
            $service->cancel($this->req, $user, $this->cancelNotes ?: null);
            session()->flash('success', 'Request cancelled.');
            $this->req->refresh();
        } catch (\Throwable $e) {
            session()->flash('error', $e->getMessage());
        }
    }
};
?>

<div>
    <x-slot:header>Request · {{ $req->typeLabel() }}</x-slot:header>

    @if(session('success'))
        <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">{{ session('error') }}</div>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2 space-y-4">
            <div class="rounded-xl border border-slate-200 bg-white p-5">
                <div class="flex items-start justify-between">
                    <div>
                        <h2 class="text-base font-semibold text-slate-900">{{ $req->typeLabel() }}</h2>
                        <p class="text-xs text-slate-500">To {{ $req->targetCompany?->name }} · {{ optional($req->created_at)->format('D, j M Y \a\t H:i') }}</p>
                    </div>
                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium
                        {{ $req->status === 'approved' ? 'bg-emerald-50 text-emerald-700'
                          : ($req->status === 'rejected' ? 'bg-rose-50 text-rose-700'
                          : ($req->status === 'cancelled' ? 'bg-slate-100 text-slate-600' : 'bg-amber-50 text-amber-700')) }}">
                        {{ $req->statusLabel() }}
                    </span>
                </div>

                <dl class="mt-4 grid grid-cols-2 gap-3 text-sm">
                    <div><dt class="text-slate-500">Vehicle</dt><dd class="text-slate-900">{{ $req->brand?->name }} {{ $req->model_name }}</dd></div>
                    <div><dt class="text-slate-500">VIN / Reg</dt><dd class="text-slate-900">{{ $req->vin ?: '—' }} · {{ $req->registration ?: '—' }}</dd></div>
                    <div><dt class="text-slate-500">Class</dt><dd class="text-slate-900">{{ $req->vehicleClass?->name ?: '—' }}</dd></div>
                    <div><dt class="text-slate-500">Requested date</dt><dd class="text-slate-900">{{ optional($req->requested_date)->format('D, j M Y') ?: '—' }}</dd></div>
                    <div class="col-span-2"><dt class="text-slate-500">Pickup</dt><dd class="text-slate-900">{{ $req->pickupLocation?->company_name }}<span class="text-slate-500"> · {{ $req->pickupLocation?->city }}</span></dd></div>
                    <div class="col-span-2"><dt class="text-slate-500">Delivery</dt><dd class="text-slate-900">{{ $req->deliveryLocation?->company_name }}<span class="text-slate-500"> · {{ $req->deliveryLocation?->city }}</span></dd></div>
                    <div class="col-span-2"><dt class="text-slate-500">Notes to dealer</dt><dd class="text-slate-900 whitespace-pre-wrap">{{ $req->notes ?: '—' }}</dd></div>
                </dl>
            </div>

            @if($req->status !== 'pending')
                <div class="rounded-xl border border-slate-200 bg-white p-5">
                    <h2 class="text-base font-semibold text-slate-900">Decision</h2>
                    <dl class="mt-3 grid grid-cols-2 gap-3 text-sm">
                        <div><dt class="text-slate-500">Decided by</dt><dd class="text-slate-900">{{ $req->decidedBy?->name ?: '—' }}</dd></div>
                        <div><dt class="text-slate-500">When</dt><dd class="text-slate-900">{{ optional($req->decided_at)->format('D, j M Y \a\t H:i') ?: '—' }}</dd></div>
                        <div class="col-span-2"><dt class="text-slate-500">Notes</dt><dd class="text-slate-900 whitespace-pre-wrap">{{ $req->decision_notes ?: '—' }}</dd></div>
                        @if($req->createdJob)
                            <div class="col-span-2">
                                <dt class="text-slate-500">Became job</dt>
                                <dd class="text-blue-600 hover:underline"><a href="{{ route('body-builder.jobs.show', $req->created_job_id) }}">{{ $req->createdJob->job_number }}</a></dd>
                            </div>
                        @endif
                    </dl>
                </div>
            @endif
        </div>

        <div class="space-y-4">
            @if($req->isPending())
                <form wire:submit.prevent="cancel" class="rounded-xl border border-slate-200 bg-white p-5">
                    <h3 class="text-sm font-semibold text-slate-900">Cancel request</h3>
                    <p class="mt-1 text-xs text-slate-500">If the vehicle isn't actually ready yet, you can withdraw the request before the dealer responds.</p>
                    <textarea wire:model="cancelNotes" rows="2" class="mt-2 block w-full rounded-md border-slate-300 text-sm" placeholder="Reason (optional)"></textarea>
                    <button type="submit" class="mt-2 w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-rose-700 hover:bg-rose-50">Cancel request</button>
                </form>
            @endif

            @if($req->sourceJob)
                <div class="rounded-xl border border-slate-200 bg-white p-5">
                    <h3 class="text-sm font-semibold text-slate-900">Source vehicle</h3>
                    <p class="mt-1 text-sm text-slate-700">{{ $req->sourceJob->job_number ?: '—' }}</p>
                    <a href="{{ route('body-builder.jobs.show', $req->source_job_id) }}" class="mt-2 inline-flex text-sm font-medium text-blue-600 hover:text-blue-800">View vehicle →</a>
                </div>
            @endif
        </div>
    </div>
</div>
