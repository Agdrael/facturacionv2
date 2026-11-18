<x-layouts::app :title="'Acceso pendiente'">
    <section class="mx-auto w-full max-w-xl space-y-4 py-12">
        <flux:heading size="xl">Acceso pendiente de asignación</flux:heading>
        <flux:text>Tu cuenta está vinculada a un empleado. Un administrador debe asignarte uno o varios roles para que puedas operar.</flux:text>
        <flux:button :href="route('dashboard')">Comprobar acceso</flux:button>
    </section>
</x-layouts::app>
