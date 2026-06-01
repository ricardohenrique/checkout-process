<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use HasFactory;

    const STATUS_PENDING = 'pending';
    const STATUS_PAID = 'paid';
    const STATUS_FAILED = 'failed';
    const STATUS_CANCELLED = 'cancelled';

    protected $fillable = ['user_id', 'status', 'total', 'discount_percent', 'notes'];

    protected $casts = [
        'total' => 'float',
        'discount_percent' => 'integer',
    ];

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Calculate the final total after discount.
     */
    public function calculateTotal(): float
    {
        $subtotal = 0;
        foreach ($this->items as $item) {
            $subtotal += $item->price * $item->quantity;
        }

        if ($this->discount_percent > 0) {
            $subtotal = $subtotal * (1 - $this->discount_percent / 100);
        }

        return round($subtotal, 2);
    }
}
