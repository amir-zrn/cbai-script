<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApiKey extends Model
{
    protected $fillable = [
        'api_key',
        'wp_user_id',
        'total_tokens_allocated',
        'tokens_used',
        'is_active',
        'last_sync_with_wp'
    ];
}
