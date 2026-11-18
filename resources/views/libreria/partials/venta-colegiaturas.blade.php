<flux:modal name="sale-tuition" data-theme="light" class="sale-dialog sale-tuition-dialog" aria-labelledby="sale-tuition-title">
    <div class="flex flex-col gap-5">
        <div class="sale-modal-heading">
            <p class="sale-label">Servicios escolares</p>
            <h2 id="sale-tuition-title" class="mt-1 text-xl font-semibold tracking-tight">Matrícula / Colegiatura</h2>
            <p class="mt-1 text-xs">Selecciona el alumno, su responsable económico y las cuotas que deseas facturar.</p>
            <p class="mt-2 text-xs">En pagos normales, las colegiaturas de meses anteriores se atienden en Cuentas por cobrar. Beca y bienhechor pueden incluir meses anteriores.</p>
        </div>

        <div x-show="tuitionStudentsInSale.length" x-cloak class="rounded-xl border border-zinc-200 bg-zinc-50 p-3" aria-label="Alumnos con cuotas agregadas">
            <p class="sale-label">Ya agregados a esta venta</p>
            <div class="mt-2 flex flex-wrap gap-2">
                <template x-for="student in tuitionStudentsInSale" :key="student.id">
                    <span class="rounded-lg border border-zinc-200 bg-white px-3 py-2 text-xs"><span class="font-medium" x-text="student.name"></span><span class="ms-2 text-zinc-500" x-text="`${student.code} · ${student.feeCount} cuota(s)`"></span></span>
                </template>
            </div>
        </div>

        <ol class="flex flex-wrap items-center gap-x-6 gap-y-2 border-b border-zinc-200 pb-4 text-xs" aria-label="Pasos para seleccionar colegiaturas">
            <li class="flex items-center gap-2"><span class="flex size-6 items-center justify-center rounded-full bg-brand-yellow text-[10px] font-semibold text-brand-ink">01</span><span class="font-medium">Alumno</span></li>
            <li class="flex items-center gap-2" :class="tuitionStudent ? 'text-brand-ink' : 'text-zinc-400'"><span class="flex size-6 items-center justify-center rounded-full text-[10px] font-semibold" :class="tuitionStudent ? 'bg-brand-yellow text-brand-ink' : 'bg-zinc-100'">02</span><span>Responsable</span></li>
            <li class="flex items-center gap-2" :class="tuitionResponsibleType ? 'text-brand-ink' : 'text-zinc-400'"><span class="flex size-6 items-center justify-center rounded-full text-[10px] font-semibold" :class="tuitionResponsibleType ? 'bg-brand-yellow text-brand-ink' : 'bg-zinc-100'">03</span><span>Cuotas asignadas</span></li>
        </ol>

        <div x-show="tuitionChoosingStudent">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <div><h3 class="text-sm font-semibold">Seleccionar alumno</h3><p class="mt-1 text-xs text-zinc-500">Busca por nombre o carnet.</p></div>
                <button type="button" x-show="tuitionStudent" class="text-xs font-medium underline underline-offset-4" x-on:click="tuitionChoosingStudent = false">Volver al alumno seleccionado</button>
            </div>
            <flux:input icon="magnifying-glass" x-model="tuitionStudentSearch" x-on:input.debounce.300ms="searchTuitionStudents(1)" placeholder="Nombre o carnet del alumno…" aria-label="Buscar alumno por nombre o carnet" />
            <p x-show="tuitionStudentsLoading" role="status" class="mt-3 text-xs text-zinc-500">Buscando alumnos…</p>
            <div x-show="tuitionStudentsError" x-cloak role="alert" class="mt-3 flex items-center justify-between gap-3 rounded-lg border border-red-200 bg-red-50 p-3 text-xs text-red-800"><span x-text="tuitionStudentsError"></span><flux:button size="xs" x-on:click="searchTuitionStudents(1)">Reintentar</flux:button></div>
            <div class="mt-4 max-h-[40dvh] overflow-auto rounded-xl border border-zinc-200">
                <table class="w-full text-left text-xs">
                    <thead class="sticky top-0 bg-zinc-100 text-zinc-600"><tr><th class="p-3 font-medium">Alumno</th><th class="p-3 font-medium">Carnet</th><th class="hidden p-3 font-medium sm:table-cell">Grado</th><th class="p-3"><span class="sr-only">Seleccionar</span></th></tr></thead>
                    <tbody class="divide-y divide-zinc-200">
                        <template x-for="student in tuitionStudents" :key="student.id">
                            <tr class="hover:bg-brand-yellow/5">
                                <td class="p-3 font-medium" x-text="student.name"></td>
                                <td class="p-3 font-mono text-[11px] text-zinc-500" x-text="student.code"></td>
                                <td class="hidden p-3 text-zinc-500 sm:table-cell" x-text="student.grade"></td>
                                <td class="p-3 text-right"><flux:button size="xs" x-on:click="selectTuitionStudent(student)" x-bind:aria-label="`Seleccionar ${student.name}`">Seleccionar</flux:button></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
                <p x-show="!tuitionStudentsLoading && !tuitionStudentsError && !tuitionStudents.length" class="px-4 py-10 text-center text-xs text-zinc-500">No se encontraron alumnos con ese nombre o carnet.</p>
            </div>
            <div class="mt-3 flex items-center justify-between gap-3 text-xs text-zinc-500">
                <span><span x-text="tuitionTotalStudents"></span> alumnos</span>
                <div class="flex items-center gap-2"><flux:button size="xs" variant="ghost" icon="chevron-left" aria-label="Página anterior de alumnos" x-bind:disabled="tuitionStudentsLoading || tuitionStudentPage <= 1" x-on:click="searchTuitionStudents(tuitionStudentPage - 1)" /><span class="text-[11px]" x-text="`${tuitionStudentPage} / ${tuitionStudentPages}`"></span><flux:button size="xs" variant="ghost" icon="chevron-right" aria-label="Página siguiente de alumnos" x-bind:disabled="tuitionStudentsLoading || tuitionStudentPage >= tuitionStudentPages" x-on:click="searchTuitionStudents(tuitionStudentPage + 1)" /></div>
            </div>
        </div>

        <div x-show="!tuitionChoosingStudent" x-cloak class="grid gap-5 lg:grid-cols-[15rem_minmax(0,1fr)]">
            <aside class="flex min-w-0 flex-col gap-4">
                <section class="rounded-xl border border-zinc-200 bg-zinc-50 p-4" aria-label="Alumno seleccionado">
                    <div class="flex items-center justify-between gap-2"><p class="sale-label">Alumno</p><button type="button" class="text-xs font-medium underline underline-offset-4" x-on:click="chooseAnotherTuitionStudent()">Cambiar</button></div>
                    <div class="mt-3 flex items-start gap-2.5"><div class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-brand-yellow/15"><flux:icon.academic-cap class="size-4" /></div><div><p class="text-sm font-semibold" x-text="tuitionStudent?.name"></p><p class="mt-1 font-mono text-[11px] text-zinc-500" x-text="tuitionStudent?.code"></p></div></div>
                    <dl class="mt-4 grid grid-cols-2 gap-3 text-xs lg:grid-cols-1"><div><dt class="sale-label">Grado / sección</dt><dd class="mt-1" x-text="[tuitionStudent?.grade, tuitionStudent?.section].filter(Boolean).join(' · ')"></dd></div><div><dt class="sale-label">Especialidad</dt><dd class="mt-1" x-text="tuitionStudent?.specialty"></dd></div><div><dt class="sale-label">Año de plataforma</dt><dd class="mt-1" x-text="tuitionStudent?.schoolYear"></dd></div></dl>
                </section>
                <p x-show="tuitionStudentLoading" role="status" class="text-xs text-zinc-500">Cargando responsables…</p>
                <div x-show="tuitionStudentError" x-cloak role="alert" class="rounded-lg border border-red-200 bg-red-50 p-3 text-xs text-red-800"><p x-text="tuitionStudentError"></p><flux:button size="xs" class="mt-2" x-on:click="selectTuitionStudent(tuitionStudent)">Reintentar</flux:button></div>
                <flux:select label="Responsable económico" x-model="tuitionResponsibleType" x-on:change="loadTuitionFees()" x-bind:disabled="tuitionStudentLoading || !!tuitionStudentError">
                    <option value="">Selecciona un responsable</option>
                    <template x-for="responsible in tuitionResponsibleTypes" :key="responsible.id"><option x-bind:value="responsible.id" x-text="responsible.label"></option></template>
                </flux:select>
                <div x-show="tuitionBilling" x-cloak class="rounded-xl border border-zinc-200 p-3">
                    <p class="sale-label">Datos del responsable</p><p class="mt-2 text-xs font-semibold" x-text="tuitionBilling?.name"></p><p class="mt-1 text-[11px] text-zinc-500" x-text="[tuitionBilling?.documentTypeLabel, tuitionBilling?.document].filter(Boolean).join(': ') || 'Documento sin agregar'"></p>
                    <p class="mt-2 break-words text-[11px] text-zinc-500" x-text="tuitionBilling?.email || 'Correo pendiente de completar en los datos del cliente'"></p>
                    <details x-show="tuitionBilling?.address || tuitionBilling?.phone || tuitionBilling?.phone2 || tuitionBilling?.phone3" class="mt-3 text-[11px]"><summary class="cursor-pointer font-medium">Dirección y contacto</summary><p class="mt-2" x-text="tuitionBilling?.address"></p><p class="mt-1 text-zinc-500" x-text="[tuitionBilling?.department, tuitionBilling?.municipality, tuitionBilling?.district].filter(Boolean).join(' · ')"></p><p class="mt-2" x-text="[tuitionBilling?.phone, tuitionBilling?.phone2, tuitionBilling?.phone3].filter(Boolean).join(' / ')"></p></details>
                    <flux:button type="button" size="xs" class="mt-3" x-on:click="editTuitionClient()">Ver / editar para facturación</flux:button>
                </div>
            </aside>

            <section class="flex min-w-0 flex-col overflow-hidden rounded-xl border border-zinc-200" aria-labelledby="sale-tuition-fees-title">
                <div class="sale-panel-heading flex min-h-12 flex-wrap items-center justify-between gap-2 px-4 py-3"><h3 id="sale-tuition-fees-title" class="text-sm font-semibold">Cuotas asignadas</h3><span class="text-[11px] text-brand-paper/65" x-show="tuitionResponsibleType" x-text="tuitionResponsibleLabel"></span></div>
                <div x-show="!tuitionResponsibleType" class="flex min-h-64 flex-1 flex-col items-center justify-center gap-3 px-6 py-10 text-center"><flux:icon.clipboard-document-list class="size-8 text-zinc-400" /><p class="text-sm font-medium">Selecciona un responsable económico</p><p class="max-w-xs text-xs leading-5 text-zinc-500">Después se mostrarán las matrículas y colegiaturas asignadas al alumno.</p></div>
                <p x-show="tuitionFeesLoading" role="status" class="px-4 py-10 text-center text-xs text-zinc-500">Cargando cuotas asignadas…</p>
                <div x-show="tuitionFeesError" x-cloak role="alert" class="m-4 rounded-lg border border-red-200 bg-red-50 p-3 text-xs text-red-800"><p x-text="tuitionFeesError"></p><flux:button size="xs" class="mt-2" x-on:click="loadTuitionFees()">Reintentar</flux:button></div>
                <div x-show="tuitionResponsibleType && !tuitionFeesLoading && !tuitionFeesError" class="min-h-40 max-h-[40dvh] flex-1 overflow-auto">
                    <table class="sale-tuition-table w-full text-left text-xs">
                        <thead class="sticky top-0 z-10 bg-zinc-100 text-zinc-600"><tr><th class="px-3 py-3 font-medium">Servicio</th><th class="px-3 py-3 text-right font-medium">Cuota</th><th class="px-3 py-3 text-right font-medium">Pagado</th><th class="px-3 py-3 text-right font-medium">Restante</th><th class="px-3 py-3 text-center"><flux:checkbox x-bind:checked="allTuitionFeesSelected" x-bind:disabled="!selectableTuitionFees.length" x-on:change="toggleAllTuitionFees()" aria-label="Seleccionar todas las cuotas pendientes" /></th></tr></thead>
                        <tbody class="divide-y divide-zinc-200">
                            <template x-for="fee in tuitionFees" :key="fee.id">
                                <tr :class="tuitionSelectedFeeIds.includes(fee.id) ? 'bg-brand-yellow/10' : ''">
                                    <td class="min-w-32 px-3 py-3"><p class="font-medium" x-text="fee.name"></p><p class="mt-1 text-[10px] text-zinc-500" x-text="tuitionFeeInSale(fee) ? 'Agregada a la venta' : (fee.remaining === 0 ? 'Pagada' : (fee.product?.code || 'Sin producto configurado'))"></p><button type="button" x-show="fee.remaining > 0 && !fee.canSelect" class="mt-1 text-xs font-medium underline" x-on:click="$flux.modal('sale-tuition-configuration').show()">Ver configuración pendiente</button></td>
                                    <td class="whitespace-nowrap px-3 py-3 text-right tabular-nums" x-text="money(fee.quota)"></td>
                                    <td class="whitespace-nowrap px-3 py-3 text-right tabular-nums text-zinc-500" x-text="money(fee.paid)"></td>
                                    <td class="whitespace-nowrap px-3 py-3 text-right font-semibold tabular-nums" x-text="money(fee.remaining)"></td>
                                    <td class="px-3 py-3"><div class="flex justify-center"><flux:checkbox x-model="tuitionSelectedFeeIds" x-bind:value="fee.id" x-bind:disabled="fee.remaining <= 0 || !fee.canSelect || !fee.product || tuitionFeeInSale(fee)" x-bind:aria-label="`Seleccionar ${fee.name}`" /></div></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                    <p x-show="!tuitionFees.length" class="px-4 py-10 text-center text-xs text-zinc-500">Este responsable no tiene servicios disponibles para facturar aquí.</p>
                </div>
                <div class="mt-auto flex items-center justify-between gap-3 border-t border-zinc-200 bg-zinc-50 px-4 py-3 text-xs"><span class="text-zinc-500"><span x-text="selectedTuitionFees.length"></span> cuotas seleccionadas</span><strong class="text-base tabular-nums" x-text="money(tuitionSelectedTotal)"></strong></div>
            </section>
        </div>

        <footer class="flex flex-wrap items-center justify-between gap-3 border-t border-zinc-200 pt-4">
            <p class="text-[11px] text-zinc-500">Puedes combinar alumnos, becas y bienhechor. Revisa los datos del cliente antes de pagar.</p>
            <div class="flex flex-wrap gap-2"><flux:modal.close><flux:button>Cancelar</flux:button></flux:modal.close><flux:button x-bind:disabled="tuitionChoosingStudent || !selectedTuitionFees.length" x-on:click="confirmTuitionFees(true)">Agregar y otro alumno</flux:button><flux:button variant="primary" x-bind:disabled="tuitionChoosingStudent || !selectedTuitionFees.length" x-on:click="confirmTuitionFees()">Agregar cuotas <flux:icon.arrow-right class="ms-1 size-4" /></flux:button></div>
        </footer>
    </div>
</flux:modal>

<flux:modal name="sale-tuition-configuration" data-theme="light" :dismissible="false" class="w-full max-w-lg">
    <div class="space-y-5">
        <div class="flex items-start gap-3"><flux:icon.exclamation-triangle class="size-6 shrink-0 text-amber-600" /><flux:heading size="lg">Configuración de servicios pendiente</flux:heading></div>
        <p class="text-sm leading-6 text-zinc-600">Estos servicios se muestran para consulta. Para agregarlos a la venta deben tener su propio producto y cuenta contable configurados.</p>
        <ul class="max-h-64 list-disc space-y-3 overflow-auto rounded-xl border border-zinc-200 bg-zinc-50 p-5 ps-8 text-sm" role="alert"><template x-for="message in tuitionConfigurationErrors" :key="message"><li x-text="message"></li></template></ul>
        <div class="flex justify-end"><flux:modal.close><flux:button variant="primary">Entendido</flux:button></flux:modal.close></div>
    </div>
</flux:modal>
