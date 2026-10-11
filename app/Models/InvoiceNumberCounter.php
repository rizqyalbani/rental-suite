<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvoiceNumberCounter extends Model
{
    protected $fillable = ['store_id', 'year', 'next_sequence'];

    public function store(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
