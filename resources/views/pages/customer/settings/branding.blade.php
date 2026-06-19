<?php

use App\Models\Company;
use App\Support\Documents\DocumentImage;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

/*
 * Delivery-note branding (Phase 1B).
 *
 * One form that fills in the letterhead the PDF pipeline reads via
 * IssuerProfile::forCompany() — logo, address, VAT, company
 * registration, phone, billing email and an optional footer line.
 * Owner / admin only (canManageCompanyData()); UI gating is backed
 * up by hard guards on every mutating action because Livewire wire
 * payloads are tamperable.
 *
 * The logo is resized server-side to a max 600px width (GD) and
 * stored on the configured default disk under company-logos/, the
 * same disk job_documents use, so prod (R2/S3) and dev (local) both
 * work without extra config.
 */
new #[Layout('components.layouts.app')] class extends Component {
    use WithFileUploads;

    public ?Company $company = null;

    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null */
    public $logo = null;

    public string $address = '';
    public string $vatNumber = '';
    public string $registrationNumber = '';
    public string $phone = '';
    public string $billingEmail = '';
    public string $brandingFooter = '';

    public function mount(): void
    {
        abort_unless($this->canManage(), 403);

        $this->company = auth()->user()->company();
        abort_unless($this->company, 403, 'No company associated with your account.');

        $this->address            = (string) ($this->company->address ?? '');
        $this->vatNumber          = (string) ($this->company->vat_number ?? '');
        $this->registrationNumber = (string) ($this->company->registration_number ?? '');
        $this->phone              = (string) ($this->company->phone ?? '');
        $this->billingEmail       = (string) ($this->company->billing_email ?? '');
        $this->brandingFooter     = (string) ($this->company->branding_footer ?? '');
    }

    protected function canManage(): bool
    {
        return (bool) auth()->user()?->canManageCompanyData();
    }

    public function save(): void
    {
        abort_unless($this->canManage(), 403);

        $validated = $this->validate([
            'logo'               => 'nullable|image|mimes:png,jpg,jpeg|max:2048',
            'address'            => 'nullable|string|max:500',
            'vatNumber'          => 'nullable|string|max:30',
            'registrationNumber' => 'nullable|string|max:30',
            'phone'              => 'nullable|string|max:40',
            'billingEmail'       => 'nullable|email|max:120',
            'brandingFooter'     => 'nullable|string|max:500',
        ]);

        if ($this->logo) {
            $path = $this->storeLogo();
            if ($path) {
                $this->deleteStoredLogo($this->company->logo_path);
                $this->company->logo_path = $path;
            }
        }

        $this->company->address              = $validated['address'] ?: null;
        $this->company->vat_number           = $validated['vatNumber'] ?: null;
        $this->company->registration_number  = $validated['registrationNumber'] ?: null;
        $this->company->phone                = $validated['phone'] ?: null;
        $this->company->billing_email        = $validated['billingEmail'] ?: null;
        $this->company->branding_footer      = $validated['brandingFooter'] ?: null;
        $this->company->save();

        $this->logo = null;
        $this->company->refresh();

        session()->flash('success', 'Delivery-note branding saved.');
    }

    public function removeLogo(): void
    {
        abort_unless($this->canManage(), 403);

        $this->deleteStoredLogo($this->company->logo_path);
        $this->company->logo_path = null;
        $this->company->save();
        $this->company->refresh();

        session()->flash('success', 'Logo removed.');
    }

    /**
     * Resize the uploaded logo to a max 600px width and store it as
     * PNG on the default disk.  Falls back to storing the original
     * bytes if GD is unavailable or the re-encode fails, so a logo
     * always lands rather than silently dropping.
     */
    protected function storeLogo(): ?string
    {
        $disk = (string) config('filesystems.default');
        $filename = 'company-logos/' . $this->company->id . '-' . uniqid() . '.png';

        $sourcePath = $this->logo->getRealPath();
        $resized = $this->resizeToPng($sourcePath, 600);

        if ($resized !== null) {
            Storage::disk($disk)->put($filename, $resized);
            return $filename;
        }

        // Fallback: keep the original upload untouched.
        $ext = $this->logo->getClientOriginalExtension() ?: 'png';
        $fallback = 'company-logos/' . $this->company->id . '-' . uniqid() . '.' . $ext;
        Storage::disk($disk)->put($fallback, (string) file_get_contents($sourcePath));

        return $fallback;
    }

    protected function resizeToPng(string $sourcePath, int $maxWidth): ?string
    {
        if (!function_exists('imagecreatefromstring')) {
            return null;
        }

        $data = @file_get_contents($sourcePath);
        if ($data === false) {
            return null;
        }

        $src = @imagecreatefromstring($data);
        if ($src === false) {
            return null;
        }

        $width = imagesx($src);
        $height = imagesy($src);

        if ($width <= $maxWidth) {
            // Already small enough — re-encode to PNG for a consistent
            // format but keep the original dimensions.
            $out = $src;
        } else {
            $ratio = $maxWidth / $width;
            $newHeight = max(1, (int) round($height * $ratio));
            $out = imagecreatetruecolor($maxWidth, $newHeight);
            imagealphablending($out, false);
            imagesavealpha($out, true);
            imagecopyresampled($out, $src, 0, 0, 0, 0, $maxWidth, $newHeight, $width, $height);
        }

        ob_start();
        $ok = imagepng($out);
        $png = ob_get_clean();

        if ($out !== $src) {
            imagedestroy($out);
        }
        imagedestroy($src);

        return $ok && $png !== false && $png !== '' ? $png : null;
    }

    protected function deleteStoredLogo(?string $path): void
    {
        if (!$path) {
            return;
        }
        try {
            Storage::disk((string) config('filesystems.default'))->delete($path);
        } catch (\Throwable) {
            // Best-effort cleanup; a stale file is harmless.
        }
    }

    public function with(): array
    {
        // Logo preview: the freshly-uploaded temp file if present,
        // otherwise the stored logo embedded as a data URI (works on
        // any disk, public or private).
        $logoPreview = null;
        if ($this->logo) {
            try {
                $logoPreview = $this->logo->temporaryUrl();
            } catch (\Throwable) {
                $logoPreview = null;
            }
        } elseif ($this->company?->logo_path) {
            $logoPreview = DocumentImage::fromDisk($this->company->logo_path);
        }

        return [
            'logoPreview' => $logoPreview,
        ];
    }
}; ?>

<div>
    <x-slot:header>Delivery-note branding</x-slot:header>

    @if(session('success'))
        <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('success') }}</div>
    @endif

    <p class="mb-6 max-w-2xl text-sm text-slate-500">
        These details print on the delivery notes you issue — both for movements your own drivers run and
        for vehicles sold off the floor. Fill them in once and every note carries your letterhead automatically.
    </p>

    <form wire:submit="save" class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        {{-- ===== Form ===== --}}
        <div class="lg:col-span-2 space-y-6">
            <div class="rounded-xl border border-slate-200 bg-white p-6">
                <h2 class="text-base font-semibold text-slate-900">Logo</h2>
                <p class="mt-1 text-sm text-slate-500">PNG or JPG, up to 2&nbsp;MB. We resize it to print crisply.</p>

                <div class="mt-4 flex items-center gap-4">
                    <div class="flex h-20 w-40 items-center justify-center rounded-lg border border-dashed border-slate-300 bg-slate-50 overflow-hidden">
                        @if($logoPreview)
                            <img src="{{ $logoPreview }}" alt="Logo preview" class="max-h-16 max-w-full object-contain">
                        @else
                            <span class="text-xs text-slate-400">No logo</span>
                        @endif
                    </div>
                    <div class="space-y-2">
                        <input type="file" wire:model="logo" accept=".png,.jpg,.jpeg"
                               class="block text-sm text-slate-700 file:mr-3 file:rounded-md file:border-0 file:bg-slate-100 file:px-3 file:py-2 file:text-sm file:font-semibold file:text-slate-700 hover:file:bg-slate-200">
                        @if($company?->logo_path)
                            <button type="button" wire:click="removeLogo"
                                    class="text-xs font-medium text-red-600 hover:text-red-500">Remove logo</button>
                        @endif
                    </div>
                </div>
                <div wire:loading wire:target="logo" class="mt-2 text-xs text-slate-400">Uploading…</div>
                @error('logo') <p class="mt-2 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div class="rounded-xl border border-slate-200 bg-white p-6 space-y-4">
                <h2 class="text-base font-semibold text-slate-900">Company details</h2>

                <div>
                    <label class="block text-xs font-medium text-slate-700">Address</label>
                    <textarea wire:model.live="address" rows="3"
                              class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                              placeholder="123 Main Road&#10;Sandton, 2196"></textarea>
                    @error('address') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-medium text-slate-700">VAT number</label>
                        <input type="text" wire:model.live="vatNumber"
                               class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                               placeholder="4xxxxxxxxx">
                        @error('vatNumber') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-700">Company registration number</label>
                        <input type="text" wire:model.live="registrationNumber"
                               class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                               placeholder="2003/012345/07">
                        @error('registrationNumber') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-700">Phone</label>
                        <input type="text" wire:model.live="phone"
                               class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                               placeholder="011 555 1234">
                        @error('phone') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-700">Billing email</label>
                        <input type="email" wire:model.live="billingEmail"
                               class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                               placeholder="accounts@dealer.co.za">
                        @error('billingEmail') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-700">Delivery note footer</label>
                    <textarea wire:model.live="brandingFooter" rows="2"
                              class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                              placeholder="Banking details, signing line, or any legal copy you want printed at the bottom of every delivery note."></textarea>
                    <p class="mt-1 text-xs text-slate-400">Banking details, signing line, or any legal copy printed at the bottom of every delivery note.</p>
                    @error('brandingFooter') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="flex items-center gap-3">
                <button type="submit"
                        class="inline-flex items-center justify-center rounded-lg bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-blue-500 transition-colors">
                    Save branding
                </button>
                <span wire:loading wire:target="save" class="text-xs text-slate-400">Saving…</span>
            </div>
        </div>

        {{-- ===== Live masthead preview ===== --}}
        <div class="lg:col-span-1">
            <div class="sticky top-6">
                <h2 class="text-sm font-semibold text-slate-700 mb-2">Preview</h2>
                <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex-1">
                            @if($logoPreview)
                                <img src="{{ $logoPreview }}" alt="Logo" class="max-h-10 max-w-[60%] object-contain">
                            @else
                                <div class="text-base font-bold text-slate-900">{{ $company?->name }}</div>
                            @endif
                        </div>
                        <div class="text-right">
                            <div class="text-sm font-bold uppercase text-blue-800 leading-none">Delivery Note</div>
                            <div class="mt-1 text-[10px] text-slate-400">SDN-000123</div>
                            <div class="mt-2 text-[9px] leading-snug text-slate-500">
                                <div class="font-semibold text-slate-600">{{ $company?->name }}</div>
                                @if($address)<div class="whitespace-pre-line">{{ $address }}</div>@endif
                                @if($phone || $billingEmail)
                                    <div>
                                        @if($phone)Tel {{ $phone }}@endif{{ $phone && $billingEmail ? ' · ' : '' }}@if($billingEmail){{ $billingEmail }}@endif
                                    </div>
                                @endif
                                @if($vatNumber || $registrationNumber)
                                    <div>
                                        @if($vatNumber)VAT {{ $vatNumber }}@endif{{ $vatNumber && $registrationNumber ? ' · ' : '' }}@if($registrationNumber)Reg {{ $registrationNumber }}@endif
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>
                    <div class="mt-3 rounded bg-slate-900 px-2 py-1.5">
                        <span class="text-[10px] font-bold tracking-widest text-white">TRIDENT</span>
                        <span class="text-[8px] text-slate-300"> · Control &amp; Dispatch Center</span>
                    </div>
                    <div class="mt-4 border-t border-slate-100 pt-2 text-[8px] text-slate-400">
                        {{ $brandingFooter ?: ($company?->name . ' — issued via TRIDENT Control & Dispatch Center') }}
                    </div>
                </div>
                <p class="mt-2 text-xs text-slate-400">Live preview of your note's masthead and footer.</p>
            </div>
        </div>
    </form>
</div>
