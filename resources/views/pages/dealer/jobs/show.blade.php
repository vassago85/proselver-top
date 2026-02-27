<?php
use App\Models\Job;
use Livewire\Volt\Component;
use Livewire\Attributes\Layout;

new #[Layout('components.layouts.app')] class extends Component {
    public Job $job;

    public function mount(Job $job): void
    {
        $company = auth()->user()->company();
        if (!$company || $job->company_id !== $company->id) {
            abort(403);
        }
        $this->redirect(route('dealer.bookings.show', $job));
    }
};
?>
<div></div>
