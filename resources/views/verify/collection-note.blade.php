<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Collection Note Verified - ProSelverTech</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 min-h-screen flex flex-col">
    {{-- Header --}}
    <header class="bg-white shadow-sm">
        <div class="max-w-2xl mx-auto px-4 py-4">
            <h1 class="text-2xl font-bold text-blue-800">ProSelverTech</h1>
            <p class="text-sm text-gray-500">Control • Dispatch • Deliver</p>
        </div>
    </header>

    {{-- Content --}}
    <main class="flex-1 max-w-2xl mx-auto w-full px-4 py-8">
        {{-- Verified Badge --}}
        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-green-100 mb-4">
                <svg class="w-10 h-10 text-green-600" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" fill="none">
                    <path d="M20 6 9 17l-5-5"/>
                </svg>
            </div>
            <h2 class="text-2xl font-bold text-gray-900">Collection Note Verified</h2>
            <p class="text-gray-500 mt-1">This is a valid collection note issued by ProSelverTech</p>
        </div>

        {{-- Details Card --}}
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
            <div class="divide-y divide-gray-100">
                <div class="px-5 py-4 flex justify-between">
                    <span class="text-sm font-medium text-gray-500">Job Number</span>
                    <span class="text-sm font-semibold text-gray-900">{{ $job->job_number }}</span>
                </div>

                <div class="px-5 py-4 flex justify-between">
                    <span class="text-sm font-medium text-gray-500">Company</span>
                    <span class="text-sm text-gray-900">{{ $job->company?->name ?? '-' }}</span>
                </div>

                <div class="px-5 py-4 flex justify-between">
                    <span class="text-sm font-medium text-gray-500">Vehicle</span>
                    <span class="text-sm text-gray-900">
                        {{ $job->brand?->name ?? '' }} {{ $job->model_name ?? '' }}
                        @if($job->vin)
                            <br><span class="text-xs text-gray-400">VIN: {{ $job->vin }}</span>
                        @endif
                    </span>
                </div>

                <div class="px-5 py-4 flex justify-between">
                    <span class="text-sm font-medium text-gray-500">Driver</span>
                    <span class="text-sm text-gray-900">{{ $job->driver?->name ?? '-' }}</span>
                </div>

                <div class="px-5 py-4 flex justify-between">
                    <span class="text-sm font-medium text-gray-500">Collection</span>
                    <span class="text-sm text-gray-900 text-right">{{ $job->pickupLocation?->full_address ?? $job->pickupLocation?->company_name ?? '-' }}</span>
                </div>

                <div class="px-5 py-4 flex justify-between">
                    <span class="text-sm font-medium text-gray-500">Delivery</span>
                    <span class="text-sm text-gray-900 text-right">{{ $job->deliveryLocation?->full_address ?? $job->deliveryLocation?->company_name ?? '-' }}</span>
                </div>

                <div class="px-5 py-4 flex justify-between items-center">
                    <span class="text-sm font-medium text-gray-500">Status</span>
                    @php
                        $statusColors = [
                            'received' => 'bg-gray-100 text-gray-700',
                            'awaiting_customer_confirmation' => 'bg-yellow-100 text-yellow-700',
                            'confirmed' => 'bg-blue-100 text-blue-700',
                            'planned' => 'bg-indigo-100 text-indigo-700',
                            'driver_assigned' => 'bg-purple-100 text-purple-700',
                            'ready_for_collection' => 'bg-orange-100 text-orange-700',
                            'collected' => 'bg-cyan-100 text-cyan-700',
                            'in_transit' => 'bg-sky-100 text-sky-700',
                            'delivered' => 'bg-green-100 text-green-700',
                            'completed' => 'bg-emerald-100 text-emerald-700',
                            'cancelled' => 'bg-red-100 text-red-700',
                        ];
                        $colorClass = $statusColors[$job->status] ?? 'bg-gray-100 text-gray-700';
                    @endphp
                    <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium {{ $colorClass }}">
                        {{ $job->phase1StatusLabel() }}
                    </span>
                </div>
            </div>
        </div>
    </main>

    {{-- Footer --}}
    <footer class="bg-white border-t border-gray-200 mt-8">
        <div class="max-w-2xl mx-auto px-4 py-4 text-center">
            <p class="text-xs text-gray-400">This collection note was issued by ProSelverTech</p>
        </div>
    </footer>
</body>
</html>
