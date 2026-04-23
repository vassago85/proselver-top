@props(['status', 'dot' => true, 'size' => 'md'])

@php
    $statusMap = [
        // Legacy statuses (kept for backward compatibility)
        'pending_verification' => ['label' => 'Pending Verification', 'color' => 'yellow'],
        'verified' => ['label' => 'Verified', 'color' => 'blue'],
        'approved' => ['label' => 'Approved', 'color' => 'blue'],
        'rejected' => ['label' => 'Rejected', 'color' => 'red'],
        'assigned' => ['label' => 'Assigned', 'color' => 'purple'],
        'in_progress' => ['label' => 'In Progress', 'color' => 'orange'],
        'completed' => ['label' => 'Completed', 'color' => 'green'],
        'ready_for_invoicing' => ['label' => 'Ready for Invoicing', 'color' => 'blue'],
        'invoiced' => ['label' => 'Invoiced', 'color' => 'green'],
        'cancelled' => ['label' => 'Cancelled', 'color' => 'red'],

        // Phase 1 Trident statuses
        'received' => ['label' => 'Received', 'color' => 'slate'],
        'awaiting_customer_confirmation' => ['label' => 'Awaiting Confirmation', 'color' => 'amber'],
        'confirmation_issue' => ['label' => 'Confirmation Issue', 'color' => 'red'],
        'confirmed' => ['label' => 'Collection Confirmed', 'color' => 'cyan'],
        'planned' => ['label' => 'Planned', 'color' => 'indigo'],
        'driver_assigned' => ['label' => 'Driver Assigned', 'color' => 'purple'],
        // Legacy: older orders may still be in ready_for_collection; surface them
        // under the same "Collection Confirmed" label so the ops board reads cleanly.
        'ready_for_collection' => ['label' => 'Collection Confirmed', 'color' => 'cyan'],
        'collected' => ['label' => 'Arrived at Pickup', 'color' => 'teal'],
        'in_transit' => ['label' => 'In Transit', 'color' => 'orange'],
        'delivered' => ['label' => 'Delivered', 'color' => 'emerald'],
    ];
    $info = $statusMap[$status] ?? ['label' => ucwords(str_replace('_', ' ', $status)), 'color' => 'gray'];
@endphp

<x-badge :color="$info['color']" :dot="$dot" :size="$size">{{ $info['label'] }}</x-badge>
