<?php
use Livewire\Volt\Component;
use Livewire\Attributes\Layout;

new #[Layout('components.layouts.app')] class extends Component {};
?>

<div class="max-w-4xl mx-auto space-y-8">
    <div>
        <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">Help &amp; Information</h1>
        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Everything you need to know about using the booking system.</p>
    </div>

    {{-- Table of Contents --}}
    <div class="rounded-xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-800">
        <h2 class="text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Contents</h2>
        <nav class="mt-3 columns-1 sm:columns-2 gap-x-8">
            <ul class="space-y-2 text-sm">
                <li><a href="#roles" class="text-blue-600 hover:underline dark:text-blue-400">Roles &amp; Permissions</a></li>
                <li><a href="#creating-booking" class="text-blue-600 hover:underline dark:text-blue-400">Creating a Booking</a></li>
                <li><a href="#locations" class="text-blue-600 hover:underline dark:text-blue-400">Managing Locations</a></li>
                <li><a href="#cutoff" class="text-blue-600 hover:underline dark:text-blue-400">Collection Date &amp; Cutoff</a></li>
                <li><a href="#purchase-orders" class="text-blue-600 hover:underline dark:text-blue-400">Purchase Orders</a></li>
                <li><a href="#reassignment" class="text-blue-600 hover:underline dark:text-blue-400">Vehicle Reassignment</a></li>
                <li><a href="#deliveries" class="text-blue-600 hover:underline dark:text-blue-400">Viewing Deliveries</a></li>
            </ul>
        </nav>
    </div>

    {{-- Roles & Permissions --}}
    <section id="roles" class="scroll-mt-24 rounded-xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-800">
        <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">Roles &amp; Permissions</h2>
        <div class="mt-4 space-y-4 text-sm text-zinc-700 dark:text-zinc-300">
            <div>
                <h3 class="font-medium text-zinc-900 dark:text-white">Dealer Principal</h3>
                <p>Full access to the dealership account. Can manage team members, locations, and all bookings. This is the top-level role for your dealership.</p>
            </div>
            <div>
                <h3 class="font-medium text-zinc-900 dark:text-white">Sales Manager</h3>
                <p>Can create and manage bookings, and oversee team members within the dealership. Has access to all bookings created by the team.</p>
            </div>
            <div>
                <h3 class="font-medium text-zinc-900 dark:text-white">Sales Person</h3>
                <p>Can create new bookings and view their own bookings. Cannot manage team members or locations.</p>
            </div>
            <div>
                <h3 class="font-medium text-zinc-900 dark:text-white">Stock Controller</h3>
                <p>Read-only overview of all vehicle movements and bookings. Useful for tracking stock in transit without the ability to create or modify bookings.</p>
            </div>
        </div>
    </section>

    {{-- Creating a Booking --}}
    <section id="creating-booking" class="scroll-mt-24 rounded-xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-800">
        <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">Creating a Booking</h2>
        <div class="mt-4 space-y-3 text-sm text-zinc-700 dark:text-zinc-300">
            <p>Follow these steps to create a new transport booking:</p>
            <ol class="list-decimal list-inside space-y-2 pl-2">
                <li><strong>Select booking type</strong> &ndash; Choose the type of transport job you need.</li>
                <li><strong>Choose locations</strong> &ndash; Select a pickup and delivery location from your saved locations, or add a new one inline using the "Add New Location" option.</li>
                <li><strong>Enter vehicle details</strong> &ndash; VIN is required for every booking. Registration number is optional at this stage.</li>
                <li><strong>Set collection date &amp; time</strong> &ndash; Both the date and time are required. Be sure to select a date that falls within the allowed cutoff window.</li>
                <li><strong>Submit</strong> &ndash; Review your details and submit the booking.</li>
            </ol>
            <p>After submission, you can upload a Purchase Order from the booking detail page.</p>
        </div>
    </section>

    {{-- Managing Locations --}}
    <section id="locations" class="scroll-mt-24 rounded-xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-800">
        <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">Managing Locations</h2>
        <div class="mt-4 space-y-3 text-sm text-zinc-700 dark:text-zinc-300">
            <p>Navigate to the <strong>Locations</strong> page to manage your dealership's pickup and delivery addresses.</p>
            <ul class="list-disc list-inside space-y-1 pl-2">
                <li>Add new locations with a name and full address.</li>
                <li>Edit existing location details at any time.</li>
                <li>Deactivate locations you no longer use &ndash; they won't appear in booking forms but historical bookings are preserved.</li>
            </ul>
            <p>Locations are exclusive to your company and are not shared with other dealerships.</p>
        </div>
    </section>

    {{-- Collection Date & Cutoff --}}
    <section id="cutoff" class="scroll-mt-24 rounded-xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-800">
        <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">Collection Date &amp; Cutoff</h2>
        <div class="mt-4 space-y-3 text-sm text-zinc-700 dark:text-zinc-300">
            <p>You can freely edit the collection date and time on a booking <strong>until the cutoff deadline</strong>.</p>
            <p>Once the cutoff has passed, direct editing is locked. To request a change after cutoff, use the <strong>"Request Date Change"</strong> button on the booking detail page. This will submit a change request to the operations team for review.</p>
            <p>You'll receive an email notification once your change request has been approved or declined.</p>
        </div>
    </section>

    {{-- Purchase Orders --}}
    <section id="purchase-orders" class="scroll-mt-24 rounded-xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-800">
        <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">Purchase Orders</h2>
        <div class="mt-4 space-y-3 text-sm text-zinc-700 dark:text-zinc-300">
            <p>Purchase Orders (POs) can be uploaded at any time from the booking detail page.</p>
            <ul class="list-disc list-inside space-y-1 pl-2">
                <li>Multiple POs per booking are supported (e.g. separate transport and fuel POs).</li>
                <li>Upload PO documents using the file upload section on the booking detail page.</li>
                <li>A PO is not required at booking creation but should be attached before collection.</li>
            </ul>
        </div>
    </section>

    {{-- Vehicle Reassignment --}}
    <section id="reassignment" class="scroll-mt-24 rounded-xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-800">
        <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">Vehicle Reassignment</h2>
        <div class="mt-4 space-y-3 text-sm text-zinc-700 dark:text-zinc-300">
            <p>If the wrong vehicle was assigned to a booking, you can use the <strong>"Reassign Vehicle"</strong> action on the booking detail page.</p>
            <ul class="list-disc list-inside space-y-1 pl-2">
                <li>Enter the correct VIN and vehicle details.</li>
                <li>A new Purchase Order will be required for the reassigned vehicle.</li>
                <li>No penalty applies for vehicle reassignment.</li>
            </ul>
        </div>
    </section>

    {{-- Viewing Deliveries --}}
    <section id="deliveries" class="scroll-mt-24 rounded-xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-800">
        <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">Viewing Deliveries</h2>
        <div class="mt-4 space-y-3 text-sm text-zinc-700 dark:text-zinc-300">
            <p>The deliveries page provides two views:</p>
            <ul class="list-disc list-inside space-y-1 pl-2">
                <li><strong>All Deliveries</strong> &ndash; Shows every booking for your dealership, regardless of who created it.</li>
                <li><strong>My Deliveries</strong> &ndash; Filtered to show only bookings you personally created.</li>
            </ul>
            <p>The <strong>"Booked By"</strong> column shows the name of the team member who created each booking, making it easy to track responsibility.</p>
        </div>
    </section>
</div>
