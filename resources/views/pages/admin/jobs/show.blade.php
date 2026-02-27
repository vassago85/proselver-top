<?php

use App\Models\Job;
use Livewire\Volt\Component;
use Livewire\Attributes\Layout;

new #[Layout('components.layouts.app')] class extends Component {
    public Job $job;

    public function mount(Job $job): void
    {
        $this->job = $job;
        $this->redirect(route('admin.bookings.show', $job));
    }
};
?>
<div></div>
