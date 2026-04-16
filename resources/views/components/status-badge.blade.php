@props(['status'])

@php
    $statusMap = [
        // Legacy statuses
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
        // Phase 1 statuses
        'received' => ['label' => 'Received', 'color' => 'yellow'],
        'awaiting_customer_confirmation' => ['label' => 'Awaiting Confirmation', 'color' => 'amber'],
        'confirmation_issue' => ['label' => 'Confirmation Issue', 'color' => 'red'],
        'confirmed' => ['label' => 'Confirmed', 'color' => 'blue'],
        'planned' => ['label' => 'Planned', 'color' => 'indigo'],
        'driver_assigned' => ['label' => 'Driver Assigned', 'color' => 'purple'],
        'ready_for_collection' => ['label' => 'Ready for Collection', 'color' => 'cyan'],
        'collected' => ['label' => 'Collected', 'color' => 'teal'],
        'in_transit' => ['label' => 'In Transit', 'color' => 'orange'],
        'delivered' => ['label' => 'Delivered', 'color' => 'emerald'],
    ];
    $info = $statusMap[$status] ?? ['label' => ucfirst(str_replace('_', ' ', $status)), 'color' => 'gray'];
@endphp

<x-badge :color="$info['color']">{{ $info['label'] }}</x-badge>
