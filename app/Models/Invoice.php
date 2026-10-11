<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    use BelongsToStore;

    protected $fillable = ['store_id', 'rental_id', 'pdf_path', 'png_path', 'generated_at', 'sent_at', 'status'];

    public function rental()
    {
        return $this->belongsTo(Rental::class);
    }
}
