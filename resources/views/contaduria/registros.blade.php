<x-layouts::app :title="$catalog->title.' · Contaduría'">
    <div class="catalog-workspace flex w-full flex-col gap-6" x-data="{ deleteName: '', deleteUrl: '' }">
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div class="flex items-center gap-4">
                <span class="catalog-icon border-brand-yellow/30 bg-brand-yellow text-brand-ink"><flux:icon :name="$catalog->icon" class="size-6" /></span>
                <div>
                <p class="mb-2 text-xs text-zinc-500 dark:text-zinc-400"><a href="{{ route('contaduria.catalogos') }}" wire:navigate class="underline underline-offset-4">Contaduría / Catálogos</a></p>
                <h1 class="text-2xl font-semibold tracking-tight">{{ $catalog->title }}</h1>
                <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">{{ $catalog->description }}</p>
                </div>
            </div>
            @can('contaduria.'.$catalog->slug.'.crear')<flux:button :href="route('contaduria.catalogos.registros.create', $catalog->slug)" wire:navigate variant="primary" icon="plus">Nuevo registro</flux:button>@endcan
        </header>
        @if ($errors->any())
            <x-catalog-notice name="catalog-feedback" title="No se pudo completar la acción" kind="error" :automatic="true">
                <ul class="list-disc space-y-2 ps-4">@foreach ($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul>
            </x-catalog-notice>
        @elseif (session('status'))
            <x-catalog-notice name="catalog-feedback" title="Operación completada" kind="success" :automatic="true">{{ session('status') }}</x-catalog-notice>
        @endif
        <section data-theme="light" class="catalog-panel overflow-hidden" aria-label="Registros del catálogo">
            <form method="GET" action="{{ route('contaduria.catalogos.registros.index', $catalog->slug) }}" class="flex flex-wrap items-end gap-3 border-b border-brand-ink/10 p-5">
                <div class="min-w-48 flex-1 sm:max-w-lg"><flux:input name="q" :value="$search" label="Buscar registros" icon="magnifying-glass" placeholder="Nombre, código o documento…" maxlength="100" /></div>
                <flux:button type="submit" icon="magnifying-glass">Buscar</flux:button>
                @if ($search !== '')<flux:button :href="route('contaduria.catalogos.registros.index', $catalog->slug)" wire:navigate variant="ghost">Limpiar</flux:button>@endif
                <span class="catalog-pill ms-auto mb-1">{{ number_format($records->total()) }} registros</span>
            </form>
            <div class="overflow-x-auto">
                <table class="catalog-table w-full text-left text-sm">
                    <thead class="bg-brand-ink text-brand-paper"><tr>
                        @foreach ($catalog->columns as $label)<th class="whitespace-nowrap px-4 py-3 text-xs font-medium">{{ $label }}</th>@endforeach
                        <th class="w-56 px-4 py-3 text-right text-xs font-medium">Acciones</th>
                    </tr></thead>
                    <tbody class="divide-y divide-brand-ink/10">
                        @forelse ($records as $record)
                            <tr>
                                @foreach ($catalog->columns as $column => $label)
                                    @php
                                        $value = $record->getAttribute($column);
                                        if ($value instanceof \BackedEnum) { $value = $value->value; }
                                    @endphp
                                    <td class="max-w-96 leading-6">
                                        @if (in_array($column, ['cuenta', 'codigo'], true) && $value !== null && $value !== '')
                                            <span class="catalog-code">{{ $value }}</span>
                                        @elseif (is_bool($value))
                                            <span class="catalog-pill">{{ $value ? 'Sí' : 'No' }}</span>
                                        @elseif (in_array($column, ['terminal', 'tipo_saldo', 'tipo_prod'], true) && $value !== null && $value !== '')
                                            <span class="catalog-pill">{{ $catalog->fields[$column]['choices'][$value] ?? $value }}</span>
                                        @else
                                            <span @class(['font-medium' => $loop->first])>{{ $value === null || $value === '' ? '—' : $value }}</span>
                                        @endif
                                    </td>
                                @endforeach
                                <td><div class="flex justify-end gap-2">
                                    @can('contaduria.'.$catalog->slug.'.editar')<flux:button class="catalog-action catalog-action--edit" icon="pencil-square" icon:variant="outline" :aria-label="'Editar '.($record->getAttribute($catalog->labelColumn) ?? 'registro')" :href="route('contaduria.catalogos.registros.edit', [$catalog->slug, $record->getKey()])" wire:navigate>Editar</flux:button>@endcan
                                    @can('contaduria.'.$catalog->slug.'.eliminar')<flux:button class="catalog-action catalog-action--delete" icon="trash" icon:variant="outline" :aria-label="'Eliminar '.($record->getAttribute($catalog->labelColumn) ?? 'registro')" x-on:click="deleteName = @js($record->getAttribute($catalog->labelColumn) ?? 'Registro'); deleteUrl = @js(route('contaduria.catalogos.registros.destroy', [$catalog->slug, $record->getKey()])); $flux.modal('catalog-delete').show()">Eliminar</flux:button>@endcan
                                </div></td>
                            </tr>
                        @empty
                            <tr><td colspan="{{ count($catalog->columns) + 1 }}"><div class="flex flex-col items-center gap-3 py-12 text-center text-sm text-zinc-500"><span class="catalog-icon"><flux:icon.magnifying-glass class="size-5" /></span>{{ $search !== '' ? 'No se encontraron registros con esa búsqueda.' : 'Este catálogo aún no tiene registros.' }}</div></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="border-t border-brand-ink/10 p-5">{{ $records->links() }}</div>
        </section>
        <flux:modal name="catalog-delete" data-theme="light" class="w-full max-w-md rounded-2xl">
            <form method="POST" x-bind:action="deleteUrl" class="space-y-6">
                @csrf
                @method('DELETE')
                <div class="flex items-start gap-4"><span class="flex size-12 shrink-0 items-center justify-center rounded-2xl border border-red-200 bg-red-50 text-red-700"><flux:icon.trash class="size-6" /></span><div><flux:heading size="lg">Eliminar registro</flux:heading><p class="mt-1 text-xs text-zinc-500">{{ $catalog->title }}</p></div></div>
                <div class="rounded-xl border border-zinc-200 bg-zinc-50 p-4"><p class="text-xs text-zinc-500">Registro seleccionado</p><p class="mt-2 break-words text-sm font-semibold" x-text="deleteName"></p></div>
                <p class="text-sm leading-6 text-zinc-500">Confirma que deseas eliminar este registro. La eliminación se bloqueará si existen referencias que la impidan.</p>
                <div class="flex justify-end gap-2 border-t border-zinc-200 pt-4"><flux:modal.close><flux:button>Cancelar</flux:button></flux:modal.close><flux:button type="submit" variant="danger" icon="trash" x-bind:disabled="!deleteUrl">Eliminar registro</flux:button></div>
            </form>
        </flux:modal>
    </div>
</x-layouts::app>
