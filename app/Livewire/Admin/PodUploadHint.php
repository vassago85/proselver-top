<?php

namespace App\Livewire\Admin;

use Livewire\Component;

/**
 * One-time notice that office staff can upload a proof of delivery
 * from Documents. Closing it writes user_dismissed_hints so the same
 * account never sees it again.
 */
class PodUploadHint extends Component
{
    public const HINT = 'pod_upload_documents';

    public bool $show = false;

    public function mount(): void
    {
        $user = auth()->user();
        if (! $user || ! $user->isInternal() || $user->hasDismissedHint(self::HINT)) {
            return;
        }

        $this->show = true;
    }

    public function dismiss(): void
    {
        auth()->user()?->dismissHint(self::HINT);
        $this->show = false;
    }

    public function goToDocuments(): void
    {
        $this->dismiss();
        $this->redirect(route('admin.documents.index'), navigate: true);
    }

    public function render()
    {
        return view('livewire.admin.pod-upload-hint', [
            'onDocuments' => request()->routeIs('admin.documents.index'),
        ]);
    }
}
