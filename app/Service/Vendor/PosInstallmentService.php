<?php

namespace App\Service\Vendor;

use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Installment;
use App\Models\InstallmentPayment;
use App\Models\PosSales;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PosInstallmentService
{
    /**
     * Validate installment schedule rows against order total.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array{installment_number:int,amount:float,due_date:string}>
     */
    public static function validateSchedule( array $rows, float $orderTotal ): array
    {
        if ( $rows === [] ) {
            throw ValidationException::withMessages( [
                'installments' => ['At least one installment is required.'],
            ] );
        }

        $normalized = [];
        $sum        = 0.0;

        foreach ( $rows as $index => $row ) {
            $number = (int) ( $row['installment_number'] ?? ( $index + 1 ) );
            $amount = round( (float) ( $row['amount'] ?? 0 ), 2 );
            $dueDate = $row['due_date'] ?? null;

            if ( $amount <= 0 ) {
                throw ValidationException::withMessages( [
                    "installments.{$index}.amount" => ['Each installment amount must be greater than zero.'],
                ] );
            }

            if ( ! $dueDate ) {
                throw ValidationException::withMessages( [
                    "installments.{$index}.due_date" => ['Each installment requires a due date.'],
                ] );
            }

            $normalized[] = [
                'installment_number' => $number,
                'amount'             => $amount,
                'due_date'           => $dueDate,
            ];
            $sum += $amount;
        }

        $sum = round( $sum, 2 );
        $orderTotal = round( $orderTotal, 2 );

        if ( abs( $sum - $orderTotal ) > 0.01 ) {
            throw ValidationException::withMessages( [
                'installments' => [
                    "Sum of installment amounts ({$sum}) must equal order total ({$orderTotal}).",
                ],
            ] );
        }

        usort( $normalized, fn ( $a, $b ) => $a['installment_number'] <=> $b['installment_number'] );

        return $normalized;
    }

    /**
     * Create installment rows for a POS sale. Does not create pos_sale_dues.
     *
     * @param  array<int, array{installment_number:int,amount:float,due_date:string}>  $schedule
     */
    public static function createPlan( PosSales $sale, array $schedule ): void
    {
        foreach ( $schedule as $row ) {
            Installment::create( [
                'pos_sales_id'       => $sale->id,
                'installment_number' => $row['installment_number'],
                'amount'             => $row['amount'],
                'paid_amount'        => 0,
                'remaining_amount'   => $row['amount'],
                'due_date'           => $row['due_date'],
                'status'             => 'unpaid',
            ] );
        }
    }

    /**
     * Apply an initial checkout payment across installments in order (1, 2, …).
     */
    public static function applyInitialPayment( PosSales $sale, float $paidAmount, int $paymentMethodId ): void
    {
        if ( $paidAmount <= 0 ) {
            return;
        }

        $remainingToApply = round( $paidAmount, 2 );
        $installments     = Installment::where( 'pos_sales_id', $sale->id )
            ->orderBy( 'installment_number' )
            ->get();

        foreach ( $installments as $installment ) {
            if ( $remainingToApply <= 0 ) {
                break;
            }

            $apply = min( (float) $installment->remaining_amount, $remainingToApply );
            if ( $apply <= 0 ) {
                continue;
            }

            self::recordPayment( $sale, $installment, $apply, $paymentMethodId, false );
            $remainingToApply = round( $remainingToApply - $apply, 2 );
        }
    }

    /**
     * Collect payment against one installment; syncs sale paid/due totals + CustomerPayment ledger.
     */
    public static function recordPayment(
        PosSales $sale,
        Installment $installment,
        float $amount,
        int $paymentMethodId,
        bool $createCustomerPayment = true,
        ?string $note = null
    ): InstallmentPayment {
        $amount = round( $amount, 2 );

        if ( $amount <= 0 ) {
            throw ValidationException::withMessages( [
                'amount' => ['Payment amount must be greater than zero.'],
            ] );
        }

        if ( $installment->pos_sales_id !== $sale->id ) {
            throw ValidationException::withMessages( [
                'installment_id' => ['Installment does not belong to this order.'],
            ] );
        }

        if ( (float) $installment->remaining_amount <= 0 ) {
            throw ValidationException::withMessages( [
                'amount' => ['This installment is already fully paid.'],
            ] );
        }

        if ( $amount > (float) $installment->remaining_amount + 0.001 ) {
            throw ValidationException::withMessages( [
                'amount' => ['Amount exceeds installment remaining balance.'],
            ] );
        }

        return DB::transaction( function () use ( $sale, $installment, $amount, $paymentMethodId, $createCustomerPayment, $note ) {
            $installment->paid_amount = round( (float) $installment->paid_amount + $amount, 2 );
            $installment->syncAmountsAndStatus();

            $customerPaymentId = null;

            if ( $createCustomerPayment ) {
                $sale->decrement( 'due_amount', $amount );
                $sale->increment( 'paid_amount', $amount );
                $sale->refresh();

                if ( (float) $sale->due_amount <= 0 ) {
                    $sale->due_amount     = 0;
                    $sale->payment_status = 'paid';
                    $sale->save();
                }

                $payment                    = new CustomerPayment();
                $payment->user_id           = Auth::id();
                $payment->vendor_id         = vendorId();
                $payment->customer_id       = $sale->customer_id;
                $payment->pos_sales_id      = $sale->id;
                $payment->payment_method_id = $paymentMethodId;
                $payment->invoice_no        = $sale->barcode;
                $payment->date              = date( 'Y-m-d' );
                $payment->paid_amount       = $amount;
                $payment->due_amount        = max( 0, (float) $sale->due_amount );
                $payment->save();
                $customerPaymentId = $payment->id;
            }

            $row = InstallmentPayment::create( [
                'installment_id'       => $installment->id,
                'pos_sales_id'         => $sale->id,
                'customer_payment_id'  => $customerPaymentId,
                'payment_method_id'    => $paymentMethodId,
                'user_id'              => Auth::id(),
                'amount'               => $amount,
                'paid_at'              => now(),
                'note'                 => $note,
            ] );

            self::syncSaleFromInstallments( $sale );

            return $row->load( ['paymentMethod:id,payment_method_name', 'user:id,name'] );
        } );
    }

    /**
     * Keep pos_sales paid/due in sync with installment totals (source of truth for installment orders).
     */
    public static function syncSaleFromInstallments( PosSales $sale ): void
    {
        $installments = Installment::where( 'pos_sales_id', $sale->id )->get();
        if ( $installments->isEmpty() ) {
            return;
        }

        $totalPaid = round( (float) $installments->sum( 'paid_amount' ), 2 );
        $totalRemaining = round( (float) $installments->sum( 'remaining_amount' ), 2 );
        $orderTotal = round( (float) $sale->total_price, 2 );

        $sale->paid_amount = min( $totalPaid, $orderTotal );
        $sale->due_amount  = max( 0, $totalRemaining );
        $sale->payment_status = $totalRemaining <= 0 ? 'paid' : 'due';
        $sale->save();
    }

    /**
     * @return array<string, mixed>
     */
    public static function planSummary( PosSales $sale ): array
    {
        $sale->loadMissing( [
            'customer:id,customer_name,phone,email,address',
            'installments.payments.paymentMethod:id,payment_method_name',
            'installments.payments.user:id,name',
        ] );

        $installments = $sale->installments->sortBy( 'installment_number' )->values();
        $totalPaid    = round( (float) $installments->sum( 'paid_amount' ), 2 );
        $totalRemaining = round( (float) $installments->sum( 'remaining_amount' ), 2 );
        $next = $installments->first( fn ( Installment $i ) => (float) $i->remaining_amount > 0 );

        return [
            'order' => [
                'id'             => $sale->id,
                'invoice'        => $sale->barcode,
                'sale_date'      => $sale->sale_date,
                'total_price'    => (float) $sale->total_price,
                'paid_amount'    => (float) $sale->paid_amount,
                'due_amount'     => (float) $sale->due_amount,
                'payment_status' => $sale->payment_status,
                'is_installment' => true,
            ],
            'customer' => $sale->customer,
            'summary'  => [
                'installment_count' => $installments->count(),
                'total_amount'      => (float) $sale->total_price,
                'total_paid'        => $totalPaid,
                'total_remaining'   => $totalRemaining,
                'next_due_date'     => optional( $next?->due_date )->format( 'Y-m-d' ),
                'plan_status'       => $totalRemaining <= 0 ? 'paid' : ( $totalPaid > 0 ? 'partial' : 'unpaid' ),
            ],
            'installments' => $installments->map( fn ( Installment $i ) => self::formatInstallment( $i ) )->values(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function formatInstallment( Installment $installment ): array
    {
        $installment->loadMissing( [
            'payments.paymentMethod:id,payment_method_name',
            'payments.user:id,name',
        ] );

        return [
            'id'                 => $installment->id,
            'pos_sales_id'       => $installment->pos_sales_id,
            'installment_number' => $installment->installment_number,
            'amount'             => (float) $installment->amount,
            'paid_amount'        => (float) $installment->paid_amount,
            'remaining_amount'   => (float) $installment->remaining_amount,
            'due_date'           => optional( $installment->due_date )->format( 'Y-m-d' ),
            'status'             => $installment->display_status,
            'payments'           => $installment->payments->map( function ( InstallmentPayment $p ) {
                return [
                    'id'                 => $p->id,
                    'amount'             => (float) $p->amount,
                    'paid_at'            => optional( $p->paid_at )->toDateTimeString(),
                    'note'               => $p->note,
                    'payment_method'     => $p->paymentMethod,
                    'collected_by'       => $p->user,
                    'customer_payment_id'=> $p->customer_payment_id,
                ];
            } )->values(),
        ];
    }

    /**
     * Paginated installment orders for merchant management UI.
     */
    public static function index()
    {
        $vendorId = vendorId();

        return PosSales::where( 'vendor_id', $vendorId )
            ->whereHas( 'installments' )
            ->when( request()->filled( 'search' ), function ( $query ) {
                $search = request()->input( 'search' );
                $query->where( function ( $q ) use ( $search ) {
                    $q->where( 'barcode', 'like', '%' . $search . '%' );
                } );
            } )
            ->when( request( 'customer_id' ), function ( $q, $customerId ) {
                $q->where( 'customer_id', $customerId );
            } )
            ->when( request( 'payment_status' ), function ( $q, $status ) {
                $q->where( 'payment_status', $status );
            } )
            ->when( request( 'start_date' ) && request( 'end_date' ), function ( $q ) {
                return $q->whereBetween( 'sale_date', [request( 'start_date' ), request( 'end_date' )] );
            } )
            ->with( [
                'customer:id,customer_name,phone',
                'installments' => fn ( $q ) => $q->orderBy( 'installment_number' ),
            ] )
            ->select(
                'id', 'barcode', 'customer_id', 'sale_date', 'total_price',
                'paid_amount', 'due_amount', 'payment_status', 'note'
            )
            ->latest( 'id' )
            ->paginate( 10 )
            ->withQueryString();

        $paginator->getCollection()->transform( function ( PosSales $sale ) {
            $installments = $sale->installments;
            $next = $installments->first( fn ( Installment $i ) => (float) $i->remaining_amount > 0 );

            return [
                'id'                => $sale->id,
                'invoice'           => $sale->barcode,
                'sale_date'         => $sale->sale_date,
                'customer'          => $sale->customer,
                'total_price'       => (float) $sale->total_price,
                'paid_amount'       => (float) $sale->paid_amount,
                'due_amount'        => (float) $sale->due_amount,
                'payment_status'    => $sale->payment_status,
                'installment_count' => $installments->count(),
                'next_due_date'     => optional( $next?->due_date )->format( 'Y-m-d' ),
                'next_status'       => $next?->display_status,
                'installments'      => $installments->map( function ( Installment $i ) {
                    return [
                        'id'                 => $i->id,
                        'installment_number' => $i->installment_number,
                        'amount'             => (float) $i->amount,
                        'paid_amount'        => (float) $i->paid_amount,
                        'remaining_amount'   => (float) $i->remaining_amount,
                        'due_date'           => optional( $i->due_date )->format( 'Y-m-d' ),
                        'status'             => $i->display_status,
                    ];
                } )->values(),
            ];
        } );

        return $paginator;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function customerInstallmentSummary( int $customerId ): ?array
    {
        $vendorId = vendorId();
        $customer = Customer::where( 'id', $customerId )
            ->where( 'vendor_id', $vendorId )
            ->select( 'id', 'customer_name', 'phone', 'email', 'address' )
            ->first();

        if ( ! $customer ) {
            return null;
        }

        $sales = PosSales::where( 'vendor_id', $vendorId )
            ->where( 'customer_id', $customerId )
            ->whereHas( 'installments' )
            ->with( [
                'installments' => fn ( $q ) => $q->orderBy( 'installment_number' ),
                'installments.payments.paymentMethod:id,payment_method_name',
            ] )
            ->select( 'id', 'barcode', 'sale_date', 'total_price', 'paid_amount', 'due_amount', 'payment_status' )
            ->latest( 'id' )
            ->get();

        $allInstallments = $sales->flatMap->installments;
        $totalAmount     = round( (float) $allInstallments->sum( 'amount' ), 2 );
        $totalPaid       = round( (float) $allInstallments->sum( 'paid_amount' ), 2 );
        $totalRemaining  = round( (float) $allInstallments->sum( 'remaining_amount' ), 2 );
        $next            = $allInstallments
            ->filter( fn ( Installment $i ) => (float) $i->remaining_amount > 0 )
            ->sortBy( 'due_date' )
            ->first();

        $paymentHistory = InstallmentPayment::whereIn( 'pos_sales_id', $sales->pluck( 'id' ) )
            ->with( [
                'paymentMethod:id,payment_method_name',
                'user:id,name',
                'installment:id,installment_number,pos_sales_id',
                'posSale:id,barcode',
            ] )
            ->latest( 'id' )
            ->get();

        return [
            'customer' => $customer,
            'summary'  => [
                'total_installment_amount' => $totalAmount,
                'total_paid'               => $totalPaid,
                'total_remaining'          => $totalRemaining,
                'next_due_date'            => optional( $next?->due_date )->format( 'Y-m-d' ),
                'installment_status'       => $totalRemaining <= 0
                    ? 'paid'
                    : ( $totalPaid > 0 ? 'partial' : 'unpaid' ),
                'order_count'              => $sales->count(),
            ],
            'orders' => $sales->map( fn ( PosSales $sale ) => self::planSummary( $sale ) )->values(),
            'payment_history' => $paymentHistory,
        ];
    }
}
