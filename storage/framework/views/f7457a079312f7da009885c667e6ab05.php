<?php ($historial = $this->historial()); ?>

<?php if (isset($component)) { $__componentOriginalee08b1367eba38734199cf7829b1d1e9 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalee08b1367eba38734199cf7829b1d1e9 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'filament::components.section.index','data' => []] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('filament::section'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes([]); ?>
<?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::processComponentKey($component); ?>

     <?php $__env->slot('heading', null, []); ?> Historial de extracciones <?php $__env->endSlot(); ?>

    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($historial->isEmpty()): ?>
        <?php if (isset($component)) { $__componentOriginal18b7d5277b8ac8ab91a5868675cf72d4 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal18b7d5277b8ac8ab91a5868675cf72d4 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'filament::components.empty-state','data' => ['icon' => 'heroicon-o-circle-stack','heading' => 'Todavía no se ha corrido ninguna extracción.']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('filament::empty-state'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['icon' => 'heroicon-o-circle-stack','heading' => 'Todavía no se ha corrido ninguna extracción.']); ?>
<?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::processComponentKey($component); ?>

<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal18b7d5277b8ac8ab91a5868675cf72d4)): ?>
<?php $attributes = $__attributesOriginal18b7d5277b8ac8ab91a5868675cf72d4; ?>
<?php unset($__attributesOriginal18b7d5277b8ac8ab91a5868675cf72d4); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal18b7d5277b8ac8ab91a5868675cf72d4)): ?>
<?php $component = $__componentOriginal18b7d5277b8ac8ab91a5868675cf72d4; ?>
<?php unset($__componentOriginal18b7d5277b8ac8ab91a5868675cf72d4); ?>
<?php endif; ?>
    <?php else: ?>
        <div class="opm-stock-table">
            <div class="fi-ta-content overflow-x-auto">
                <table class="fi-ta-table w-full text-start">
                    <thead>
                        <tr>
                            <th class="fi-ta-header-cell"><span class="fi-ta-header-cell-label">Cód.</span></th>
                            <th class="fi-ta-header-cell"><span class="fi-ta-header-cell-label">Rango</span></th>
                            <th class="fi-ta-header-cell"><span class="fi-ta-header-cell-label">Estado</span></th>
                            <th class="fi-ta-header-cell"><span class="fi-ta-header-cell-label">Locales</span></th>
                            <th class="fi-ta-header-cell"><span class="fi-ta-header-cell-label">Movimientos</span></th>
                            <th class="fi-ta-header-cell"><span class="fi-ta-header-cell-label">Fallidos</span></th>
                            <th class="fi-ta-header-cell"><span class="fi-ta-header-cell-label">Duración</span></th>
                            <th class="fi-ta-header-cell"><span class="fi-ta-header-cell-label">Iniciado</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::openLoop(); ?><?php endif; ?><?php $__currentLoopData = $historial; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $item): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::startLoopIteration(); ?><?php endif; ?>
                            <tr class="fi-ta-row">
                                <td class="fi-ta-cell"><div class="px-3 py-3 text-sm text-gray-950 dark:text-white"><?php echo e($item->id); ?></div></td>
                                <td class="fi-ta-cell"><div class="px-3 py-3 text-sm whitespace-nowrap text-gray-950 dark:text-white"><?php echo e($item->filtros['fechaInicio'] ?? '—'); ?> al <?php echo e($item->filtros['fechaFin'] ?? '—'); ?></div></td>
                                <td class="fi-ta-cell"><div class="px-3 py-3"><span class="opm-status"><?php echo e(ucfirst(str_replace('_', ' ', $item->estado))); ?></span></div></td>
                                <td class="fi-ta-cell"><div class="opm-table-number px-3 py-3 text-sm text-gray-950 dark:text-white"><?php echo e($item->locales_procesados); ?> / <?php echo e($item->locales_total ?? '—'); ?></div></td>
                                <td class="fi-ta-cell"><div class="opm-table-number px-3 py-3 text-sm text-gray-950 dark:text-white"><?php echo e($item->movimientos_guardados); ?></div></td>
                                <td class="fi-ta-cell"><div class="opm-table-number px-3 py-3 text-sm text-danger-600 dark:text-danger-400"><?php echo e($item->locales_fallidos); ?></div></td>
                                <td class="fi-ta-cell"><div class="px-3 py-3 text-sm text-gray-950 dark:text-white"><?php echo e($item->duracion ?? '—'); ?></div></td>
                                <td class="fi-ta-cell"><div class="px-3 py-3 text-sm whitespace-nowrap text-gray-950 dark:text-white"><?php echo e($item->iniciado_at?->format('d/m/Y H:i') ?? '—'); ?></div></td>
                            </tr>
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::endLoop(); ?><?php endif; ?><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::closeLoop(); ?><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
 <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalee08b1367eba38734199cf7829b1d1e9)): ?>
<?php $attributes = $__attributesOriginalee08b1367eba38734199cf7829b1d1e9; ?>
<?php unset($__attributesOriginalee08b1367eba38734199cf7829b1d1e9); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalee08b1367eba38734199cf7829b1d1e9)): ?>
<?php $component = $__componentOriginalee08b1367eba38734199cf7829b1d1e9; ?>
<?php unset($__componentOriginalee08b1367eba38734199cf7829b1d1e9); ?>
<?php endif; ?>
<?php /**PATH D:\DS-TI\CRM-DIMSUM\opm-digemid\resources\views/filament/pages/kardex/partials/extraccion-historial.blade.php ENDPATH**/ ?>