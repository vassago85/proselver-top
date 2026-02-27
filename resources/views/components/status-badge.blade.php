@props(['status'])

@php
    $statusMap = [
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
    ];
    $info = $statusMap[$status] ?? ['label' => ucfirst(str_replace('_', ' ', $status)), 'color' => 'gray'];
@endphp

<x-badge :color="$info['color']">{{ $info['label'] }}</x-badge>
