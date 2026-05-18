<?php

use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
};
?>

<div>
    <x-slot:header>Help</x-slot:header>

    <div class="prose prose-slate max-w-3xl">
        <h2>How the body-builder portal works</h2>

        <p>
            This portal lets your workshop work directly with the dealers who've authorised you.
            Your job is to <strong>confirm vehicles when they arrive</strong> and, when work is done,
            <strong>request the next movement</strong> back to the dealer or on to the next fitment.
        </p>

        <h3>1. Vehicles tab</h3>
        <p>
            All vehicles in flight are listed here in three buckets:
        </p>
        <ul>
            <li><strong>Inbound</strong> — being dispatched to you right now. Watch for these so your team is ready when the truck rolls in.</li>
            <li><strong>On site</strong> — confirmed received and parked at your workshop. These are the ones you can request follow-up movements for.</li>
            <li><strong>Past</strong> — completed or cancelled jobs, for reference.</li>
        </ul>

        <h3>2. Confirm receipt</h3>
        <p>
            When a vehicle physically arrives at your workshop, open it from the Vehicles tab and click
            <strong>"Confirm receipt"</strong>. This tells the dealer and ProSelver that you've taken delivery.
            The vehicle then moves into the <strong>On site</strong> bucket.
        </p>

        <h3>3. Request the next movement</h3>
        <p>
            Two follow-up actions are available on any on-site vehicle:
        </p>
        <ul>
            <li><strong>Request next fitment</strong> — for when the truck needs to go on to another workshop (e.g. canopy → crane mounting → paint).</li>
            <li><strong>Request collection</strong> — for when the fitment work is complete and the vehicle is ready to go back to the dealer or out to a customer.</li>
        </ul>
        <p>
            Both raise a <em>pending request</em> that the dealer must approve. Once approved, ProSelver dispatches a driver and you'll see the new job ID on the request page.
        </p>

        <h3>4. My Requests</h3>
        <p>
            Track every request you've raised here. Pending requests can be cancelled if the vehicle isn't actually ready.
            Approved requests link to the new transport job, so you can see who's collecting and when.
        </p>

        <h3>5. Linked Dealers</h3>
        <p>
            Read-only list of dealers who've authorised your workshop. Removing or pausing the link is the dealer's call — talk to them directly if something looks wrong here.
        </p>

        <h3>Team &amp; Locations</h3>
        <p>
            If you're the body-builder owner you can add team members and workshop locations from the sidebar. Each team member's role decides what they can do:
        </p>
        <ul>
            <li><strong>Body Builder Owner</strong> — full access including team and locations management.</li>
            <li><strong>Body Builder User</strong> — can confirm receipts and raise requests.</li>
        </ul>

        <hr>
        <p class="text-sm text-slate-500">
            Need a dealer linked to you? Ask their admin to add your company from the dealer-side "Linked Body Builders" page.
            Stuck? Contact ProSelver support and we'll help.
        </p>
    </div>
</div>
