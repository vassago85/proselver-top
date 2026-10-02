{{--
    One-time POD upload notice. Mounted by the app layout for internal
    users. z-[70] sits under the stale-action gate (z-[80]) so that
    gate stays the one they have to deal with first.
--}}
<div>
    @if($show)
        <div class="fixed inset-0 z-[70] flex items-center justify-center bg-slate-900/60 p-4"
             role="dialog"
             aria-modal="true"
             aria-labelledby="pod-upload-hint-title"
             wire:click.self="dismiss">
            <div class="w-full max-w-md overflow-hidden rounded-2xl bg-white shadow-2xl ring-1 ring-slate-900/5">
                <div class="border-b border-blue-100 bg-blue-50 px-6 py-4">
                    <h2 id="pod-upload-hint-title" class="text-base font-semibold text-blue-950">You can upload PODs now</h2>
                    <p class="mt-1 text-sm text-blue-900/80">A proof of delivery can be filed from the office, without waiting for the driver.</p>
                </div>

                <div class="px-6 py-5 text-sm leading-relaxed text-slate-700">
                    @if($onDocuments)
                        <p>Use <strong>Upload a POD</strong> at the top of this page. Enter the job number or VIN, then choose a PDF or a photo.</p>
                    @else
                        <p>Open <strong>Documents</strong> in the sidebar, under Ops · Booking. At the top of that page, use <strong>Upload a POD</strong>. Enter the job number or VIN, then choose a PDF or a photo.</p>
                    @endif
                </div>

                <div class="flex items-center justify-end gap-2 border-t border-slate-200 bg-slate-50 px-6 py-4">
                    @unless($onDocuments)
                        <button type="button" wire:click="goToDocuments"
                                class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-500">
                            Go to Documents
                        </button>
                    @endunless
                    <button type="button" wire:click="dismiss"
                            class="rounded-lg px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100">
                        Got it
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
