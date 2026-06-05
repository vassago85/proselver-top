<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    // ===== Profile section =====
    public string $name = '';
    public string $email = '';
    public string $phone = '';

    // ===== Password section =====
    public string $currentPassword = '';
    public string $newPassword = '';
    public string $newPasswordConfirmation = '';

    public function mount(): void
    {
        $user = auth()->user();
        $this->name = $user->name ?? '';
        $this->email = $user->email ?? '';
        $this->phone = $user->phone ?? '';
    }

    /**
     * Update the signed-in user's profile information (name, email, phone).
     * Email is unique across users, ignoring the current user's own row so
     * saving without changing the address does not trip the unique rule.
     */
    public function updateProfile(): void
    {
        $user = auth()->user();

        $data = $this->validate([
            'name'  => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:32'],
        ]);

        $user->forceFill([
            'name'  => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?: null,
        ])->save();

        session()->flash('profileStatus', 'Profile updated.');
    }

    /**
     * Update the signed-in user's password. Requires the current password so
     * a hijacked session (stolen cookie, left unlocked) cannot silently change
     * the credential.
     *
     * Post-save we ALWAYS issue a redirect, even for users not in forced-
     * rotation mode. Without this, a successful save resets the three password
     * fields to '' (their initial value), Livewire computes no DOM diff and
     * returns an ~89-byte no-op response; the user sees no feedback whatsoever
     * and is convinced the page has hung. A hard redirect to the same page
     * (or to the role home for forced-rotation users) gives them an unambiguous
     * "we did the thing" with the flash message visible.
     */
    public function updatePassword(): void
    {
        try {
            $data = $this->validate([
                'currentPassword'          => ['required', 'string'],
                'newPassword'              => ['required', 'string', 'min:8', 'confirmed:newPasswordConfirmation', 'different:currentPassword'],
                'newPasswordConfirmation'  => ['required', 'string'],
            ]);
        } catch (ValidationException $e) {
            // Never leak password fields back into the DOM.
            $this->reset('currentPassword', 'newPassword', 'newPasswordConfirmation');
            throw $e;
        }

        $user = auth()->user();

        if (!Hash::check($data['currentPassword'], $user->password)) {
            $this->reset('currentPassword', 'newPassword', 'newPasswordConfirmation');
            throw ValidationException::withMessages([
                'currentPassword' => 'The current password is incorrect.',
            ]);
        }

        // Capture the forced-rotation state from the model BEFORE we clear it.
        // Don't read this from the URL (`?must_change=1`) — Livewire's POST to
        // /livewire-{id}/update has no query string, so a URL-based check
        // always reads false for these AJAX calls and the redirect never fires.
        $wasForcedRotation = (bool) $user->must_change_password;

        $user->forceFill([
            'password' => Hash::make($data['newPassword']),
            'must_change_password' => false,
            'password_changed_at' => now(),
        ])->save();

        $this->reset('currentPassword', 'newPassword', 'newPasswordConfirmation');

        if ($wasForcedRotation) {
            session()->flash('passwordStatus', 'Password updated. Welcome back.');
            $this->redirect(resolveUserHomePath($user), navigate: false);
            return;
        }

        // Self-service change (user wasn't forced) — reload the profile page so
        // the success banner is visibly rendered. Livewire would otherwise emit
        // an empty diff because every property reset back to its initial value.
        session()->flash('passwordStatus', 'Password updated.');
        $this->redirect(route('profile.index'), navigate: false);
    }
};
?>

<div>
    <x-slot:header>Profile &amp; security</x-slot:header>

    <div class="mx-auto max-w-3xl space-y-6">

        @if(request()->boolean('must_change') || auth()->user()->must_change_password)
            <div class="rounded-2xl border border-amber-300 bg-amber-50 p-5 shadow-sm">
                <div class="flex items-start gap-3">
                    <svg class="h-5 w-5 text-amber-600 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                    </svg>
                    <div class="text-sm">
                        <p class="font-semibold text-amber-900">Password change required</p>
                        <p class="mt-1 text-amber-800">Your account is currently using a temporary password set by an administrator. Please choose a new password below before continuing.</p>
                    </div>
                </div>
            </div>
        @endif

        {{-- Identity card --}}
        <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
            <div class="px-6 py-5 border-b border-slate-100 flex items-center gap-4">
                <span class="h-12 w-12 rounded-xl bg-gradient-to-br from-slate-800 to-slate-900 text-white flex items-center justify-center text-sm font-semibold ring-1 ring-slate-900/10">
                    {{ strtoupper(substr(auth()->user()->name, 0, 1)) }}{{ strtoupper(substr(strstr(auth()->user()->name, ' ') ?: '', 1, 1)) }}
                </span>
                <div class="min-w-0">
                    <p class="text-base font-semibold text-slate-900 truncate">{{ auth()->user()->name }}</p>
                    @php
                        // Shared helper -- matches the header user menu and
                        // the team page so the same user sees the same label
                        // everywhere in the portal.
                        $profileRoleName = tenantRoleDisplayName(
                            auth()->user()->roles->first()?->name ?? 'Member',
                            optional(auth()->user()->companies()->first())->type,
                        );
                    @endphp
                    <p class="text-xs text-slate-500 truncate">
                        {{ $profileRoleName }}
                        @if(auth()->user()->username)
                            · <span class="font-mono">{{ auth()->user()->username }}</span>
                        @endif
                    </p>
                </div>
            </div>
        </div>

        {{-- Profile information --}}
        <form wire:submit="updateProfile" class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
            <div class="px-6 py-5 border-b border-slate-100">
                <h2 class="text-base font-semibold text-slate-900">Profile information</h2>
                <p class="mt-1 text-xs text-slate-500">Your display name, contact email and phone. Used across the app and on notifications sent to you.</p>
            </div>

            @if(session('profileStatus'))
                <div class="mx-6 mt-4 rounded-lg bg-emerald-50 border border-emerald-200 px-3 py-2 text-xs font-medium text-emerald-800">
                    {{ session('profileStatus') }}
                </div>
            @endif

            <div class="px-6 py-5 grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="sm:col-span-2">
                    <label for="name" class="block text-xs font-semibold uppercase tracking-wide text-slate-600 mb-1.5">Full name</label>
                    <input wire:model="name" id="name" type="text" autocomplete="name"
                        class="block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 focus:border-blue-500 focus:ring-blue-500">
                    @error('name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="email" class="block text-xs font-semibold uppercase tracking-wide text-slate-600 mb-1.5">Email address</label>
                    <input wire:model="email" id="email" type="email" autocomplete="email"
                        class="block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 focus:border-blue-500 focus:ring-blue-500">
                    @error('email') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="phone" class="block text-xs font-semibold uppercase tracking-wide text-slate-600 mb-1.5">Phone</label>
                    <input wire:model="phone" id="phone" type="tel" autocomplete="tel"
                        class="block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 focus:border-blue-500 focus:ring-blue-500"
                        placeholder="+27 82 …">
                    @error('phone') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="px-6 py-4 bg-slate-50/70 border-t border-slate-100 flex items-center justify-end gap-2">
                <button type="submit" wire:loading.attr="disabled" wire:target="updateProfile"
                    class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-500 disabled:opacity-60 transition">
                    <span wire:loading.remove wire:target="updateProfile">Save changes</span>
                    <span wire:loading wire:target="updateProfile">Saving…</span>
                </button>
            </div>
        </form>

        {{-- Password --}}
        <form wire:submit="updatePassword" class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
            <div class="px-6 py-5 border-b border-slate-100">
                <h2 class="text-base font-semibold text-slate-900">Password</h2>
                <p class="mt-1 text-xs text-slate-500">Use at least 8 characters. You'll need your current password to confirm the change.</p>
            </div>

            @if(session('passwordStatus'))
                <div class="mx-6 mt-4 rounded-lg bg-emerald-50 border border-emerald-200 px-3 py-2 text-xs font-medium text-emerald-800">
                    {{ session('passwordStatus') }}
                </div>
            @endif

            <div class="px-6 py-5 grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="sm:col-span-2">
                    <label for="currentPassword" class="block text-xs font-semibold uppercase tracking-wide text-slate-600 mb-1.5">Current password</label>
                    <input wire:model="currentPassword" id="currentPassword" type="password" autocomplete="current-password"
                        class="block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 focus:border-blue-500 focus:ring-blue-500">
                    @error('currentPassword') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="newPassword" class="block text-xs font-semibold uppercase tracking-wide text-slate-600 mb-1.5">New password</label>
                    <input wire:model="newPassword" id="newPassword" type="password" autocomplete="new-password"
                        class="block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 focus:border-blue-500 focus:ring-blue-500">
                    @error('newPassword') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="newPasswordConfirmation" class="block text-xs font-semibold uppercase tracking-wide text-slate-600 mb-1.5">Confirm new password</label>
                    <input wire:model="newPasswordConfirmation" id="newPasswordConfirmation" type="password" autocomplete="new-password"
                        class="block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 focus:border-blue-500 focus:ring-blue-500">
                    @error('newPasswordConfirmation') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="px-6 py-4 bg-slate-50/70 border-t border-slate-100 flex items-center justify-end gap-2">
                <button type="submit" wire:loading.attr="disabled" wire:target="updatePassword"
                    class="inline-flex items-center gap-2 rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800 disabled:opacity-60 transition">
                    <span wire:loading.remove wire:target="updatePassword">Update password</span>
                    <span wire:loading wire:target="updatePassword">Updating…</span>
                </button>
            </div>
        </form>

    </div>
</div>
