<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CspReport extends Model
{
    protected $fillable = [
        'store_id',
        'user_id',
        'ip',
        'user_agent',
        'document_uri',
        'referrer',
        'blocked_uri',
        'violated_directive',
        'effective_directive',
        'original_policy',
        'disposition',
        'status_code',
        'raw',
    ];

    protected $casts = [
        'store_id' => 'integer',
        'user_id' => 'integer',
        'status_code' => 'integer',
        'raw' => 'array',
    ];
}

