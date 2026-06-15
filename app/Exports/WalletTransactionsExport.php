<?php
namespace App\Exports;

use App\Models\WalletTransaction;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class WalletTransactionsExport implements FromQuery, WithHeadings, WithMapping, WithStyles
{
    protected array $filters;

    public function __construct(array $filters = [])
    {
        $this->filters = $filters;
    }

    public function query()
    {
        $query = WalletTransaction::with(['wallet.driver.user', 'admin'])
            ->orderBy('created_at', 'desc');

        if (!empty($this->filters['type'])) {
            $query->where('type', $this->filters['type']);
        }
        if (!empty($this->filters['direction'])) {
            $query->where('direction', $this->filters['direction']);
        }
        if (!empty($this->filters['date_from'])) {
            $query->whereDate('created_at', '>=', $this->filters['date_from']);
        }
        if (!empty($this->filters['date_to'])) {
            $query->whereDate('created_at', '<=', $this->filters['date_to']);
        }
        if (!empty($this->filters['min_amount'])) {
            $query->where('amount', '>=', $this->filters['min_amount']);
        }
        if (!empty($this->filters['max_amount'])) {
            $query->where('amount', '<=', $this->filters['max_amount']);
        }

        return $query;
    }

    public function headings(): array
    {
        return ['ID', 'Conductor', 'Tipo', 'Dirección', 'Monto (Bs)', 'Referencia', 'Fecha'];
    }

    public function map($transaction): array
    {
        return [
            $transaction->id,
            $transaction->wallet?->driver?->user?->name ?? 'N/A',
            ucfirst($transaction->type ?? 'N/A'),
            $transaction->direction === 'CREDIT' ? 'Ingreso' : 'Egreso',
            number_format($transaction->amount, 2),
            $transaction->reference_id ?? 'N/A',
            $transaction->created_at?->format('d/m/Y H:i') ?? 'N/A',
        ];
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
