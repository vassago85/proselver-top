<?php

use App\Models\BodyBuilderDealerLink;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    public function with(): array
    {
        $company = auth()->user()?->company();
        if (! $company) {
            return ['links' => collect(), 'company' => null];
        }

        $links = BodyBuilderDealerLink::query()
            ->where('body_builder_company_id', $company->id)
            ->with(['dealer:id,name,type', 'linkedBy:id,name'])
            ->orderByDesc('is_active')
            ->orderByDesc('updated_at')
            ->get();

        return compact('links', 'company');
    }
};
?>

<div>
    <x-slot:header>Linked Dealers</x-slot:header>

    <div class="mb-4 rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-900">
        These dealers have authorised <strong>{{ $company?->name }}</strong> to receive their vehicles and raise next-fitment / collection requests on their behalf. Only a dealer can pause or remove the link from their side.
    </div>

    <div class="overflow-hidden rounded-xl border border-slate-200 bg-white">
        @if($links->isEmpty())
            <div class="px-6 py-10 text-center text-sm text-slate-500">
                No dealers have linked you yet. Ask a dealer to add <strong>{{ $company?->name }}</strong> from their "Linked Body Builders" page.
            </div>
        @else
            <table class="min-w-full divide-y divide-slate-200">
                <thead class="bg-slate-50">
                    <tr>
                        <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-slate-500">Dealer</th>
                        <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-slate-500">Linked by</th>
                        <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-slate-500">Since</th>
                        <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-slate-500">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach($links as $link)
                        <tr class="{{ $link->is_active ? '' : 'opacity-60' }}">
                            <td class="whitespace-nowrap px-4 py-2 text-sm font-medium text-slate-900">{{ $link->dealer?->name }}</td>
                            <td class="whitespace-nowrap px-4 py-2 text-sm text-slate-700">{{ $link->linkedBy?->name ?: '—' }}</td>
                            <td class="whitespace-nowrap px-4 py-2 text-sm text-slate-700">{{ optional($link->created_at)->format('j M Y') }}</td>
                            <td class="whitespace-nowrap px-4 py-2 text-sm">
                                @if($link->is_active)
                                    <span class="inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700">Active</span>
                                @else
                                    <span class="inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600">Paused</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</div>
