@props(['name', 'label', 'options' => [], 'value' => '', 'required' => false, 'disabled' => false, 'showCode' => false, 'placeholder' => 'Seleccionar…'])

@php
    $id = 'catalog-select-'.$name;
    $value = (string) $value;
    $options = array_map(fn ($option) => ['value' => (string) $option['value'], 'label' => (string) $option['label']], $options);
    if ($value !== '' && !in_array($value, array_column($options, 'value'), true)) {
        array_unshift($options, ['value' => $value, 'label' => $value.' (valor actual)']);
    }
    $selectedLabel = collect($options)->firstWhere('value', $value)['label'] ?? $value;
    if (!$required) { array_unshift($options, ['value' => '', 'label' => 'Sin especificar']); }
@endphp

<div {{ $attributes->class(['catalog-select']) }} x-data="catalogSelect(@js($options), @js($value), @js($disabled))" x-modelable="value"
    x-on:click.outside="if (!$refs.panel?.contains($event.target)) closeMenu()"
    x-on:catalog-select-open.window="if ($event.detail !== $refs.trigger.id) closeMenu()"
    x-on:resize.window="closeMenu()" x-on:scroll.window="closeMenu()">
    <label for="{{ $id }}" data-flux-label>{{ $label }}</label>
    <select name="{{ $name }}" class="catalog-select__native" tabindex="-1" aria-hidden="true" x-model="value" :disabled="disabled" @required($required) x-on:invalid.prevent="if ($el.form.querySelector('input:invalid, select:invalid, textarea:invalid') === $el) openMenu()">
        <option value="" @selected($value === '')>{{ $placeholder }}</option>
        @foreach ($options as $option)
            @if ($option['value'] !== '')<option value="{{ $option['value'] }}" @selected($value === $option['value'])>{{ $option['label'] }}</option>@endif
        @endforeach
    </select>
    <button id="{{ $id }}" type="button" class="catalog-select__trigger" data-flux-control x-ref="trigger"
        role="combobox" aria-haspopup="listbox" aria-controls="{{ $id }}-options" :aria-expanded="open" :disabled="disabled"
        @if ($required) aria-required="true" @endif @if ($errors->has($name)) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif
        x-effect="if (disabled) open = false"
        x-on:click="open ? closeMenu() : openMenu()" x-on:keydown.arrow-down.prevent="move(1)" x-on:keydown.arrow-up.prevent="move(-1)" x-on:keydown.escape.stop.prevent="closeMenu()">
        <span class="catalog-select__selection" :class="!value && 'catalog-select__placeholder'">
            @if ($showCode)<span class="catalog-select__code" x-show="value" x-text="value"></span>@endif
            <span class="catalog-select__text" x-text="value ? selectedLabel : @js($placeholder)">{{ $selectedLabel ?: $placeholder }}</span>
        </span>
        <span class="catalog-select__chevron" :class="open && 'catalog-select__chevron--open'"><flux:icon.chevron-down class="size-4" /></span>
    </button>
    @if ($errors->has($name))<span id="{{ $id }}-error" class="sr-only">{{ $errors->first($name) }}</span>@endif

    <template x-teleport="body">
        <div x-cloak x-show="open" x-ref="panel" class="catalog-select__panel" data-theme="light" :style="panelStyle" x-on:click.stop
            x-on:keydown.arrow-down.prevent="move(1)" x-on:keydown.arrow-up.prevent="move(-1)" x-on:keydown.enter.prevent="chooseActive()"
            x-on:keydown.escape.stop.prevent="closeMenu(true)" x-on:keydown.tab="closeMenu(true)">
            <div class="catalog-select__search">
                <flux:icon.magnifying-glass class="size-4 shrink-0" />
                <input type="search" x-ref="search" x-model="query" x-on:input="active = 0" placeholder="Buscar por nombre o código…"
                    aria-label="Buscar en {{ $label }}" role="combobox" aria-autocomplete="list" aria-expanded="true" aria-controls="{{ $id }}-options"
                    :aria-activedescendant="visibleOptions.length ? '{{ $id }}-option-' + active : null" autocomplete="off">
            </div>
            <div id="{{ $id }}-options" class="catalog-select__options" role="listbox" aria-label="{{ $label }}" x-ref="list">
                <template x-for="(option, index) in visibleOptions" :key="option.value">
                    <div class="catalog-select__option" role="option" :id="'{{ $id }}-option-' + index" :data-option-index="index"
                        :aria-selected="value === option.value" :class="{ 'is-active': active === index, 'is-selected': value === option.value }"
                        x-on:mouseenter="active = index" x-on:mousedown.prevent x-on:click="choose(option.value)">
                        <span class="catalog-select__option-content">
                            @if ($showCode)<span class="catalog-select__code" x-show="option.value" x-text="option.value"></span>@endif
                            <span x-text="option.label"></span>
                        </span>
                        <flux:icon.check class="size-4 shrink-0" x-show="value === option.value" />
                    </div>
                </template>
                <p class="catalog-select__empty" x-show="!visibleOptions.length">No se encontraron opciones.</p>
            </div>
            <p class="catalog-select__footer" aria-live="polite" x-text="matches.length > 60 ? 'Mostrando 60 de ' + matches.length + '. Escribe para afinar la búsqueda.' : matches.length + (matches.length === 1 ? ' opción disponible' : ' opciones disponibles')"></p>
        </div>
    </template>
</div>
