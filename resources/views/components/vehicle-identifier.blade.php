@props([
    // Any object with `vin` and `registration` string properties.  Works
    // uniformly on `App\Models\Job`, `App\Models\DealerStock`, and
    // `App\Models\MovementRequest`.  Pass `null` and it renders as `—`.
    'model' => null,
    // 'inline' (default) shows both identifiers on one line separated by
    // a dot.  'stacked' renders VIN above, Reg below (used in table cells
    // where vertical space is cheap and horizontal space is tight).
    'layout' => 'inline',
    // When true, prefixes the surviving identifier with a subtle "Reg"
    // marker if the VIN is missing -- so a reader scanning a list of
    // "VIN" columns knows this row is plate-only rather than typo-VIN.
    'markRegOnly' => true,
])

@php
    $vin = $model?->vin;
    $reg = $model?->registration;
    $hasVin = $vin !== null && $vin !== '';
    $hasReg = $reg !== null && $reg !== '';
@endphp

@if(!$hasVin && !$hasReg)
    <span class="text-slate-400">—</span>
@elseif($layout === 'stacked')
    <span class="font-mono">
        @if($hasVin)
            <span class="block">{{ $vin }}</span>
        @endif
        @if($hasReg)
            <span class="block text-[10px] text-slate-500 uppercase tracking-wide">
                @if(!$hasVin && $markRegOnly)Reg @endif{{ $reg }}
            </span>
        @endif
    </span>
@else
    <span class="font-mono">
        @if($hasVin){{ $vin }}@endif
        @if($hasVin && $hasReg)<span class="text-slate-300"> · </span>@endif
        @if($hasReg)@if(!$hasVin && $markRegOnly)<span class="text-slate-500 text-[10px] uppercase tracking-wide mr-1">Reg</span>@endif{{ $reg }}@endif
    </span>
@endif
