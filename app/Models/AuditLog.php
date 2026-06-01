<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    protected $fillable = ['event', 'order_id', 'payment_id', 'metadata'];

    protected $casts = ['metadata' => 'array'];
}
