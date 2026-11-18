<x-layouts::app :title="($record ? 'Editar' : 'Nuevo').' · '.$catalog->title">
    @php
        $groups = collect($catalog->fields)->groupBy(fn ($field) => $field['group'] ?? 'Datos generales', true);
        $recordName = $record?->getAttribute($catalog->labelColumn) ?: 'Nuevo registro';
        $recordReference = $record ? match ($catalog->slug) {
            'cuentas' => $record->getAttribute('cuenta'),
            'productos' => $record->getAttribute('codigo'),
            default => '#'.$record->getKey(),
        } : null;
        $groupIcons = ['Clasificación' => 'adjustments-horizontal', 'Contabilidad' => 'calculator', 'Precios y descuentos' => 'currency-dollar', 'Contacto' => 'phone', 'Ubicación' => 'map-pin', 'Inventario y ubicación' => 'cube'];
        $location = collect($catalog->fields)
            ->filter(fn ($field) => in_array($field['lookup'] ?? '', ['departments', 'districts', 'municipalities'], true))
            ->mapWithKeys(fn ($field, $column) => [$field['lookup'] => (string) old($column, $record?->getAttribute($column))])->all();
        $productTaxType = (string) old('iva2', $record ? ($record->getAttribute('iva2') ?? '') : 'A');
    @endphp
    <div class="catalog-workspace mx-auto flex w-full max-w-7xl flex-col gap-6">
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <nav aria-label="Ruta de navegación" class="mb-3 flex flex-wrap items-center gap-2 text-xs text-zinc-500 dark:text-zinc-400">
                    <a href="{{ route('contaduria.catalogos') }}" wire:navigate class="hover:underline">Catálogos</a><flux:icon.chevron-right class="size-3" />
                    <a href="{{ route('contaduria.catalogos.registros.index', $catalog->slug) }}" wire:navigate class="hover:underline">{{ $catalog->title }}</a>
                </nav>
                <h1 class="text-2xl font-semibold tracking-tight">{{ $record ? 'Editar registro' : 'Nuevo registro' }}</h1>
                <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">{{ $record ? 'Actualiza la información del registro seleccionado.' : 'Completa la información para agregar un nuevo registro.' }}</p>
            </div>
            <flux:button :href="route('contaduria.catalogos.registros.index', $catalog->slug)" wire:navigate variant="ghost" icon="arrow-left">Volver al catálogo</flux:button>
        </header>
        @if ($errors->any())
            <div x-data x-init="$nextTick(() => $flux.modal('catalog-validation').show())">
                <flux:modal name="catalog-validation" :dismissible="false" :closable="false" data-theme="light" class="w-full max-w-lg rounded-2xl">
                    <div class="space-y-5">
                        <div class="flex items-start gap-4">
                            <span class="flex size-12 shrink-0 items-center justify-center rounded-2xl border border-brand-yellow bg-brand-yellow/20 text-brand-ink"><flux:icon.exclamation-triangle class="size-6" /></span>
                            <div>
                                <flux:heading size="lg">No se guardaron los cambios</flux:heading>
                                <p class="mt-2 text-sm leading-6 text-zinc-600">Corrige los siguientes datos y vuelve a guardar.</p>
                            </div>
                        </div>
                        <div role="alert" class="max-h-64 overflow-y-auto rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">
                            <ul class="list-disc space-y-2 ps-5">
                                @foreach ($errors->all() as $message)
                                    <li>{{ $message }}</li>
                                @endforeach
                            </ul>
                        </div>
                        <div class="flex justify-end border-t border-zinc-200 pt-4">
                            <flux:modal.close><flux:button variant="primary" icon="pencil-square">Revisar formulario</flux:button></flux:modal.close>
                        </div>
                    </div>
                </flux:modal>
            </div>
        @endif
        <div class="grid items-start gap-6 xl:grid-cols-[16rem_minmax(0,1fr)]">
            <aside class="min-w-0 space-y-4 xl:sticky xl:top-6">
                <section class="catalog-summary p-5" aria-label="Registro seleccionado">
                    <div class="mb-5 flex items-center justify-between gap-3"><span class="catalog-icon"><flux:icon :name="$catalog->icon" class="size-6" /></span><span class="rounded-full border border-brand-paper/15 px-2.5 py-1 text-[10px] text-brand-paper/65">{{ $record ? 'Edición' : 'Nuevo' }}</span></div>
                    <p class="catalog-summary__label">{{ $catalog->title }}</p>
                    <h2 class="mt-3 break-words text-base leading-6 font-semibold">{{ $recordName }}</h2>
                    @if ($recordReference)<p class="mt-3 break-all font-mono text-xs text-brand-yellow">{{ $recordReference }}</p>@endif
                    <p class="mt-5 border-t border-brand-paper/10 pt-4 text-xs leading-5 text-brand-paper/50">{{ $catalog->description }}</p>
                </section>
                <nav class="catalog-panel hidden p-3 xl:block" data-theme="light" aria-label="Secciones del formulario">
                    <p class="px-3 pt-2 pb-3 text-[10px] font-semibold tracking-wider text-zinc-500 uppercase">En este formulario</p>
                    @foreach ($groups as $group => $fields)
                        <a href="#catalog-section-{{ $loop->index }}" class="catalog-section-link"><flux:icon :name="$groupIcons[$group] ?? 'document-text'" class="size-4 shrink-0" /><span class="flex-1">{{ $group }}</span><span class="font-mono text-[10px] text-zinc-400">{{ count($fields) }}</span></a>
                    @endforeach
                </nav>
                <flux:modal.trigger name="catalog-form-help"><flux:button type="button" class="w-full" icon="information-circle">Ayuda del formulario</flux:button></flux:modal.trigger>
            </aside>
        <form method="POST" action="{{ $record ? route('contaduria.catalogos.registros.update', [$catalog->slug, $record->getKey()]) : route('contaduria.catalogos.registros.store', $catalog->slug) }}" class="catalog-form min-w-0 space-y-5" x-data="catalogLocation(@js($options['districts'] ?? []), @js($location))">
            @csrf
            @if ($record)@method('PUT')@endif
            <div class="catalog-savebar" data-theme="light">
                <span class="hidden items-center gap-2 text-xs text-zinc-500 sm:flex"><flux:icon.pencil-square class="size-4" />{{ $record ? 'Editando información' : 'Creando un registro' }}</span>
                <div class="ms-auto flex gap-2"><flux:button :href="route('contaduria.catalogos.registros.index', $catalog->slug)" wire:navigate variant="ghost">Cancelar</flux:button><flux:button type="submit" variant="primary" icon="check-circle">{{ $record ? 'Guardar cambios' : 'Crear registro' }}</flux:button></div>
            </div>
            <div class="space-y-5" @if ($catalog->slug === 'productos') x-data="productPricing(@js((string) old('p_venta', $record?->getAttribute('p_venta') ?? '')), @js($productTaxType))" @endif>
            @foreach ($groups as $group => $fields)
                <fieldset id="catalog-section-{{ $loop->index }}" data-theme="light" class="catalog-panel catalog-section">
                    <legend class="sr-only">{{ $group }}</legend>
                    <div class="catalog-section__heading"><span class="catalog-icon"><flux:icon :name="$groupIcons[$group] ?? 'document-text'" class="size-5" /></span><div class="flex-1"><h2 class="text-sm font-semibold">{{ $group }}</h2><p class="mt-1 text-[11px] text-zinc-500">{{ count($fields) }} campos</p></div><span class="font-mono text-xs text-zinc-400">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span></div>
                    <div class="catalog-section__fields">
                        @foreach ($fields as $column => $field)
                            @php
                                $value = old($column, $record?->getAttribute($column));
                                if ($value instanceof \BackedEnum) { $value = $value->value; }
                                if (is_bool($value)) { $value = $value ? '1' : '0'; }
                                $value = $value === null ? '' : trim((string) $value);
                                $choices = $field['choices'] ?? [];
                                if ($field['type'] === 'boolean') { $choices = ['1' => 'Sí', '0' => 'No']; }
                                $lookup = isset($field['lookup']) ? ($options[$field['lookup']] ?? []) : null;
                                $required = $field['required'] ?? false;
                                $label = $field['label'].($required ? ' *' : '');
                                $choiceOptions = collect($choices)->map(fn ($name, $key) => ['value' => (string) $key, 'label' => $name])->values()->all();
                            @endphp
                            <div @class(['catalog-field--wide' => in_array($column, ['nombre', 'nombre_proveedor', 'nombre_recibo', 'descrip', 'descripcion', 'direccion'], true) || ($catalog->slug === 'productos' && $column === 'p_venta')])>
                                @if ($catalog->slug === 'productos' && $column === 'p_venta')
                                    <div class="grid gap-4 md:grid-cols-2">
                                        <flux:input :name="$column" :label="$label" :value="$value" x-model="netPrice" x-on:input="fromNet($event.target.value)" type="number" min="0" max="99999.999999" step="0.000001" />
                                        <flux:input id="product-price-with-tax" label="Precio de venta con IVA" x-model="grossPrice" x-on:input="fromGross($event.target.value)" x-effect="$el.setCustomValidity(grossError)" x-bind:disabled="!hasTaxType" type="number" min="0" x-bind:max="taxType === 'A' ? '112999.999999' : '99999.999999'" step="0.000001" />
                                    </div>
                                    <div class="mt-3"><flux:modal.trigger name="product-price-help"><flux:button type="button" size="sm" icon="information-circle">Información de precios e IVA</flux:button></flux:modal.trigger></div>
                                @elseif ($catalog->slug === 'productos' && $column === 'iva2')
                                    <x-catalog-select :name="$column" :label="$label" :value="in_array($productTaxType, ['A', 'E'], true) ? $productTaxType : ''" :options="$choiceOptions" x-model="taxType" x-on:change="changeTaxType()" />
                                @elseif (isset($field['suggestions']))
                                    <x-catalog-select :name="$column" :label="$label" :value="$value" :options="$lookup" :required="$required" :show-code="true" placeholder="Seleccionar cuenta contable…" />
                                @elseif (($field['lookup'] ?? '') === 'departments')
                                    <x-catalog-select :name="$column" :label="$label" :value="$value" :options="$lookup" :required="$required" :show-code="true" x-model="department" x-on:change="changeDepartment()" placeholder="Seleccionar departamento…" />
                                @elseif (($field['lookup'] ?? '') === 'districts')
                                    <x-catalog-select :name="$column" :label="$label" :value="$value" :options="$lookup" :required="$required" x-model="district" x-on:change="changeDistrict()" x-effect="setOptions(availableDistricts, !department)" placeholder="Seleccionar distrito…" />
                                @elseif (($field['lookup'] ?? '') === 'municipalities')
                                    <flux:input :label="$label" x-bind:value="municipalityLabel" readonly placeholder="Se asigna al seleccionar el distrito" />
                                    <input type="hidden" name="{{ $column }}" x-model="municipality" value="{{ $value }}">
                                @elseif ($lookup !== null)
                                    <x-catalog-select :name="$column" :label="$label" :value="$value" :options="$lookup" :required="$required" :show-code="in_array($field['lookup'] ?? '', ['economicActivities', 'supplierAccounts'], true)" />
                                @elseif ($field['type'] === 'select' || $field['type'] === 'boolean')
                                    <x-catalog-select :name="$column" :label="$label" :value="$value" :options="$choiceOptions" :required="$required" />
                                @elseif ($field['type'] === 'integer' || $field['type'] === 'decimal')
                                    <flux:input :name="$column" :label="$label" :value="$value" type="number" min="0" :step="$field['type'] === 'integer' ? '1' : '0.000001'" :required="$required" />
                                @elseif (in_array($column, ['descrip', 'descripcion', 'direccion'], true))
                                    <flux:textarea :name="$column" :label="$label" rows="2" resize="vertical" :maxlength="$field['max'] ?? 255" :required="$required">{{ $value }}</flux:textarea>
                                @else
                                    <flux:input :name="$column" :label="$label" :value="$value" :type="$field['type'] === 'email' ? 'email' : 'text'" :maxlength="$field['max'] ?? 255" :required="$required" :list="isset($field['suggestions']) ? 'catalog-accounts' : null" />
                                @endif
                                @error($column)<p class="mt-1 text-xs text-red-700" role="alert">{{ $message }}</p>@enderror
                            </div>
                        @endforeach
                    </div>
                </fieldset>
            @endforeach
            @if ($catalog->slug === 'productos')
                <x-catalog-notice name="product-price-help" title="Precios e IVA">
                    <div class="space-y-3">
                        <p>Puedes escribir el precio sin IVA o el precio con IVA. El otro importe se calcula automáticamente.</p>
                        <p><strong>Solo se guarda el precio sin IVA.</strong></p>
                        <p x-text="taxType === 'A' ? 'Afecta: se aplica el 13% de IVA.' : (taxType === 'E' ? 'Exenta: ambos precios son iguales.' : 'Selecciona Afecta o Exenta en la clasificación de IVA para calcular el precio.')"></p>
                    </div>
                </x-catalog-notice>
            @endif
            </div>
        </form>
        </div>
        <x-catalog-notice name="catalog-form-help" title="Información del formulario">
            <div class="space-y-3">
                <p>Los campos marcados con <strong>*</strong> son obligatorios.</p>
                <p>En los selectores puedes buscar por nombre o código y elegir una opción de la lista.</p>
                <p>Presiona <strong>{{ $record ? 'Guardar cambios' : 'Crear registro' }}</strong> para guardar. Si hay un problema, se abrirá una ventana con los datos que debes revisar.</p>
            </div>
        </x-catalog-notice>
    </div>
</x-layouts::app>
