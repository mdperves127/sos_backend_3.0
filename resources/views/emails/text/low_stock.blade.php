{{ config('app.name', 'Affsell') }}

Low stock alert

Hello{{ ! empty($user->name) ? ', ' . $user->name : '' }},

The following product needs attention. Stock is low or depleted - restock soon to avoid missed orders.

Product: {{ $product->name ?? 'Product' }}
Current stock: {{ $product->qty ?? $product->stock ?? 0 }}

Log in to your dashboard to update inventory for this product.

---
This is a transactional message from {{ config('app.name', 'Affsell') }}.
If you did not request this, you can safely ignore this email.
© {{ date('Y') }} {{ config('app.name', 'Affsell') }}. All rights reserved.
