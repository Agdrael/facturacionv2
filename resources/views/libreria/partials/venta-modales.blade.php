<flux:modal name="sale-catalog" data-theme="light" class="sale-dialog" aria-labelledby="sale-catalog-title">
    <div class="flex flex-col gap-5">
        <div class="sale-modal-heading"><p class="sale-label">Agregar al detalle</p><h2 id="sale-catalog-title" class="mt-1 text-xl font-semibold tracking-tight" x-text="catalogCategory === 'paquetes' ? 'Paquetes' : 'Productos y servicios'"></h2><p class="mt-1 text-xs" x-text="catalogCategory === 'paquetes' ? 'Cada paquete agrega sus productos por separado, con las cantidades configuradas.' : 'Busca por código o descripción. Los precios afectos incluyen IVA.'"></p></div>
        <div class="flex flex-wrap gap-1 rounded-xl bg-zinc-100 p-1 dark:bg-brand-ink/70" role="group" aria-label="Origen de los productos">
            <template x-for="category in [{ id: 'productos', label: 'Productos / Servicios' }, { id: 'paquetes', label: 'Paquetes' }]" :key="category.id">
                <button type="button" class="flex-1 whitespace-nowrap rounded-lg px-3 py-2 text-xs font-medium transition-colors" :class="catalogCategory === category.id ? 'bg-brand-yellow text-brand-ink' : 'text-zinc-500 hover:text-brand-ink dark:text-zinc-400 dark:hover:text-brand-paper'" :aria-pressed="catalogCategory === category.id" x-on:click="catalogCategory = category.id; search = ''; searchCatalog()" x-text="category.label"></button>
            </template>
        </div>
        <flux:input icon="magnifying-glass" x-model="search" x-on:input.debounce.300ms="searchCatalog()" maxlength="100" placeholder="Buscar por nombre o código…" aria-label="Buscar en el catálogo" />
        <div class="max-h-[42dvh] min-h-48 overflow-y-auto rounded-xl border border-zinc-200 dark:border-brand-paper/10" x-bind:aria-busy="catalogLoading">
            <p x-show="catalogLoading" role="status" class="px-4 py-12 text-center text-sm text-zinc-500">Cargando catálogo…</p>
            <div x-show="catalogError" x-cloak role="alert" class="p-4 text-sm text-red-700"><p x-text="catalogError"></p><flux:button size="xs" class="mt-3" x-on:click="searchCatalog(catalogPage)">Reintentar</flux:button></div>
            <template x-for="product in filteredCatalog" :key="product.id">
                <div class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-3 border-b border-zinc-200 p-3 last:border-0 dark:border-brand-paper/10">
                    <div class="flex min-w-0 items-center gap-3">
                    <div class="hidden size-9 shrink-0 items-center justify-center rounded-lg bg-zinc-100 text-zinc-400 sm:flex dark:bg-brand-paper/5"><flux:icon.shopping-bag class="size-4" /></div>
                    <div class="min-w-0 flex-1"><p class="text-sm font-medium" x-text="product.name"></p><p class="mt-1 text-[11px] text-zinc-500 dark:text-zinc-400"><span class="font-mono" x-text="product.code"></span><span class="mx-2">·</span><span x-show="product.category === 'paquetes'" x-text="`${product.componentsCount || 0} productos`"></span><span x-show="product.category !== 'paquetes'"><span x-text="product.productType === 'S' ? 'Servicio' : 'Bien'"></span><span class="mx-2">·</span><span x-text="product.taxable ? 'Gravado' : 'Exento'"></span></span></p><p x-show="product.stock && Number(product.stock.total || 0) <= 0" x-cloak class="mt-2 text-xs font-medium text-red-700 dark:text-red-400">No hay existencia</p><button type="button" x-show="product.configurationError" class="mt-2 text-xs font-medium text-amber-700 underline underline-offset-4" x-on:click="catalogConfigurationError = product.configurationError; $flux.modal('sale-product-configuration').show()">Revisar configuración</button></div>
                    </div>
                    <div class="flex shrink-0 flex-col items-end gap-2 sm:flex-row sm:items-center">
                    <div x-show="product.category !== 'paquetes'" class="flex items-center gap-2">
                        <span x-show="catalogQuantity(product) > 0" x-cloak class="inline-flex min-w-7 items-center justify-center rounded-md bg-brand-yellow px-1.5 py-1 text-xs font-bold tabular-nums text-brand-ink" x-text="catalogQuantity(product)" x-bind:aria-label="`${catalogQuantity(product)} de ${product.name} en la venta`" title="Cantidad agregada a la venta"></span>
                        <span class="text-sm font-semibold tabular-nums" x-text="product.netPrice === null ? 'Sin precio' : money(product.price)"></span>
                    </div>
                    <flux:button size="sm" icon="plus" x-on:click="product.category === 'paquetes' ? addPackage(product) : addProduct(product)" x-bind:disabled="packageAdding || !product.canSelect" x-bind:aria-label="`Agregar ${product.name}`"><span x-text="product.category === 'paquetes' ? (packageAdding ? 'Agregando…' : 'Agregar productos') : 'Agregar'"></span></flux:button>
                    </div>
                </div>
            </template>
            <div x-show="!catalogLoading && !catalogError && !filteredCatalog.length" class="px-4 py-12 text-center text-sm text-zinc-500 dark:text-zinc-400" x-text="catalogCategory === 'paquetes' && !search ? 'No hay paquetes registrados.' : 'No hay coincidencias. Prueba con otro nombre o código.'"></div>
        </div>
        <div x-show="!catalogError" class="flex items-center justify-between gap-3 text-xs text-zinc-500"><span x-text="`${catalogTotal} resultados · Página ${catalogPage} de ${catalogPages}`"></span><div class="flex gap-2"><flux:button size="xs" x-bind:disabled="catalogLoading || catalogPage <= 1" x-on:click="searchCatalog(catalogPage - 1)">Anterior</flux:button><flux:button size="xs" x-bind:disabled="catalogLoading || catalogPage >= catalogPages" x-on:click="searchCatalog(catalogPage + 1)">Siguiente</flux:button></div></div>
        <div class="sticky bottom-0 z-10 flex flex-wrap items-center justify-between gap-3 border-t border-zinc-200 bg-white py-4 dark:border-brand-paper/10">
            <div class="min-w-0 flex-1 basis-44"><div class="flex items-center gap-3 text-xs"><span class="font-medium text-zinc-500">Productos incluidos</span><strong class="font-semibold tabular-nums text-brand-ink" x-text="money(total)"></strong></div><div class="mt-2 flex max-h-16 flex-wrap gap-1.5 overflow-y-auto" aria-live="polite"><template x-for="product in selectedCatalogProducts" :key="product.id"><span class="rounded-md border border-zinc-200 bg-zinc-100 px-2 py-1 text-[11px] text-brand-ink" x-bind:title="product.name" x-text="product.label"></span></template><span x-show="!selectedCatalogProducts.length" class="text-[11px] text-zinc-500">Aún no has agregado productos.</span></div></div>
            <flux:modal.close><flux:button variant="primary">Volver a la venta <flux:icon.arrow-right class="ms-1 size-4" /></flux:button></flux:modal.close>
        </div>
    </div>
</flux:modal>

<flux:modal name="sale-product-configuration" data-theme="light" class="sale-dialog md:max-w-lg!" aria-labelledby="sale-product-configuration-title">
    <div class="flex flex-col gap-4"><h2 id="sale-product-configuration-title" class="text-lg font-semibold">Revisa el producto antes de agregarlo</h2><p class="whitespace-pre-line text-sm" x-text="catalogConfigurationError"></p><flux:modal.close><flux:button variant="primary">Entendido</flux:button></flux:modal.close></div>
</flux:modal>

@include('libreria.partials.venta-colegiaturas')

@include('libreria.partials.venta-clientes')

<flux:modal name="sale-credit-client" data-theme="light" class="sale-dialog md:max-w-lg!" aria-labelledby="sale-credit-client-title">
    <div class="flex flex-col gap-4">
        <h2 id="sale-credit-client-title" class="text-lg font-semibold">Selecciona el cliente del crédito fiscal</h2>
        <p class="text-sm">Para emitir un Comprobante de crédito fiscal debes cambiar Clientes varios por el cliente que recibirá el documento.</p>
        <div class="flex justify-end gap-2">
            <flux:modal.close><flux:button>Volver</flux:button></flux:modal.close>
            <flux:button variant="primary" x-on:click="chooseFiscalClient()">Seleccionar cliente</flux:button>
        </div>
    </div>
</flux:modal>

<flux:modal name="sale-client" data-theme="light" class="sale-dialog" aria-labelledby="sale-client-title">
    <form class="flex flex-col gap-5" x-on:submit.prevent="saveClient()">
        <div class="sale-modal-heading"><p class="sale-label">Datos del receptor</p><h2 id="sale-client-title" class="mt-1 text-xl font-semibold tracking-tight">Información del cliente</h2><p class="mt-1 text-xs">La información se conserva mientras preparas esta venta.</p></div>
        <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-zinc-200 bg-zinc-50 p-3">
            <div class="min-w-0"><p class="sale-label" x-text="clientDraft.academicResponsible ? 'Responsable del alumno' : 'Cliente seleccionado'"></p><p class="mt-1 truncate text-sm font-medium" x-text="hasBillingDraft ? clientDraft.name : 'Sin seleccionar'"></p></div>
            <flux:button type="button" size="sm" icon="magnifying-glass" x-on:click="openClientPicker()">Cambiar cliente</flux:button>
        </div>
        <fieldset class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3" x-bind:disabled="!hasBillingDraft">
            <legend class="sr-only">Datos del cliente seleccionado</legend>
            <div class="sm:col-span-2"><flux:input label="Nombre / Razón social" x-model="clientDraft.name" required maxlength="255" /></div>
            <div>
                <template x-if="!tuitionStudentsInSale.length"><flux:input label="Alumno de referencia" x-model="clientDraft.student" maxlength="255" placeholder="Opcional" /></template>
                <template x-if="tuitionStudentsInSale.length"><flux:input label="Alumnos de colegiaturas" x-bind:value="tuitionStudentsLabel" readonly /></template>
            </div>
            <flux:select label="Tipo de documento" x-model="clientDraft.documentType" x-on:change="changeClientDocument()" x-bind:disabled="!hasBillingDraft"><option value="">Selecciona un tipo</option>@foreach ($saleCatalogs['identityDocuments'] as $option)<option value="{{ $option['codigo'] }}">{{ $option['valor'] }}</option>@endforeach</flux:select>
            <flux:input label="Número de documento" x-model="clientDraft.document" maxlength="40" placeholder="Número de identificación" />
            <flux:input label="NRC" x-model="clientDraft.nrc" maxlength="40" placeholder="Si aplica" />
            <div class="sm:col-span-2 lg:col-span-3"><flux:input label="Dirección" x-model="clientDraft.address" maxlength="500" /></div>
            <flux:select label="Departamento" x-model="clientDraft.department" x-on:change="changeDraftDepartment()"><option value="">Selecciona un departamento</option>@foreach ($saleCatalogs['geography']['departments'] as $option)<option value="{{ $option['codigo'] }}">{{ $option['valor'] }}</option>@endforeach</flux:select>
            <flux:select label="Distrito" x-model="clientDraft.district" x-on:change="syncDraftLocation()" x-bind:disabled="!hasBillingDraft || !clientDraft.department || !availableDistricts.length"><option value="">Selecciona un distrito</option><template x-for="district in availableDistricts" :key="district.codigo"><option :value="district.codigo" x-text="district.valor"></option></template></flux:select>
            <flux:input label="Municipio" x-bind:value="draftMunicipalityName" readonly placeholder="Se asigna al seleccionar el distrito" />
            <flux:input label="Correo electrónico" type="email" x-model="clientDraft.email" maxlength="255" />
            <flux:input label="Teléfono 1" type="tel" x-model="clientDraft.phone" maxlength="30" />
            <flux:input label="Teléfono 2" type="tel" x-model="clientDraft.phone2" maxlength="30" />
            <div class="sm:col-span-2 lg:col-span-3">
                <flux:select label="Actividad económica" x-model="clientDraft.activity">
                    <option value="">Sin actividad económica</option>
                    <template x-if="clientDraft.activity && !hasKnownActivity"><option :value="clientDraft.activity" x-text="`Código registrado ${clientDraft.activity} · No encontrado en el catálogo`"></option></template>
                    @foreach ($saleCatalogs['economicActivities'] as $option)
                        <option value="{{ $option['codigo'] }}">{{ $option['codigo'] }} · {{ $option['valor'] }}</option>
                    @endforeach
                </flux:select>
                <p x-show="clientDraft.activity && !hasKnownActivity" x-cloak class="mt-2 text-xs text-amber-700">Revisa la actividad registrada o selecciona una del catálogo.</p>
            </div>
        </fieldset>
        <div class="flex justify-end gap-2 border-t border-zinc-200 pt-4 dark:border-brand-paper/10"><flux:modal.close><flux:button>Cancelar</flux:button></flux:modal.close><flux:button type="submit" variant="primary" x-bind:disabled="!hasBillingDraft">Usar estos datos</flux:button></div>
    </form>
</flux:modal>

<flux:modal name="sale-invoice" data-theme="light" class="sale-dialog" aria-labelledby="sale-invoice-title">
    <div class="flex flex-col gap-5">
        <div class="sale-modal-heading"><p class="sale-label">Configuración de la venta</p><h2 id="sale-invoice-title" class="mt-1 text-xl font-semibold tracking-tight">Datos de factura</h2></div>
        <div class="grid gap-4 sm:grid-cols-2">
            <flux:select label="Documento" x-model="document.type">@foreach ($saleCatalogs['documents'] as $option)<option value="{{ $option['codigo'] }}">{{ $option['valor'] }}</option>@endforeach</flux:select>
            <flux:input label="Fecha" type="date" x-model="document.date" />
            <flux:input label="Número de control" value="Se asignará al emitir la factura" readonly />
            <flux:input label="Código de generación" value="Se asignará al emitir la factura" readonly />
            <flux:select label="Tipo de documento de tercero" x-model="document.thirdPartyType" x-bind:disabled="!hasBillingClient"><option value="">Selecciona un tipo</option>@foreach ($saleCatalogs['identityDocuments'] as $option)<option value="{{ $option['codigo'] }}">{{ $option['valor'] }}</option>@endforeach</flux:select>
            <flux:input label="Documento de tercero" x-model="document.thirdPartyDocument" x-bind:disabled="!hasBillingClient || !document.thirdPartyType" maxlength="40" placeholder="Si aplica" />
            <flux:input label="Cuenta a abonar" x-model="document.creditAccount" maxlength="100" />
            <flux:input label="Cuenta contable" x-model="document.account" maxlength="30" />
            <flux:input label="Descuento general (%)" type="number" min="0" max="100" step="0.01" x-model.number="document.discount" x-on:change="document.discount = Math.min(100, Math.max(0, Number(document.discount) || 0))" />
        </div>
        <p class="text-xs leading-5 text-zinc-500 dark:text-zinc-400">El descuento general se aplica después de los descuentos por producto.</p>
        <div class="flex justify-end border-t border-zinc-200 pt-4 dark:border-brand-paper/10"><flux:modal.close><flux:button variant="primary">Volver a la venta</flux:button></flux:modal.close></div>
    </div>
</flux:modal>

<flux:modal name="sale-payment" data-theme="light" class="sale-dialog" aria-labelledby="sale-payment-title">
    <fieldset x-bind:disabled="generating || !!generated" class="min-w-0">
    <div class="flex flex-col gap-5">
        <div class="sale-modal-heading"><p class="sale-label">Último paso</p><h2 id="sale-payment-title" class="mt-1 text-xl font-semibold tracking-tight">Información de pago</h2><p class="mt-1 text-xs">Revisa el importe y distribuye el pago como lo necesites.</p></div>
        <div class="grid gap-5 md:grid-cols-[minmax(0,1fr)_15rem]">
            <div class="flex min-w-0 flex-col gap-5">
                <div class="grid gap-3 sm:grid-cols-3">
                    <div class="sm:col-span-3"><flux:select label="Operación" x-model="operation">@foreach ($saleCatalogs['operations'] as $option)<option value="{{ $option['codigo'] }}">{{ $option['valor'] }}</option>@endforeach</flux:select></div>
                    <div x-show="operation === '2'" x-cloak class="sm:col-span-1"><flux:input label="Número de pagos" type="number" min="1" step="1" x-model.number="installments" x-bind:required="operation === '2'" /></div>
                    <div x-show="operation === '2'" x-cloak class="sm:col-span-2"><flux:select label="Plazos" x-model="term">@foreach ($saleCatalogs['creditTerms'] as $option)<option value="{{ $option['codigo'] }}">{{ $option['valor'] }}</option>@endforeach</flux:select></div>
                </div>
                <div class="flex items-center justify-between gap-2"><h3 class="text-sm font-semibold">Formas de pago</h3><button type="button" class="text-xs font-medium underline underline-offset-4" x-on:click="exactPayment()" @disabled(! collect($saleCatalogs['paymentMethods'])->contains('codigo', '01'))>Efectivo exacto</button></div>
                <div class="flex max-h-[35dvh] flex-col gap-3 overflow-y-auto pe-1">
                    <template x-for="(payment, index) in payments" :key="payment.id">
                        <div class="rounded-xl border border-zinc-200 p-3 dark:border-brand-paper/10">
                            <div class="mb-3 flex items-center justify-between"><span class="sale-label" x-text="`Pago ${index + 1}`"></span><button type="button" x-show="payments.length > 1" class="rounded p-1 text-zinc-500 hover:text-red-500" x-on:click="payments = payments.filter(row => row.id !== payment.id)" :aria-label="`Quitar pago ${index + 1}`"><flux:icon.x-mark class="size-3.5" /></button></div>
                            <div class="grid grid-cols-[minmax(0,1fr)_7rem] gap-3">
                                <flux:select size="sm" label="Forma de pago" x-model="payment.method">@foreach ($saleCatalogs['paymentMethods'] as $option)<option value="{{ $option['codigo'] }}">{{ $option['valor'] }}</option>@endforeach</flux:select>
                                <flux:input size="sm" label="Monto ($)" type="number" min="0" step="0.01" x-model="payment.amount" placeholder="0.00" />
                                <div x-show="payment.method !== '01'" x-cloak class="col-span-2"><flux:input size="sm" label="Referencia" x-model="payment.reference" maxlength="50" placeholder="Número de comprobante o autorización" /></div>
                            </div>
                        </div>
                    </template>
                </div>
                <flux:button size="sm" icon="plus" class="w-full" x-on:click="addPayment()">Agregar otra forma de pago</flux:button>
            </div>
            <aside class="flex flex-col gap-4">
                <div class="rounded-xl bg-brand-yellow p-5 text-brand-ink"><p class="text-[11px] font-semibold tracking-wider uppercase">Total a pagar</p><p class="mt-2 text-4xl font-semibold tracking-tight tabular-nums" x-text="money(total)"></p><p class="mt-3 text-xs" x-text="`${units} unidades · ${client.name}`"></p></div>
                <dl class="grid gap-3 rounded-xl border border-zinc-200 p-4 text-xs dark:border-brand-paper/10">
                    <div class="flex justify-between gap-3"><dt class="text-zinc-500 dark:text-zinc-400">Subtotal</dt><dd class="tabular-nums" x-text="money(subtotal)"></dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-zinc-500 dark:text-zinc-400">Descuento</dt><dd class="tabular-nums" x-text="money(discount)"></dd></div>
                    <div class="flex justify-between gap-3 border-t border-zinc-200 pt-3 dark:border-brand-paper/10"><dt class="text-zinc-500 dark:text-zinc-400">Recibido</dt><dd class="font-semibold tabular-nums" x-text="money(paid)"></dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-zinc-500 dark:text-zinc-400" x-text="operation === '2' ? 'Saldo a crédito' : 'Pendiente'"></dt><dd class="font-semibold tabular-nums" x-text="money(remaining)"></dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-zinc-500 dark:text-zinc-400">Vuelto en efectivo</dt><dd class="font-semibold tabular-nums text-brand-ink dark:text-brand-yellow" x-text="money(change)"></dd></div>
                </dl>
            </aside>
        </div>
        @cannot('libreria.ventas.transmitir')<p class="text-sm text-amber-700">Necesitas el permiso de transmitir ventas para emitir el comprobante.</p>@endcannot
        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-zinc-200 pt-4 dark:border-brand-paper/10"><flux:modal.close><flux:button variant="ghost" icon="arrow-left">Volver al detalle</flux:button></flux:modal.close><flux:button variant="primary" x-on:click="generateSale()" x-bind:disabled="generating || !!generated || {{ auth()->user()->can('libreria.ventas.transmitir') ? 'false' : 'true' }}" icon="document-check"><span x-text="generating ? 'Emitiendo…' : 'Emitir comprobante'"></span></flux:button></div>
    </div>
    </fieldset>
</flux:modal>

<flux:modal name="sale-generated" data-theme="light" class="w-full max-w-lg">
    <div class="space-y-5">
        <flux:heading size="lg"><span x-text="['ACEPTADO', 'ACEPTADO_PRUEBA'].includes(generated?.transmissionState) ? 'Comprobante emitido' : 'Venta registrada'"></span></flux:heading>
        <p class="break-all font-mono text-sm" x-text="generated?.number"></p>
        <p class="text-sm leading-6" role="status" x-text="generated?.message"></p>
        <p x-show="generated && !['ACEPTADO', 'ACEPTADO_PRUEBA'].includes(generated.transmissionState)" class="text-sm text-amber-700">El DTE aún no está aceptado. Revisa su estado desde el historial sin repetir la venta.</p>
        <p class="text-sm font-semibold">¿Deseas una copia del comprobante?</p>
        <div class="flex flex-wrap justify-end gap-3">
            <flux:button x-on:click="finishSale()" x-bind:disabled="generating">No, continuar</flux:button>
            <flux:button variant="primary" x-on:click="finishSale(true)" x-bind:disabled="generating || !generated?.ticketUrl">Sí, abrir copia</flux:button>
        </div>
    </div>
</flux:modal>
<x-catalog-notice name="sale-generation-notice" title="Información de la venta"><p class="whitespace-pre-line" x-text="generationNotice"></p></x-catalog-notice>
<x-catalog-notice name="sale-payment-excess" title="Confirma el monto recibido">
    <p>El monto recibido supera el total de la venta. Revisa el pago y el cambio antes de emitir.</p>
    <dl class="mt-4 space-y-2 tabular-nums">
        <div class="flex justify-between gap-4"><dt>Total a pagar</dt><dd class="font-semibold" x-text="money(paymentExcess?.total || 0)"></dd></div>
        <div class="flex justify-between gap-4"><dt>Monto recibido</dt><dd class="font-semibold" x-text="money(paymentExcess?.paid || 0)"></dd></div>
        <div class="flex justify-between gap-4"><dt>Cambio en efectivo</dt><dd class="font-semibold" x-text="money(paymentExcess?.change || 0)"></dd></div>
    </dl>
    <x-slot:actions><div class="flex flex-wrap justify-end gap-3"><flux:button type="button" x-on:click="cancelExcessPayment()">Revisar pago</flux:button><flux:button type="button" variant="primary" x-on:click="confirmExcessPayment()" x-bind:disabled="generating">Confirmar y emitir</flux:button></div></x-slot:actions>
</x-catalog-notice>

@include('libreria.partials.venta-historial')

<flux:modal name="sale-requests" data-theme="light" class="sale-dialog" aria-labelledby="sale-requests-title">
    <div class="flex flex-col gap-5">
        <div class="sale-modal-heading"><p class="sale-label">Importar a la venta</p><h2 id="sale-requests-title" class="mt-1 text-xl font-semibold tracking-tight">Solicitudes <span x-text="requestSource"></span></h2></div>
        <div class="rounded-xl border border-dashed border-zinc-300 px-6 py-12 text-center dark:border-brand-paper/15"><flux:icon.inbox class="mx-auto size-8 text-zinc-400" /><p class="mt-4 text-sm font-semibold">Bandeja lista para integrar</p><p class="mx-auto mt-2 max-w-sm text-xs leading-5 text-zinc-500 dark:text-zinc-400">Aquí podrás seleccionar las solicitudes de <span x-text="requestSource"></span> y traer sus conceptos al detalle de la venta.</p></div>
        <div class="flex justify-end"><flux:modal.close><flux:button>Volver a la venta</flux:button></flux:modal.close></div>
    </div>
</flux:modal>

<flux:modal name="sale-reset" data-theme="light" class="max-w-sm" aria-labelledby="sale-reset-title">
    <div class="flex flex-col gap-4"><h2 id="sale-reset-title" class="pe-6 text-lg font-semibold">¿Comenzar una nueva venta?</h2><p class="text-sm leading-6 text-zinc-500 dark:text-zinc-400">Se limpiarán los productos, los datos del cliente y los pagos de esta vista previa.</p><div class="flex justify-end gap-2"><flux:modal.close><flux:button>Cancelar</flux:button></flux:modal.close><flux:button variant="primary" x-on:click="newSale()">Nueva venta</flux:button></div></div>
</flux:modal>
