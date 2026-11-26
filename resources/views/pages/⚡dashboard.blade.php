<?php

use App\Services\Dashboard\DashboardMetricsService;
use Illuminate\Database\QueryException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Inicio · Facturación')] class extends Component
{
    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    #[Url(as: 'caja', except: 'all')]
    public string $register = 'all';

    #[Locked]
    public array $data = [];

    #[Locked]
    public string $loadError = '';

    protected DashboardMetricsService $metrics;

    public function boot(DashboardMetricsService $metrics): void
    {
        abort_unless(auth()->check(), 401);
        $this->metrics = $metrics;
    }

    public function mount(): void
    {
        $this->from = $this->from ?: now(config('cash.timezone'))->toDateString();
        $this->to = $this->to ?: now(config('cash.timezone'))->toDateString();
        $this->refreshData();
    }

    public function hydrate(): void
    {
        $mode = $this->metrics->mode(auth()->user());
        if (($this->data['mode'] ?? null) !== $mode
            || ($mode === 'cashier' && ($this->data['assigned'] ?? null) !== $this->metrics->assigned())) {
            $this->data = [];
            $this->refreshData();
        }
    }

    public function refreshData(): void
    {
        $this->resetValidation();
        if ($this->metrics->mode(auth()->user()) !== 'executive') {
            $this->from = $this->to = now(config('cash.timezone'))->toDateString();
            $this->register = $this->metrics->assigned();
        }
        try {
            $this->data = $this->metrics->query(auth()->user(), $this->from, $this->to, $this->register);
            $this->loadError = '';
        } catch (QueryException $error) {
            report($error);
            $this->loadError = 'No se pudo actualizar el panel. Se conserva la última consulta válida; revisa su fecha de actualización.';
        }
    }

    public function period(int $days): void
    {
        abort_unless(in_array($days, [1, 7, 30], true), 422);
        $this->from = now(config('cash.timezone'))->subDays($days - 1)->toDateString();
        $this->to = now(config('cash.timezone'))->toDateString();
        $this->refreshData();
    }
};
?>

@include('dashboard')
