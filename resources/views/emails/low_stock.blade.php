@extends('emails.layouts.professional', [
    'title' => 'Low Stock Alert',
    'heading' => 'Low stock alert',
    'preheader' => 'A product in your catalog is low or out of stock.',
])

@section('content')
    <p style="margin:0 0 8px;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.6;color:#334155;">
        Hello{{ ! empty($user->name) ? ', ' . $user->name : '' }},
    </p>
    <p style="margin:0 0 16px;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.6;color:#334155;">
        The following product needs attention. Stock is low or depleted — restock soon to avoid missed orders.
    </p>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:24px 0;border:1px solid #e2e8f0;">
        <tr>
            <td style="padding:14px 16px;background-color:#f8fafc;border-bottom:1px solid #e2e8f0;font-family:Arial,Helvetica,sans-serif;font-size:12px;letter-spacing:1px;text-transform:uppercase;color:#64748b;width:40%;">
                Product
            </td>
            <td style="padding:14px 16px;border-bottom:1px solid #e2e8f0;font-family:Arial,Helvetica,sans-serif;font-size:15px;font-weight:700;color:#392D87;">
                {{ $product->name ?? 'Product' }}
            </td>
        </tr>
        <tr>
            <td style="padding:14px 16px;background-color:#f8fafc;font-family:Arial,Helvetica,sans-serif;font-size:12px;letter-spacing:1px;text-transform:uppercase;color:#64748b;">
                Current stock
            </td>
            <td style="padding:14px 16px;font-family:Arial,Helvetica,sans-serif;font-size:15px;font-weight:700;color:#b91c1c;">
                {{ $product->qty ?? $product->stock ?? 0 }}
            </td>
        </tr>
    </table>

    <p style="margin:0;font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.6;color:#64748b;">
        Log in to your dashboard to update inventory for this product.
    </p>
@endsection
