<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SecurityEvent extends Model
{
    protected $fillable = [
        'store_id',
        'user_id',
        'kind',
        'severity',
        'ip',
        'method',
        'path',
        'query',
        'user_agent',
        'status_code',
        'meta',
    ];

    protected $casts = [
        'store_id' => 'integer',
        'user_id' => 'integer',
        'status_code' => 'integer',
        'meta' => 'array',
    ];
}

