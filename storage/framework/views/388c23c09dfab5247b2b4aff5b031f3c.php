<?php ($extraccion = $this->extraccionActual()); ?>

<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($extraccion): ?>
    <div <?php if(in_array($extraccion->estado, ['pendiente', 'en_progreso'], true)): ?> wire:poll.3s="refreshExtraccion" <?php endif; ?>>
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

         <?php $__env->slot('heading', null, []); ?> 
            Extracción #<?php echo e($extraccion->id); ?>

            <span class="opm-status"><?php echo e(ucfirst(str_replace('_', ' ', $extraccion->estado))); ?></span>
         <?php $__env->endSlot(); ?>

        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(in_array($extraccion->estado, ['pendiente', 'en_progreso'], true) && auth()->user()?->hasPermission('kardex.extraccion.anular')): ?>
             <?php $__env->slot('afterHeader', null, []); ?> 
                <?php if (isset($component)) { $__componentOriginal6330f08526bbb3ce2a0da37da512a11f = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal6330f08526bbb3ce2a0da37da512a11f = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'filament::components.button.index','data' => ['color' => 'danger','size' => 'sm','icon' => 'heroicon-o-x-circle','wire:click' => 'anularExtraccion','wire:confirm' => '¿Anular esta extracción? Los locales que aún no se hayan procesado se quedarán sin guardar.']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('filament::button'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['color' => 'danger','size' => 'sm','icon' => 'heroicon-o-x-circle','wire:click' => 'anularExtraccion','wire:confirm' => '¿Anular esta extracción? Los locales que aún no se hayan procesado se quedarán sin guardar.']); ?>
<?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::processComponentKey($component); ?>

                    Anular extracción
                 <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal6330f08526bbb3ce2a0da37da512a11f)): ?>
<?php $attributes = $__attributesOriginal6330f08526bbb3ce2a0da37da512a11f; ?>
<?php unset($__attributesOriginal6330f08526bbb3ce2a0da37da512a11f); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal6330f08526bbb3ce2a0da37da512a11f)): ?>
<?php $component = $__componentOriginal6330f08526bbb3ce2a0da37da512a11f; ?>
<?php unset($__componentOriginal6330f08526bbb3ce2a0da37da512a11f); ?>
<?php endif; ?>
             <?php $__env->endSlot(); ?>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($extraccion->estado === 'en_progreso' || $extraccion->estado === 'completado'): ?>
            <div class="mb-3">
                <div class="h-2 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-white/10">
                    <div class="h-full rounded-full bg-primary-600 transition-all" style="width: <?php echo e($extraccion->progreso); ?>%"></div>
                </div>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400"><?php echo e($extraccion->progreso); ?>% · <?php echo e($extraccion->locales_procesados + $extraccion->locales_fallidos); ?> de <?php echo e($extraccion->locales_total ?? '—'); ?> locales procesados</p>
            </div>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

        <div class="grid grid-cols-2 gap-4 text-sm sm:grid-cols-4">
            <div><span class="block text-gray-500 dark:text-gray-400">Movimientos guardados</span><strong class="text-gray-950 dark:text-white"><?php echo e($extraccion->movimientos_guardados); ?></strong></div>
            <div><span class="block text-gray-500 dark:text-gray-400">Locales procesados</span><strong class="text-gray-950 dark:text-white"><?php echo e($extraccion->locales_procesados); ?></strong></div>
            <div><span class="block text-gray-500 dark:text-gray-400">Locales fallidos</span><strong class="text-danger-600 dark:text-danger-400"><?php echo e($extraccion->locales_fallidos); ?></strong></div>
            <div><span class="block text-gray-500 dark:text-gray-400">Duración</span><strong class="text-gray-950 dark:text-white"><?php echo e($extraccion->duracion ?? '—'); ?></strong></div>
        </div>

        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(in_array($extraccion->estado, ['fallido', 'cancelado'], true) && $extraccion->mensaje_error): ?>
            <p class="mt-3 text-sm font-medium text-danger-600 dark:text-danger-400"><?php echo e($extraccion->mensaje_error); ?></p>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($extraccion->locales->isNotEmpty()): ?>
            <div class="mt-4 opm-stock-table">
                <div class="fi-ta-content overflow-x-auto">
                    <table class="fi-ta-table w-full text-start">
                        <thead>
                            <tr>
                                <th class="fi-ta-header-cell"><span class="fi-ta-header-cell-label">Local</span></th>
                                <th class="fi-ta-header-cell"><span class="fi-ta-header-cell-label">Estado</span></th>
                                <th class="fi-ta-header-cell"><span class="fi-ta-header-cell-label">Movimientos</span></th>
                                <th class="fi-ta-header-cell"><span class="fi-ta-header-cell-label">Error</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::openLoop(); ?><?php endif; ?><?php $__currentLoopData = $extraccion->locales; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $local): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::startLoopIteration(); ?><?php endif; ?>
                                <tr class="fi-ta-row">
                                    <td class="fi-ta-cell"><div class="px-3 py-2 text-sm text-gray-950 dark:text-white"><?php echo e($local->local_nombre ?? $local->local_id); ?></div></td>
                                    <td class="fi-ta-cell"><div class="px-3 py-2"><span class="opm-status"><?php echo e(ucfirst(str_replace('_', ' ', $local->estado))); ?></span></div></td>
                                    <td class="fi-ta-cell"><div class="opm-table-number px-3 py-2 text-sm text-gray-950 dark:text-white"><?php echo e($local->movimientos_guardados); ?></div></td>
                                    <td class="fi-ta-cell"><div class="px-3 py-2 text-sm text-danger-600 dark:text-danger-400"><?php echo e($local->mensaje_error ?? '—'); ?></div></td>
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
    </div>
<?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
<?php /**PATH D:\DS-TI\CRM-DIMSUM\opm-digemid\resources\views/filament/pages/kardex/partials/extraccion-progreso.blade.php ENDPATH**/ ?>