<?php
use App\Models\Job;
use App\Models\JobDocument;
use App\Models\JobEvent;
use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\WithFileUploads;

new #[Layout('components.layouts.app')] class extends Component {
    use WithFileUploads;

    public Job $job;
    public $photoUpload;

    public function mount(Job $job): void
    {
        if ($job->driver_user_id !== auth()->id()) {
            abort(403);
        }
        $this->job = $job->load(['company:id,name', 'pickupLocation:id,company_name,address', 'deliveryLocation:id,company_name,address', 'events']);
    }

    public function logEvent(string $eventType): void
    {
        if (!in_array($eventType, JobEvent::TYPES)) return;

        JobEvent::create([
            'job_id' => $this->job->id,
            'user_id' => auth()->id(),
            'event_type' => $eventType,
            'event_at' => now(),
            'synced_at' => now(),
            'client_uuid' => \Illuminate\Support\Str::uuid(),
        ]);

        // Legacy transitions
        if ($eventType === JobEvent::TYPE_ARRIVED_PICKUP && $this->job->status === Job::STATUS_ASSIGNED) {
            $this->job->transitionTo(Job::STATUS_IN_PROGRESS);
        }
        if ($eventType === JobEvent::TYPE_VEHICLE_READY) {
            $this->job->actual_ready_time = now();
            $this->job->save();
        }
        if ($eventType === JobEvent::TYPE_JOB_COMPLETED && $this->job->status === Job::STATUS_IN_PROGRESS) {
            $this->job->transitionTo(Job::STATUS_COMPLETED);
        }

        // Phase 1 transitions
        if ($eventType === JobEvent::TYPE_ARRIVED_PICKUP && $this->job->status === Job::STATUS_READY_FOR_COLLECTION) {
            $this->job->transitionTo(Job::STATUS_COLLECTED);
        }
        if ($eventType === JobEvent::TYPE_DEPARTED_PICKUP && $this->job->status === Job::STATUS_COLLECTED) {
            $this->job->transitionTo(Job::STATUS_IN_TRANSIT);
        }
        if ($eventType === JobEvent::TYPE_ARRIVED_DELIVERY && $this->job->status === Job::STATUS_IN_TRANSIT) {
            $this->job->transitionTo(Job::STATUS_DELIVERED);
        }
        if ($eventType === JobEvent::TYPE_JOB_COMPLETED && $this->job->status === Job::STATUS_DELIVERED) {
            $this->job->transitionTo(Job::STATUS_COMPLETED);
        }

        $this->job->refresh()->load('events');
        session()->flash('success', ucfirst(str_replace('_', ' ', $eventType)) . ' logged.');
    }

    public function uploadPhoto(): void
    {
        $this->validate(['photoUpload' => 'required|image|max:5120']);

        $disk = config('filesystems.default') === 'local' ? 'local' : 'r2';
        $path = $this->photoUpload->store('jobs/' . $this->job->uuid . '/documents', $disk);

        JobDocument::create([
            'job_id' => $this->job->id,
            'uploaded_by_user_id' => auth()->id(),
            'category' => JobDocument::CATEGORY_PHOTO,
            'disk' => $disk,
            'path' => $path,
            'original_filename' => $this->photoUpload->getClientOriginalName(),
            'mime_type' => $this->photoUpload->getMimeType(),
            'size_bytes' => $this->photoUpload->getSize(),
            'file_hash' => hash_file('sha256', $this->photoUpload->getRealPath()),
        ]);

        $this->reset('photoUpload');
        session()->flash('success', 'Photo uploaded.');
    }

    public function uploadPod(): void
    {
        $this->validate(['photoUpload' => 'required|file|max:10240']);

        $disk = config('filesystems.default') === 'local' ? 'local' : 'r2';
        $path = $this->photoUpload->store('jobs/' . $this->job->uuid . '/documents', $disk);

        JobDocument::create([
            'job_id' => $this->job->id,
            'uploaded_by_user_id' => auth()->id(),
            'category' => JobDocument::CATEGORY_POD,
            'disk' => $disk,
            'path' => $path,
            'original_filename' => $this->photoUpload->getClientOriginalName(),
            'mime_type' => $this->photoUpload->getMimeType(),
            'size_bytes' => $this->photoUpload->getSize(),
            'file_hash' => hash_file('sha256', $this->photoUpload->getRealPath()),
        ]);

        $this->reset('photoUpload');
        session()->flash('success', 'POD uploaded.');
    }

    public function with(): array
    {
        $loggedTypes = $this->job->events->pluck('event_type')->toArray();
        return ['loggedTypes' => $loggedTypes];
    }
};
?>
<div>
    <x-slot:header>Job {{ $job->job_number }}</x-slot:header>

    <div class="max-w-2xl mx-auto space-y-6">
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-xl font-bold text-gray-900">{{ $job->company?->name }}</h3>
                <x-status-badge :status="$job->status" />
            </div>
            @if($job->isTransport())
            <div class="text-center py-4">
                <p class="text-lg font-semibold text-gray-900">{{ $job->pickupLocation?->company_name }}</p>
                <p class="text-sm text-gray-500">{{ $job->pickupLocation?->address }}</p>
                <p class="text-xs text-gray-500">Contact: {{ $job->pickup_contact_name ?? $job->pickupLocation?->customer_name ?? '—' }} {{ $job->pickup_contact_phone ?? $job->pickupLocation?->customer_phone ?? '' }}</p>
                <div class="my-3 text-2xl text-gray-400">↓</div>
                <p class="text-lg font-semibold text-gray-900">{{ $job->deliveryLocation?->company_name }}</p>
                <p class="text-sm text-gray-500">{{ $job->deliveryLocation?->address }}</p>
                <p class="text-xs text-gray-500">Contact: {{ $job->delivery_contact_name ?? $job->deliveryLocation?->customer_name ?? '—' }} {{ $job->delivery_contact_phone ?? $job->deliveryLocation?->customer_phone ?? '' }}</p>
            </div>
            @endif
            <p class="text-sm text-gray-500 text-center">{{ $job->scheduled_date?->format('D, d M Y') }}</p>
        </div>

        {{-- Action Buttons --}}
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 space-y-3">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">Actions</h3>

            @php $steps = [
                ['type' => 'arrived_pickup', 'label' => 'Arrived at Pickup', 'color' => 'blue'],
                ['type' => 'vehicle_ready_confirmed', 'label' => 'Vehicle Ready', 'color' => 'blue'],
                ['type' => 'departed_pickup', 'label' => 'Departed Pickup', 'color' => 'blue'],
                ['type' => 'arrived_delivery', 'label' => 'Arrived at Delivery', 'color' => 'blue'],
                ['type' => 'pod_scanned', 'label' => 'POD Scanned', 'color' => 'green'],
                ['type' => 'job_completed', 'label' => 'Complete Job', 'color' => 'green'],
            ]; @endphp

            @foreach($steps as $step)
                @if(in_array($step['type'], $loggedTypes))
                    <button disabled class="w-full rounded-lg bg-gray-100 px-4 py-4 text-sm font-semibold text-gray-400 flex items-center justify-between">
                        {{ $step['label'] }}
                        <svg class="h-5 w-5 text-green-500" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" fill="none"><path d="M20 6 9 17l-5-5"/></svg>
                    </button>
                @else
                    <button wire:click="logEvent('{{ $step['type'] }}')" wire:confirm="Log '{{ $step['label'] }}'?"
                        class="w-full rounded-lg bg-{{ $step['color'] }}-600 px-4 py-4 text-sm font-semibold text-white hover:bg-{{ $step['color'] }}-500 transition-colors text-left">
                        {{ $step['label'] }}
                    </button>
                @endif
            @endforeach
        </div>

        {{-- Photo / POD Upload --}}
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6" x-data="imageCompressor()">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">Upload Photo / POD</h3>
            <input type="file" accept="image/*,application/pdf" @change="compressAndAttach($event)" class="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100">
            @error('photoUpload') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
            <div class="mt-3 flex gap-2">
                <button wire:click="uploadPhoto" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-500" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="uploadPhoto">Upload Photo</span>
                    <span wire:loading wire:target="uploadPhoto">Uploading...</span>
                </button>
                <button wire:click="uploadPod" class="rounded-lg bg-green-600 px-4 py-2 text-sm font-semibold text-white hover:bg-green-500" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="uploadPod">Upload POD</span>
                    <span wire:loading wire:target="uploadPod">Uploading...</span>
                </button>
            </div>
        </div>

        {{-- Event Timeline --}}
        @if($job->events->isNotEmpty())
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">Timeline</h3>
            <ol class="space-y-3">
                @foreach($job->events->sortByDesc('event_at') as $event)
                <li class="flex justify-between items-center py-2 border-b border-gray-100 last:border-0">
                    <span class="text-sm font-medium text-gray-900">{{ ucfirst(str_replace('_', ' ', $event->event_type)) }}</span>
                    <time class="text-xs text-gray-500">{{ $event->event_at->format('H:i') }}</time>
                </li>
                @endforeach
            </ol>
        </div>
        @endif
    </div>

    <script>
    function imageCompressor() {
        return {
            compressAndAttach(event) {
                const file = event.target.files[0];
                if (!file || !file.type.startsWith('image/')) {
                    @this.upload('photoUpload', file);
                    return;
                }
                const maxWidth = 1200;
                const quality = 0.7;
                const reader = new FileReader();
                reader.onload = (e) => {
                    const img = new Image();
                    img.onload = () => {
                        const canvas = document.createElement('canvas');
                        let w = img.width, h = img.height;
                        if (w > maxWidth) { h = h * maxWidth / w; w = maxWidth; }
                        canvas.width = w; canvas.height = h;
                        canvas.getContext('2d').drawImage(img, 0, 0, w, h);
                        canvas.toBlob((blob) => {
                            const compressed = new File([blob], file.name, { type: 'image/jpeg' });
                            @this.upload('photoUpload', compressed);
                        }, 'image/jpeg', quality);
                    };
                    img.src = e.target.result;
                };
                reader.readAsDataURL(file);
            }
        };
    }
    </script>
</div>
