<x-layouts::app :title="'Venta · Librería'">
    <div x-data="libreriaSale(@js($saleCatalogs))">
        <div class="sales-workspace">
        <header class="flex shrink-0 flex-wrap items-center justify-between gap-3">
            <div>
                <div class="mb-1.5 flex items-center gap-2 text-xs text-zinc-500 dark:text-zinc-400">
                    <span>Librería</span><span class="text-zinc-400 dark:text-zinc-600">/</span><span class="text-brand-ink dark:text-brand-paper">Venta</span>
                </div>
                <div class="flex items-center gap-3">
                    <h1 class="text-2xl font-semibold tracking-tight">Nueva venta</h1>
                    <span class="rounded-md border border-brand-yellow/25 bg-brand-yellow/10 px-2 py-1 text-[10px] font-semibold tracking-wide text-brand-ink dark:text-brand-yellow">{{ ($saleCatalogs['dteEnvironment'] ?? '00') === '00' ? 'PRUEBAS · AMBIENTE 00' : 'DTE · AMBIENTE 01' }}</span>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <flux:button size="sm" variant="ghost" icon="clock" x-on:click="openHistory()">Historial</flux:button>
                <flux:button size="sm" icon="plus" x-on:click="$flux.modal('sale-reset').show()" x-bind:disabled="generating || quoteLoading">Nueva venta</flux:button>
            </div>
        </header>

        <fieldset class="contents" x-bind:disabled="generating || quoteLoading || !!generated">
        <section data-theme="light" class="sale-panel grid shrink-0 grid-cols-2 items-center gap-4 p-4 md:grid-cols-[1fr_1fr_1fr_auto]" aria-label="Datos de factura">
            <flux:select label="Documento" size="sm" x-model="document.type" x-on:change="requireFiscalClientChange()" aria-label="Tipo de documento">
                @foreach ($saleCatalogs['documents'] as $option)
                    <option value="{{ $option['codigo'] }}">{{ $option['valor'] }}</option>
                @endforeach
            </flux:select>
            <flux:input label="Fecha" size="sm" type="date" x-model="document.date" />
            <div class="flex flex-col gap-1.5">
                <span class="sale-label">Número de control</span>
                <span class="text-xs text-zinc-500 dark:text-zinc-400" x-text="generated?.number || 'Se asigna al generar'"></span>
            </div>
            <flux:button size="sm" variant="ghost" icon="adjustments-horizontal" x-on:click="$flux.modal('sale-invoice').show()">Más datos</flux:button>
            <button type="button" class="col-span-2 flex items-center justify-between gap-3 border-t border-zinc-200 pt-3 text-xs md:col-span-4 xl:hidden dark:border-brand-paper/10" x-on:click="editClient()"><span class="flex min-w-0 items-center gap-2"><flux:icon.user class="size-4 shrink-0 text-zinc-400" /><span class="truncate" x-text="client.name"></span></span><span class="shrink-0 font-medium underline underline-offset-4">Editar cliente</span></button>
        </section>

        <div class="grid min-h-0 flex-1 gap-4 xl:grid-cols-[minmax(0,1fr)_18rem]">
            <section data-theme="light" class="sale-panel flex min-h-96 min-w-0 flex-col overflow-hidden lg:min-h-0" aria-labelledby="sale-products-title">
                <div class="sale-panel-heading flex shrink-0 flex-wrap items-center justify-between gap-3 border-b p-4">
                    <div class="flex items-center gap-2.5">
                        <h2 id="sale-products-title" class="text-sm font-semibold">Detalle de la venta</h2>
                        <span data-sale-line-count class="rounded-md px-2 py-0.5 text-[11px] tabular-nums" x-text="`${items.length} líneas`"></span>
                    </div>
                    <div class="flex gap-1">
                        <flux:button size="xs" variant="ghost" x-on:click="requestSource = 'Registro'; $flux.modal('sale-requests').show()">Solicitudes Registro</flux:button>
                        <flux:button size="xs" variant="ghost" x-on:click="requestSource = 'CFP'; $flux.modal('sale-requests').show()">Solicitudes CFP</flux:button>
                    </div>
                </div>

                <div class="flex shrink-0 flex-col gap-2 border-b border-zinc-200 p-3 dark:border-brand-paper/10">
                    <form class="flex gap-2" x-on:submit.prevent="quickAdd()">
                        <flux:input size="sm" icon="magnifying-glass" x-model="quickSearch" maxlength="100" placeholder="Busca por código o nombre del producto…" aria-label="Código o nombre del producto" class="flex-1" />
                        <flux:button type="submit" size="sm" variant="primary" icon="plus" x-bind:disabled="quickProductLoading"><span x-text="quickProductLoading ? 'Buscando…' : 'Agregar'"></span></flux:button>
                    </form>
                    <div class="flex flex-wrap items-center gap-2">
                        <flux:button size="xs" icon="shopping-bag" x-on:click="openCatalog('productos')">Productos / Servicios</flux:button>
                        <flux:button size="xs" icon="academic-cap" x-on:click="openTuition()">Matrícula / Colegiatura</flux:button>
                        <flux:button size="xs" icon="squares-2x2" x-on:click="openCatalog('paquetes')">Paquetes</flux:button>
                    </div>
                </div>

                <div x-ref="productScroll" class="min-h-0 flex-1 overflow-auto">
                    <table class="sale-workspace-table w-full text-left text-sm" x-show="items.length">
                        <thead class="sticky top-0 z-10 bg-zinc-100 text-zinc-500 dark:bg-[#292925] dark:text-zinc-400">
                            <tr><th>Producto</th><th class="text-center!">Cant.</th><th class="text-right!">Precio</th><th class="text-center!">Desc. %</th><th class="text-right!">Descuento $</th><th class="text-right!">Importe</th><th><span class="sr-only">Quitar</span></th></tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200 dark:divide-brand-paper/5">
                            <template x-for="item in items" :key="item.id">
                                <tr class="group hover:bg-brand-yellow/[0.025]">
                                    <td class="min-w-36">
                                        <div class="text-[13px] font-medium" x-text="item.name"></div>
                                        <div x-show="item.tuition" class="mt-1 max-w-60 truncate text-[10px] text-zinc-500" x-text="item.tuition ? `${item.tuition.studentName} · ${item.tuition.studentCode}` : ''" :title="item.tuition ? `${item.tuition.studentName} · ${item.tuition.studentCode} · ${item.tuition.responsibleLabel}` : ''"></div>
                                        <div class="mt-1 flex items-center gap-2 text-[10px] text-zinc-500 dark:text-zinc-400"><span class="font-mono" x-text="item.code"></span><span class="size-0.5 rounded-full bg-current"></span><span x-text="item.taxable ? 'Gravado · IVA incluido' : 'Exento'"></span></div>
                                    </td>
                                    <td class="text-center"><input class="sale-number" type="number" min="0.01" step="0.01" x-model.number="item.quantity" x-bind:readonly="!!item.tuition" x-bind:max="item.productType === 'B' ? Number(item.stock?.total || 0) : null" :aria-label="`Cantidad de ${item.name}`" x-on:change="changeProductQuantity(item)" /></td>
                                    <td class="text-right text-[13px] tabular-nums text-zinc-600 dark:text-zinc-300">
                                        <template x-if="!canEditPrice(item)"><span x-text="money(item.price)"></span></template>
                                        <template x-if="canEditPrice(item)">
                                            <div class="flex flex-col items-end gap-1">
                                                <label class="text-[10px] text-zinc-500" :for="`sale-price-${item.id}`" x-text="item.taxable ? 'Sin IVA ($)' : 'Precio ($)'"></label>
                                                <input class="sale-number w-28! text-right!" type="text" inputmode="decimal" maxlength="13" :id="`sale-price-${item.id}`" :value="item.netPrice" :aria-label="`Precio sin IVA de ${item.name}`" :aria-invalid="!!item.priceError" x-on:input="changeProductPrice(item, $event.target.value, false)" x-on:change="changeProductPrice(item, $event.target.value)" />
                                                <span x-show="item.taxable" class="text-[10px] text-zinc-500" x-text="`Con IVA ${money(item.price)}`"></span>
                                            </div>
                                        </template>
                                    </td>
                                    <td class="text-center"><input class="sale-number w-14!" type="number" min="0" max="100" step="0.01" x-model.number="item.discount" x-bind:readonly="!canDiscount(item)" :aria-label="`Descuento de ${item.name}`" x-on:change="item.discount = canDiscount(item) ? Math.min(100, Math.max(0, Number(item.discount) || 0)) : 0" /></td>
                                    <td class="whitespace-nowrap text-right text-[13px] font-medium tabular-nums" :class="lineDiscount(item) + lineGeneralDiscount(item) > 0 ? 'text-emerald-700 dark:text-emerald-400' : 'text-zinc-400'" x-text="lineDiscount(item) + lineGeneralDiscount(item) > 0 ? '− ' + money(lineDiscount(item) + lineGeneralDiscount(item)) : '—'" title="Descuento del producto más su descuento general"></td>
                                    <td class="text-right text-[13px] font-semibold tabular-nums" x-text="money(lineTotal(item))"></td>
                                    <td class="w-10"><flux:button size="xs" variant="ghost" icon="x-mark" x-on:click="removeProduct(item.id)" x-bind:aria-label="`Quitar ${item.name}`" /></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                    <div x-show="!items.length" x-cloak class="flex h-full min-h-56 flex-col items-center justify-center gap-3 px-6 py-10 text-center">
                        <div class="flex size-14 items-center justify-center rounded-2xl border border-brand-yellow/20 bg-brand-yellow/10 text-brand-ink dark:text-brand-yellow"><flux:icon.shopping-bag class="size-6" /></div>
                        <div><p class="text-sm font-semibold">Tu venta empieza aquí</p><p class="mt-1 text-xs leading-5 text-zinc-500 dark:text-zinc-400">Busca un producto o selecciona una colegiatura<br class="hidden sm:block" /> para agregarla al detalle.</p></div>
                        <flux:button size="sm" variant="ghost" x-on:click="openCatalog()">Explorar productos <flux:icon.arrow-right class="ms-1 size-3.5" /></flux:button>
                    </div>
                </div>

                <div class="flex shrink-0 items-start gap-3 border-t border-brand-yellow/30 bg-brand-yellow/10 px-4 py-4">
                    <div class="mt-1 shrink-0 rounded-lg bg-brand-yellow/25 p-2 text-brand-ink"><flux:icon.chat-bubble-bottom-center-text class="size-5" /></div>
                    <div class="min-w-0 flex-1"><flux:textarea label="Observación de la venta (opcional)" x-model="observation" maxlength="500" rows="2" resize="none" class="min-h-20 bg-white! text-base! leading-relaxed! text-brand-ink! placeholder:text-zinc-500!" placeholder="Escribe aquí una indicación o detalle importante de esta venta…" /></div>
                </div>

                <footer class="sale-totals flex shrink-0 flex-wrap items-center justify-between gap-4 border-t px-5 py-4">
                    <div class="flex items-center gap-5 text-xs">
                        <div><p class="sale-label">Subtotal</p><p class="mt-1.5 tabular-nums" x-text="money(subtotal)"></p></div>
                        <div><p class="sale-label">Descuento</p><p data-sale-discount class="mt-1.5 tabular-nums" x-text="`− ${money(discount)}`"></p></div>
                    </div>
                    <div class="flex items-center gap-5">
                        <div class="text-right"><p class="sale-label">Total a pagar</p><p class="mt-0.5 text-2xl font-semibold tracking-tight tabular-nums" x-text="money(total)"></p></div>
                        <flux:button variant="primary" x-on:click="openPayment()" x-bind:disabled="stockChecking || packageAdding || !items.length || !hasBillingClient" class="shrink-0"><span x-text="stockChecking ? 'Comprobando existencias…' : 'Confirmar productos'"></span><flux:icon.arrow-right class="ms-1 size-4" /></flux:button>
                    </div>
                </footer>
            </section>

            <aside class="hidden min-h-0 flex-col gap-4 xl:flex" aria-label="Resumen de la venta">
                <section data-theme="light" class="sale-panel overflow-hidden">
                    <div class="sale-panel-heading flex items-center justify-between border-b px-4 py-3"><h2 class="text-sm font-semibold">Cliente</h2><flux:button size="xs" variant="ghost" x-on:click="editClient()"><span x-text="hasBillingClient ? 'Cambiar / Editar' : 'Seleccionar'"></span></flux:button></div>
                    <div class="flex flex-col gap-5 p-4">
                        <div class="flex items-center gap-3"><div class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-brand-yellow/10 text-brand-ink dark:text-brand-yellow"><flux:icon.user class="size-5" /></div><div class="min-w-0"><p class="text-sm font-semibold wrap-break-word" x-text="client.name"></p><p class="mt-1 text-[11px] text-zinc-500 dark:text-zinc-400" x-text="hasBillingClient ? (client.document || 'Sin documento seleccionado') : 'Selecciona un cliente para continuar'"></p></div></div>
                        <dl class="grid gap-3 text-xs">
                            <div class="flex justify-between gap-3"><dt class="text-zinc-500 dark:text-zinc-400">Alumno(s)</dt><dd class="max-w-40 truncate text-right" x-text="tuitionStudentsLabel || client.student || 'Sin asignar'" :title="tuitionStudentsInSale.map(student => `${student.name} · ${student.code}`).join('\n')"></dd></div>
                            <div class="flex justify-between gap-3"><dt class="text-zinc-500 dark:text-zinc-400">NRC</dt><dd x-text="client.nrc || '—'"></dd></div>
                            <div class="flex justify-between gap-3"><dt class="text-zinc-500 dark:text-zinc-400">Correo</dt><dd class="max-w-40 truncate" x-text="client.email || 'Sin agregar'"></dd></div>
                        </dl>
                    </div>
                </section>
                <section data-theme="light" class="sale-panel flex min-h-0 flex-col overflow-auto">
                    <div class="sale-panel-heading border-b px-4 py-3"><h2 class="text-sm font-semibold">Resumen</h2></div>
                    <dl class="grid gap-3 p-4 text-xs">
                        <div class="flex justify-between gap-3"><dt class="text-zinc-500 dark:text-zinc-400">Punto de venta</dt><dd class="font-mono font-semibold">{{ auth()->user()->puntoventa ?? 'Sin asignar' }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-zinc-500 dark:text-zinc-400">Documento</dt><dd class="text-right" x-text="documentLabel"></dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-zinc-500 dark:text-zinc-400">Unidades</dt><dd class="tabular-nums" x-text="units"></dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-zinc-500 dark:text-zinc-400">Código de generación</dt><dd class="text-zinc-500 dark:text-zinc-400">Al emitir</dd></div>
                    </dl>
                </section>
            </aside>
        </div>

        <div class="flex shrink-0 items-center justify-between gap-3 text-[10px] text-zinc-500 dark:text-zinc-400">
            <span>Venta e inventario vinculados al DTE generado</span>
            <span class="hidden sm:block">Importes confirmados por el servidor</span>
        </div>
        </fieldset>

        </div>

        @include('libreria.partials.venta-modales')
    </div>
</x-layouts::app>
