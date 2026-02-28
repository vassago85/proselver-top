<?php
use App\Models\Brand;
use App\Models\VehicleModel;
use Livewire\Volt\Component;
use Livewire\Attributes\Layout;

new #[Layout('components.layouts.app')] class extends Component {
    public string $newBrandName = '';
    public string $newModelName = '';
    public ?int $addModelToBrandId = null;
    public ?int $editingBrandId = null;
    public string $editBrandName = '';

    public function addBrand(): void
    {
        $this->validate(['newBrandName' => 'required|string|max:255|unique:brands,name']);
        Brand::create(['name' => $this->newBrandName, 'is_active' => true]);
        $this->newBrandName = '';
    }

    public function startEditBrand(int $brandId): void
    {
        $brand = Brand::findOrFail($brandId);
        $this->editingBrandId = $brandId;
        $this->editBrandName = $brand->name;
    }

    public function updateBrand(): void
    {
        $this->validate(['editBrandName' => "required|string|max:255|unique:brands,name,{$this->editingBrandId}"]);
        Brand::findOrFail($this->editingBrandId)->update(['name' => $this->editBrandName]);
        $this->editingBrandId = null;
        $this->editBrandName = '';
    }

    public function toggleBrand(int $brandId): void
    {
        $brand = Brand::findOrFail($brandId);
        $brand->update(['is_active' => !$brand->is_active]);
    }

    public function addModel(): void
    {
        $this->validate([
            'addModelToBrandId' => 'required|exists:brands,id',
            'newModelName' => 'required|string|max:255',
        ]);

        VehicleModel::firstOrCreate(
            ['brand_id' => $this->addModelToBrandId, 'name' => $this->newModelName],
            ['is_active' => true]
        );

        $this->newModelName = '';
    }

    public function toggleModel(int $modelId): void
    {
        $model = VehicleModel::findOrFail($modelId);
        $model->update(['is_active' => !$model->is_active]);
    }

    public function deleteModel(int $modelId): void
    {
        VehicleModel::findOrFail($modelId)->delete();
    }

    public function with(): array
    {
        return [
            'brands' => Brand::withCount('vehicleModels')
                ->with(['vehicleModels' => fn($q) => $q->orderBy('name')])
                ->orderBy('name')
                ->get(),
        ];
    }
};
?>
<div>
    <x-slot:header>Brands & Models</x-slot:header>

    <div class="max-w-3xl">
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">Add Brand</h3>
            <form wire:submit="addBrand" class="flex gap-3">
                <input wire:model="newBrandName" type="text" placeholder="Brand name (e.g. MAN)" class="flex-1 rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500">
                <button type="submit" class="rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-500">Add</button>
            </form>
            @error('newBrandName')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        </div>

        <div class="space-y-4">
            @foreach($brands as $brand)
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                <div class="flex items-center justify-between px-6 py-4 bg-gray-50 border-b border-gray-200">
                    @if($editingBrandId === $brand->id)
                        <form wire:submit="updateBrand" class="flex items-center gap-2 flex-1">
                            <input wire:model="editBrandName" type="text" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm flex-1">
                            <button type="submit" class="text-sm text-blue-600 font-medium">Save</button>
                            <button type="button" wire:click="$set('editingBrandId', null)" class="text-sm text-gray-500">Cancel</button>
                        </form>
                    @else
                        <div class="flex items-center gap-3">
                            <h4 class="text-sm font-semibold text-gray-900">{{ $brand->name }}</h4>
                            <span class="text-xs text-gray-400">{{ $brand->vehicle_models_count }} models</span>
                            @unless($brand->is_active)
                                <span class="inline-flex items-center rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-700">Inactive</span>
                            @endunless
                        </div>
                        <div class="flex items-center gap-2">
                            <button wire:click="startEditBrand({{ $brand->id }})" class="text-sm text-blue-600 hover:text-blue-800 font-medium">Rename</button>
                            <button wire:click="toggleBrand({{ $brand->id }})" class="text-sm {{ $brand->is_active ? 'text-red-600' : 'text-green-600' }} font-medium">
                                {{ $brand->is_active ? 'Disable' : 'Enable' }}
                            </button>
                        </div>
                    @endif
                </div>

                <div class="px-6 py-3">
                    @if($brand->vehicleModels->isNotEmpty())
                    <div class="flex flex-wrap gap-2 mb-3">
                        @foreach($brand->vehicleModels as $model)
                            <span class="inline-flex items-center gap-1 rounded-full border px-3 py-1 text-xs {{ $model->is_active ? 'border-gray-200 text-gray-700' : 'border-red-200 text-red-500 line-through' }}">
                                {{ $model->name }}
                                <button wire:click="toggleModel({{ $model->id }})" class="ml-1 {{ $model->is_active ? 'text-gray-400 hover:text-red-500' : 'text-green-500' }}" title="{{ $model->is_active ? 'Disable' : 'Enable' }}">
                                    @if($model->is_active)
                                        <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                                    @else
                                        <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                                    @endif
                                </button>
                            </span>
                        @endforeach
                    </div>
                    @endif

                    <form wire:submit="addModel" class="flex gap-2">
                        <input type="hidden" wire:model="addModelToBrandId">
                        <input wire:model="newModelName" wire:focus="$set('addModelToBrandId', {{ $brand->id }})" type="text" placeholder="Add model..." class="flex-1 rounded-lg border border-gray-200 px-3 py-1.5 text-xs focus:border-blue-500 focus:ring-blue-500">
                        <button type="submit" wire:click="$set('addModelToBrandId', {{ $brand->id }})" class="rounded-lg bg-gray-100 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-200">Add</button>
                    </form>
                </div>
            </div>
            @endforeach
        </div>
    </div>
</div>
