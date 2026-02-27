<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames((['label', 'value', 'color' => 'blue', 'href' => null]));

foreach ($attributes->all() as $__key => $__value) {
    if (in_array($__key, $__propNames)) {
        $$__key = $$__key ?? $__value;
    } else {
        $__newAttributes[$__key] = $__value;
    }
}

$attributes = new \Illuminate\View\ComponentAttributeBag($__newAttributes);

unset($__propNames);
unset($__newAttributes);

foreach (array_filter((['label', 'value', 'color' => 'blue', 'href' => null]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>

<?php
    $colors = [
        'blue' => 'bg-blue-50 text-blue-700',
        'green' => 'bg-green-50 text-green-700',
        'red' => 'bg-red-50 text-red-700',
        'yellow' => 'bg-yellow-50 text-yellow-700',
        'gray' => 'bg-gray-50 text-gray-700',
    ];
    $colorClass = $colors[$color] ?? $colors['blue'];
?>

<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($href): ?>
<a href="<?php echo e($href); ?>" class="block rounded-xl border border-gray-200 bg-white p-6 shadow-sm hover:shadow-md transition-shadow">
<?php else: ?>
<div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
<?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    <dt class="text-sm font-medium text-gray-500 truncate"><?php echo e($label); ?></dt>
    <dd class="mt-2 flex items-baseline gap-x-2">
        <span class="text-3xl font-bold tracking-tight <?php echo e($colorClass); ?> px-2 py-0.5 rounded-lg"><?php echo e($value); ?></span>
    </dd>
<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($href): ?>
</a>
<?php else: ?>
</div>
<?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
<?php /**PATH /var/www/html/resources/views/components/stat-card.blade.php ENDPATH**/ ?>