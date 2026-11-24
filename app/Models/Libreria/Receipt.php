<?php

namespace App\Models\Libreria;

use Database\Factories\Libreria\ReceiptFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Receipt extends Model
{
    /** @use HasFactory<ReceiptFactory> */
    use HasFactory;

    protected $table = 'libreria.receipts';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['annulled_at' => 'immutable_datetime', 'total' => 'decimal:2', 'paid' => 'decimal:2', 'change' => 'decimal:2'];
    }

    public function number(): string
    {
        return 'R-'.str_pad((string) $this->id, 8, '0', STR_PAD_LEFT);
    }
}
