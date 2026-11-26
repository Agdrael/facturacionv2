<?php

namespace App\Services\Dashboard;

use App\Services\Libreria\CashMovementCalculator;
use App\Services\Libreria\ReceiptMoney;
use Carbon\CarbonImmutable;

class DashboardTotals
{
    /** @var array<string, mixed> */
    private array $summary;

    /** @var array<string, array<string, mixed>> */
    private array $boxes = [];

    /** @var array<string, array<string, mixed>> */
    private array $days = [];

    /** @var list<array<string, mixed>> */
    private array $recent = [];

    /** @var list<array<string, mixed>> */
    private array $issues = [];

    /** @param list<string> $registers */
    public function __construct(array $registers)
    {
        $this->summary = $this->empty();
        foreach ($registers as $register) {
            $this->boxes[$register] = $this->empty();
        }
    }

    /** @return array<string, mixed> */
    private function empty(): array
    {
        return ['sales' => 0, 'salesCount' => 0, 'saleCollected' => 0, 'receipts' => 0, 'receiptCount' => 0,
            'receiptCollected' => 0, 'collections' => 0, 'cash' => 0, 'credit' => 0, 'issues' => 0, 'pendingDte' => 0,
            'pendingReceipt' => 0, 'annulledCount' => 0, 'annulledAmount' => 0, 'annulledUnknown' => 0,
            'documents' => [], 'methods' => [], 'origins' => []];
    }

    /** @param array<string, mixed> $event
     * @param array<string, mixed> $money */
    public function add(array $event, array $money): void
    {
        $this->days[$event['date']] ??= $this->empty();
        $this->apply($this->summary, $event, $money);
        $this->apply($this->boxes[$event['register']], $event, $money);
        $this->apply($this->days[$event['date']], $event, $money);
        $this->remember([...$event, 'amount' => $money['total']]);
    }

    /** @param array<string, mixed> $group
     * @param array<string, mixed> $event
     * @param array<string, mixed> $money */
    private function apply(array &$group, array $event, array $money): void
    {
        $sale = $event['kind'] === 'sale';
        $amount = ReceiptMoney::cents($money['total']);
        $collected = array_sum(array_map(ReceiptMoney::cents(...), $money['payments']));
        $group[$sale ? 'sales' : 'receipts'] += $amount;
        $group[$sale ? 'salesCount' : 'receiptCount']++;
        $group[$sale ? 'saleCollected' : 'receiptCollected'] += $collected;
        $group['collections'] += $collected;
        $group['cash'] += ReceiptMoney::cents($money['payments']['01'] ?? '0');
        $group['credit'] += ReceiptMoney::cents($money['receivable']);
        $source = $event['source'];
        $group['origins'][$source] ??= ['sales' => 0, 'receipts' => 0, 'collections' => 0, 'issues' => 0, 'count' => 0];
        $group['origins'][$source]['count']++;
        $group['origins'][$source][$sale ? 'sales' : 'receipts'] += $amount;
        $group['origins'][$source]['collections'] += $collected;
        if ($sale) {
            $type = $event['type'];
            $group['documents'][$type] ??= ['count' => 0, 'amount' => 0];
            $group['documents'][$type]['count']++;
            $group['documents'][$type]['amount'] += $amount;
            if ($event['state'] !== 'ACEPTADO') {
                $group['pendingDte']++;
            }
        } elseif ($event['state'] !== 'EMITIDO') {
            $group['pendingReceipt']++;
        }
        foreach ($money['payments'] as $method => $value) {
            $group['methods'][$method] ??= ['label' => CashMovementCalculator::METHOD_LABELS[$method] ?? 'Medio '.$method, 'sales' => 0, 'receipts' => 0, 'total' => 0];
            $cents = ReceiptMoney::cents($value);
            $group['methods'][$method][$sale ? 'sales' : 'receipts'] += $cents;
            $group['methods'][$method]['total'] += $cents;
        }
    }

    /** @param array<string, mixed> $event */
    public function issue(array $event, string $message): void
    {
        $this->summary['issues']++;
        $this->boxes[$event['register']]['issues']++;
        $this->days[$event['date']] ??= $this->empty();
        $this->days[$event['date']]['issues']++;
        $source = $event['source'];
        $origin = ['sales' => 0, 'receipts' => 0, 'collections' => 0, 'issues' => 0, 'count' => 0];
        $this->summary['origins'][$source] ??= $origin;
        $this->boxes[$event['register']]['origins'][$source] ??= $origin;
        $this->days[$event['date']]['origins'][$source] ??= $origin;
        $this->summary['origins'][$source]['issues']++;
        $this->boxes[$event['register']]['origins'][$source]['issues']++;
        $this->days[$event['date']]['origins'][$source]['issues']++;
        if (count($this->issues) < 20) {
            $this->issues[] = [...$event, 'message' => $message];
        }
        $this->remember([...$event, 'amount' => null, 'state' => 'REVISION']);
    }

    /** @param array<string, mixed> $event */
    public function annul(array $event, ?int $amount): void
    {
        foreach (['summary', 'box'] as $scope) {
            $group = $scope === 'summary' ? $this->summary : $this->boxes[$event['register']];
            $group['annulledCount']++;
            if ($amount === null) {
                $group['annulledUnknown']++;
            } else {
                $group['annulledAmount'] += $amount;
            }
            if ($scope === 'summary') {
                $this->summary = $group;
            } else {
                $this->boxes[$event['register']] = $group;
            }
        }
        $this->remember([...$event, 'amount' => $amount === null ? null : ReceiptMoney::decimal($amount), 'state' => 'ANULADO']);
    }

    /** @param array<string, mixed> $event */
    private function remember(array $event): void
    {
        $this->recent[] = $event;
        usort($this->recent, fn (array $a, array $b): int => [$b['stamp'], $b['source'], $b['id']] <=> [$a['stamp'], $a['source'], $a['id']]);
        $this->recent = array_slice($this->recent, 0, 10);
    }

    /** @param array<string, mixed> $group
     * @return array<string, mixed> */
    private function formatted(array $group): array
    {
        foreach (['sales', 'saleCollected', 'receipts', 'receiptCollected', 'collections', 'cash', 'credit', 'annulledAmount'] as $key) {
            $group[$key] = ReceiptMoney::decimal($group[$key]);
        }
        foreach ($group['methods'] as &$method) {
            foreach (['sales', 'receipts', 'total'] as $key) {
                $method[$key] = ReceiptMoney::decimal($method[$key]);
            }
        }
        unset($method);
        ksort($group['methods']);
        foreach ($group['documents'] as &$document) {
            $document['amount'] = ReceiptMoney::decimal($document['amount']);
        }
        unset($document);
        foreach ($group['origins'] as &$origin) {
            foreach (['sales', 'receipts', 'collections'] as $key) {
                $origin[$key] = ReceiptMoney::decimal($origin[$key]);
            }
        }
        unset($origin);
        $group['complete'] = $group['issues'] === 0;
        $group['hasVerified'] = $group['salesCount'] + $group['receiptCount'] > 0;

        return $group;
    }

    /** @return array<string, mixed> */
    public function result(string $from, string $to): array
    {
        ksort($this->days);
        $days = [];
        $first = $this->days === [] ? $to : max($from, array_key_first($this->days));
        for ($day = CarbonImmutable::parse($first); $day->toDateString() <= $to; $day = $day->addDay()) {
            $key = $day->toDateString();
            $days[] = ['date' => $key, ...$this->formatted($this->days[$key] ?? $this->empty())];
        }

        return ['summary' => $this->formatted($this->summary),
            'boxes' => array_map($this->formatted(...), $this->boxes), 'days' => $days,
            'recent' => $this->recent, 'issues' => $this->issues];
    }
}
