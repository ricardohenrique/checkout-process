<h1>Order Confirmation</h1>

<p>Thank you for your order #{{ $order->id }}!</p>

<p><strong>Total:</strong> €{{ number_format($order->total, 2) }}</p>

<h3>Items:</h3>
<ul>
    @foreach($order->items as $item)
        <li>{{ $item->product->name }} × {{ $item->quantity }} — €{{ number_format($item->price * $item->quantity, 2) }}</li>
    @endforeach
</ul>

<p>Your order is being processed.</p>
