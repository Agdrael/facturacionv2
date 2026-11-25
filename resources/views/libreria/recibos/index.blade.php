<x-layouts::app :title="'Recibos · Librería'">
    <div x-data="libreriaReceipts(@js($options))">
        <div class="sales-workspace">
            <header class="flex shrink-0 flex-wrap items-center justify-between gap-3">
                <div>
                    <p class="mb-1.5 text-xs text-zinc-500 dark:text-zinc-400">Librería <span class="mx-1.5">/</span> Recibos</p>
                    <div class="flex items-center gap-3"><h1 class="text-2xl font-semibold tracking-tight">Nuevo recibo</h1><span class="rounded-md border border-brand-yellow/25 bg-brand-yellow/10 px-2 py-1 text-[10px] font-semibold">{{ auth()->user()->puntoventa ?? 'Sin caja' }}</span></div>
                </div>
                <div class="flex items-center gap-2">
                    <flux:button size="sm" x-show="completed" x-on:click="$flux.modal('receipt-copy').show()">Comprobante registrado</flux:button>
                    <flux:button size="sm" variant="ghost" icon="clock" x-on:click="openHistory()">Historial</flux:button>
                    <flux:button size="sm" icon="plus" x-show="options.canCreate" x-on:click="$flux.modal('receipt-reset').show()" x-bind:disabled="locked || selecting">Nuevo recibo</flux:button>
                </div>
            </header>
            <div x-show="notice" x-cloak role="status" class="rounded-xl border border-amber-400/30 bg-amber-400/10 px-4 py-3 text-sm"><p class="whitespace-pre-line" x-text="notice"></p></div>
            <section x-show="!options.canCreate" data-theme="light" class="sale-panel p-8 text-center"><p class="text-sm">Consulta los comprobantes y las acciones disponibles para tu rol.</p><flux:button class="mt-4" size="sm" x-on:click="openHistory()">Consultar recibos</flux:button></section>
            <fieldset x-show="options.canCreate" x-bind:disabled="busy || selecting || !!completed" class="contents">
                <section data-theme="light" class="sale-panel flex shrink-0 flex-wrap items-center justify-between gap-3 p-2" aria-label="Origen del recibo">
                    <div class="grid w-full grid-cols-3 gap-1 sm:w-auto">
                        <template x-for="entry in [{ mode: 'catalog', label: 'Tipo del catálogo' }, { mode: 'client_cxc', label: 'CXC de cliente' }, { mode: 'academic_cxc', label: 'CXC de alumno' }]" :key="entry.mode">
                            <button type="button" class="rounded-lg px-3 py-2.5 text-xs font-medium transition-colors sm:px-5" :class="mode === entry.mode ? 'bg-brand-ink text-brand-paper shadow-sm' : 'text-zinc-500 hover:bg-zinc-100 hover:text-zinc-900'" :aria-pressed="mode === entry.mode" x-on:click="if (mode !== entry.mode) setMode(entry.mode)" x-text="entry.label"></button>
                        </template>
                    </div>
                    <p class="hidden px-3 text-xs text-zinc-500 sm:block" x-text="options.date"></p>
                </section>
                <div class="grid min-h-0 flex-1 gap-4 xl:grid-cols-[minmax(0,1fr)_21rem]">
                    <fieldset x-bind:disabled="locked" data-theme="light" class="sale-panel flex min-w-0 flex-col overflow-hidden" aria-label="Preparar recibo">
                        <div class="sale-panel-heading flex items-center justify-between gap-3 border-b px-5 py-3">
                            <h2 class="text-sm font-semibold" x-text="mode === 'academic_cxc' ? 'Cuotas del alumno' : 'Información del recibo'"></h2>
                            <span x-show="mode === 'academic_cxc'" data-sale-line-count class="rounded-md px-2 py-1 text-[11px]" x-text="academicItems.length + (academicItems.length === 1 ? ' cuota' : ' cuotas')"></span>
                        </div>
                        <div class="min-h-0 flex-1 overflow-auto">
                            <div x-show="mode === 'academic_cxc'" class="border-b border-zinc-200 p-5">
                                <div class="flex flex-wrap items-center justify-between gap-4">
                                    <div class="flex min-w-0 items-center gap-3">
                                        <div class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-brand-yellow/20 text-base font-semibold" x-text="form.studentName ? form.studentName.slice(0, 1) : '—'"></div>
                                        <div class="min-w-0"><p class="sale-label">Alumno</p><p class="mt-1 text-sm font-semibold" x-text="form.studentName || 'Selecciona las cuotas de un alumno'"></p><p x-show="form.studentId" class="mt-1 font-mono text-xs text-zinc-500" x-text="'Carnet ' + form.studentId"></p></div>
                                    </div>
                                    <flux:button x-show="academicItems.length" size="sm" icon="plus" x-on:click="openLookup('academic_cxc')">Agregar cuotas</flux:button>
                                </div>
                            </div>
                            <div x-show="mode === 'academic_cxc' && !academicItems.length" class="flex min-h-64 flex-col items-center justify-center px-6 py-10 text-center">
                                <div class="mb-4 flex size-14 items-center justify-center rounded-2xl border border-dashed border-zinc-300 bg-zinc-100"><flux:icon.magnifying-glass class="size-6 text-zinc-400" /></div>
                                <h3 class="text-base font-semibold">¿Qué cuotas vas a cobrar?</h3>
                                <p class="mt-2 max-w-sm text-xs leading-relaxed text-zinc-500">Busca al alumno y agrega sus cuotas pendientes. Puedes pagar el saldo completo o indicar un abono para cada una.</p>
                                <flux:button class="mt-5" variant="primary" size="sm" icon="magnifying-glass" x-on:click="openLookup('academic_cxc')">Buscar cuotas pendientes</flux:button>
                            </div>
                            <div x-show="mode === 'academic_cxc' && academicItems.length" class="overflow-x-auto">
                                <table class="sale-workspace-table w-full min-w-[32rem] text-left text-sm">
                                    <thead class="bg-zinc-100 text-zinc-500"><tr><th>Cuota / período</th><th class="text-right!">Saldo pendiente</th><th class="w-36 text-right!">Abono</th><th><span class="sr-only">Quitar cuota</span></th></tr></thead>
                                    <tbody class="divide-y divide-zinc-200">
                                        <template x-for="(item, index) in academicItems" :key="item.sourceId + ':' + item.funding">
                                            <tr>
                                                <td><p class="font-medium" x-text="item.name"></p><span class="mt-1.5 inline-block rounded bg-zinc-100 px-1.5 py-0.5 text-[10px] text-zinc-500" x-text="fundingLabel(item.funding)"></span><details class="mt-2 text-[11px] text-zinc-500"><summary class="cursor-pointer hover:text-zinc-900">Factura y cuenta</summary><p class="mt-2 max-w-64 break-all font-mono" x-text="item.invoiceNumber"></p><p class="mt-1 font-mono" x-text="item.account"></p></details></td>
                                                <td class="text-right align-top tabular-nums text-zinc-500" x-text="money(cents(item.remaining))"></td>
                                                <td class="align-top"><flux:input size="sm" type="number" min="0.01" step="0.01" x-bind:max="item.remaining" x-model="item.amount" x-bind:aria-label="'Abono de ' + item.name" class="w-28" /><p class="mt-1.5 text-right text-[10px] text-zinc-500" x-text="cents(item.amount) < cents(item.remaining) ? 'Abono parcial' : 'Pago completo'"></p></td>
                                                <td class="align-top"><flux:button size="xs" variant="ghost" x-on:click="removeAcademic(index)" x-bind:aria-label="'Quitar ' + item.name">Quitar</flux:button></td>
                                            </tr>
                                        </template>
                                    </tbody>
                                </table>
                                <div class="flex items-center justify-between border-t border-zinc-200 px-5 py-3 text-xs"><span class="text-zinc-500">Los abonos se suman en un solo comprobante.</span><span class="font-semibold tabular-nums" x-text="money(total)"></span></div>
                            </div>
                            <div class="space-y-4 p-5" :class="mode === 'academic_cxc' ? 'border-t border-zinc-200' : ''">
                                <div x-show="mode === 'catalog'"><flux:select label="Tipo de recibo" size="sm" x-model="form.conceptId" x-on:change="selectConcept()"><option value="">Selecciona un tipo del catálogo</option><template x-for="row in options.concepts" :key="row.id"><option :value="row.id" x-text="row.name"></option></template></flux:select><p class="mt-2 text-xs text-zinc-500" x-text="concept?.kind === 'anticipo' ? 'Selecciona un cliente para registrar el saldo a su favor.' : 'Escribe el nombre de la persona; no necesita estar registrada.'"></p></div>
                                <div x-show="mode === 'client_cxc'" class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-zinc-200 p-4"><div><p class="sale-label">Factura pendiente</p><p class="mt-2 text-sm font-medium" x-text="selection?.name || 'Selecciona una cuenta por cobrar'"></p><p x-show="selection" class="mt-1 text-xs text-zinc-500" x-text="'Saldo: ' + money(cents(selection?.remaining))"></p></div><flux:button size="sm" x-on:click="openLookup('client_cxc')"><span x-text="selection ? 'Cambiar factura' : 'Buscar factura'"></span></flux:button></div>
                                <div><div x-show="mode === 'academic_cxc'" class="mb-3"><p class="sale-label">Datos de quien paga</p><p class="mt-1 text-[11px] text-zinc-500">Este nombre aparecerá en el comprobante.</p></div><div class="grid items-end gap-3 sm:grid-cols-[1fr_auto]"><flux:input label="Recibido de" size="sm" x-model="form.recipient.name" x-on:input="form.recipient.clientId = null" maxlength="255" placeholder="Nombre completo de la persona" /><flux:button x-show="mode === 'catalog'" size="sm" icon="magnifying-glass" x-on:click="openLookup('clients')">Buscar cliente</flux:button></div><p x-show="mode === 'catalog' && form.recipient.clientId" class="mt-2 text-[11px] text-zinc-500">Cliente seleccionado del catálogo.</p></div>
                                <details class="text-xs text-zinc-500"><summary class="cursor-pointer font-medium hover:text-zinc-900">Agregar documento de identidad <span class="font-normal">(opcional)</span></summary><div class="mt-3 grid gap-3 sm:grid-cols-[11rem_1fr]"><flux:select label="Tipo de documento" size="sm" x-model="form.recipient.documentType"><option value="">Sin documento</option><template x-for="row in options.identityDocuments" :key="row.codigo"><option :value="row.codigo" x-text="row.valor"></option></template></flux:select><flux:input label="Número de documento" size="sm" x-model="form.recipient.document" maxlength="40" /></div></details>
                                <div x-show="concept?.kind === 'fotografia' && mode === 'catalog'" class="space-y-3 rounded-xl border border-zinc-200 p-4"><div class="flex items-center justify-between gap-3"><div><p class="sale-label">Alumno</p><p class="mt-2 text-sm font-medium" x-text="form.studentName || 'Selecciona un alumno'"></p><p class="mt-1 font-mono text-xs text-zinc-500" x-text="form.studentId"></p></div><flux:button size="xs" x-on:click="openLookup('students')">Buscar alumno</flux:button></div><div class="grid gap-3 sm:grid-cols-2"><flux:input label="Año académico" size="sm" type="number" min="2000" max="2100" x-model.number="form.year" /><flux:select label="Responsable del pago" size="sm" x-model="form.funding"><option value="normal">Responsable familiar</option><option value="beca">Beca</option><option value="bienhechor">Bienhechor</option></flux:select></div></div>
                                <div x-show="mode === 'client_cxc' && selection"><p class="sale-label">Factura de origen</p><p class="mt-2 break-all font-mono text-xs" x-text="form.invoice?.number"></p></div>
                                <div x-show="mode !== 'academic_cxc'" class="grid gap-3 sm:grid-cols-[1fr_10rem]"><div><template x-if="mode === 'catalog'"><flux:input label="Cuenta de abono" size="sm" x-model="form.account" readonly /></template><template x-if="mode === 'client_cxc'"><flux:select label="Cuenta por cobrar" size="sm" x-model="form.account"><option value="">Selecciona una cuenta</option><template x-for="row in options.accounts" :key="row.account"><option :value="row.account" x-text="row.account + ' · ' + row.name"></option></template></flux:select></template></div><flux:input label="Monto a cobrar" size="sm" type="number" min="0.01" step="0.01" x-bind:max="selection?.remaining || '99999999.99'" x-model="form.amount" /></div>
                            </div>
                        </div>
                        <div class="flex shrink-0 items-start gap-3 border-t border-brand-yellow/30 bg-brand-yellow/10 px-5 py-4">
                            <div class="mt-1 shrink-0 rounded-lg bg-brand-yellow/25 p-2 text-brand-ink"><flux:icon.chat-bubble-bottom-center-text class="size-5" /></div>
                            <div class="min-w-0 flex-1"><flux:textarea label="Observación del recibo (opcional)" x-model="form.observation" maxlength="500" rows="2" resize="none" class="min-h-20 bg-white! text-base! leading-relaxed! text-brand-ink! placeholder:text-zinc-500!" placeholder="Escribe aquí una indicación o detalle importante de este recibo…" /></div>
                        </div>
                    </fieldset>
                    <aside data-theme="light" class="sale-panel flex max-h-full min-w-0 flex-col self-start overflow-hidden" aria-label="Cobro del recibo">
                        <header class="flex shrink-0 items-center justify-between gap-4 border-b border-zinc-200 px-5 py-4">
                            <div><h2 class="text-sm font-semibold">Cobro del recibo</h2><p class="mt-1 text-xs text-zinc-500">Total a recibir</p></div>
                            <p class="text-2xl font-semibold tracking-tight tabular-nums" x-text="money(total)"></p>
                        </header>
                        <fieldset x-bind:disabled="locked" class="min-h-0 min-w-0 space-y-4 overflow-y-auto px-5 py-4">
                            <div class="grid grid-cols-[minmax(0,1fr)_6rem_1.5rem] gap-2 text-[11px] font-medium text-zinc-500"><span>Forma de pago</span><span class="text-right">Monto ($)</span><span class="sr-only">Quitar pago</span></div>
                            <template x-for="(payment, index) in form.payments" :key="payment.uid">
                                <div :class="index > 0 ? 'border-t border-zinc-200 pt-4' : ''">
                                    <div class="grid grid-cols-[minmax(0,1fr)_6rem_1.5rem] items-center gap-2">
                                        <flux:select size="sm" x-model="payment.method" x-bind:aria-label="'Forma de pago ' + (index + 1)"><template x-for="row in options.methods" :key="row.codigo"><option :value="row.codigo" x-text="row.valor"></option></template></flux:select>
                                        <flux:input size="sm" type="number" min="0.01" step="0.01" placeholder="0.00" x-model="payment.amount" x-bind:aria-label="'Monto recibido del pago ' + (index + 1)" class="[&_input]:text-right" />
                                        <flux:button size="xs" variant="ghost" icon="x-mark" x-show="form.payments.length > 1" x-on:click="removePayment(payment.uid)" x-bind:aria-label="'Quitar pago ' + (index + 1)" />
                                    </div>
                                    <div x-show="payment.method !== '01'" class="mt-2"><flux:input size="sm" placeholder="Referencia (opcional)" x-model="payment.reference" maxlength="100" x-bind:aria-label="'Referencia del pago ' + (index + 1)" /></div>
                                    <div x-show="total > 0 && paid < total" class="mt-2 text-right"><button type="button" class="text-[11px] font-medium text-zinc-600 underline decoration-zinc-300 underline-offset-4 hover:text-brand-ink" x-on:click="completePayment(payment.uid)">Completar monto</button></div>
                                </div>
                            </template>
                            <button type="button" class="inline-flex items-center gap-1.5 text-xs font-medium text-zinc-600 hover:text-brand-ink disabled:opacity-50" x-on:click="addPayment()"><flux:icon.plus class="size-3.5" /><span x-text="form.payments.length === 1 ? 'Dividir entre varios medios' : 'Agregar otro medio'"></span></button>
                        </fieldset>
                        <div class="shrink-0 space-y-4 border-t border-zinc-200 px-5 py-4">
                            <dl x-show="paid > 0 || total > 0" class="space-y-2.5 text-xs" aria-live="polite">
                                <div x-show="form.payments.length > 1" class="flex justify-between"><dt class="text-zinc-500">Total recibido</dt><dd class="font-medium tabular-nums" x-text="money(paid)"></dd></div>
                                <div x-show="paid < total" class="flex justify-between text-amber-700"><dt>Por completar</dt><dd class="font-semibold tabular-nums" x-text="money(Math.max(0, total - paid))"></dd></div>
                                <div x-show="change > 0 && change <= cash" class="flex items-center justify-between rounded-lg bg-brand-yellow/10 px-3 py-2.5"><dt>Cambio en efectivo</dt><dd class="text-lg font-semibold tabular-nums" x-text="money(change)"></dd></div>
                                <div x-show="change > cash" role="status" class="rounded-lg bg-red-500/5 px-3 py-2 text-red-700">El excedente solo puede devolverse del efectivo recibido.</div>
                            </dl>
                            <flux:button variant="primary" class="w-full" x-on:click="issue()" x-bind:disabled="busy || selecting || !!completed || (!submittedPayload && total <= 0)"><span x-text="busy ? 'Registrando…' : (submittedPayload ? 'Reintentar mismo recibo' : 'Emitir recibo')"></span></flux:button>
                            <p class="text-center text-[10px] text-zinc-500">Podrás elegir si deseas una copia al finalizar.</p>
                            <details class="text-[10px] text-zinc-500"><summary class="cursor-pointer">Cuenta de cargo</summary><p class="mt-2 font-mono" x-text="options.cashAccount"></p></details>
                        </div>
                    </aside>
                </div>
            </fieldset>
        </div>
        @include('libreria.recibos.partials.modals')
    </div>
</x-layouts::app>
