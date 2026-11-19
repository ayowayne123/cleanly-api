<?php

namespace App\Services;

use App\Exceptions\FinanceException;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class PaymentService
{
    // card = paid on a POS machine
    private const MANUAL_METHODS = ['cash_on_arrival', 'bank_transfer', 'card'];

    /**
     * Record money received outside Paystack (pay on arrival, bank transfer, POS).
     * The amount must equal the booking total. It can be recorded after the job is done.
     *
     * @throws FinanceException
     */
    public function recordManualPayment(
        Booking|int $booking,
        string $method,
        ?int $amountK = null,
        ?User $by = null,
        ?string $reference = null,
        ?CarbonInterface $paidAt = null,
    ): Payment {
        if (! in_array($method, self::MANUAL_METHODS, true)) {
            throw new FinanceException('Please choose how the client paid.');
        }

        $paidAt ??= now();

        if ($paidAt->isFuture()) {
            throw new FinanceException('The payment date cannot be in the future.');
        }

        $id = $booking instanceof Booking ? $booking->id : $booking;

        return DB::transaction(function () use ($id, $method, $amountK, $by, $reference, $paidAt) {
            $booking = Booking::whereKey($id)->lockForUpdate()->firstOrFail();

            if ($booking->subscription_id) {
                throw new FinanceException('Subscription visits are paid through the subscription.');
            }

            if (in_array($booking->status, ['cancelled', 'expired'], true)) {
                throw new FinanceException('This booking was cancelled or expired.');
            }

            if ($booking->payment_status !== 'unpaid') {
                throw new FinanceException('This booking is not waiting for payment.');
            }

            if ((int) $booking->total_k <= 0) {
                throw new FinanceException('There is nothing to pay on this booking.');
            }

            $amountK ??= (int) $booking->total_k;

            if ($amountK !== (int) $booking->total_k) {
                throw new FinanceException('The amount must match the booking total (₦'.number_format($booking->total_k / 100).').');
            }

            $payment = Payment::create([
                'booking_id' => $booking->id,
                'client_id' => $booking->client_id,
                'purpose' => 'booking',
                'provider' => 'manual',
                'method' => $method,
                'provider_reference' => $reference,   // e.g. the bank transfer reference
                'amount_k' => $amountK,
                'status' => 'success',
                'paid_at' => $paidAt,
            ]);

            $booking->update(['payment_status' => 'paid']);

            // an online booking that was waiting for payment now joins the admin queue
            if ($booking->status === 'pending_payment') {
                $booking->changeStatus('awaiting_assignment', $by, 'Payment recorded');
            }

            AuditLog::record('payment.recorded', $payment, null, [
                'booking' => $booking->reference,
                'method' => $method,
                'amount_k' => $amountK,
            ], $by);

            return $payment;
        });
    }

    /**
     * Record a refund that has been paid back to the client (cash or transfer).
     * Online (Paystack) payments are refunded through Paystack instead.
     *
     * @throws FinanceException
     */
    public function recordRefund(Payment|int $payment, int $amountK, ?string $reason = null, ?User $by = null): Refund
    {
        $id = $payment instanceof Payment ? $payment->id : $payment;

        return DB::transaction(function () use ($id, $amountK, $reason, $by) {
            $payment = Payment::whereKey($id)->lockForUpdate()->firstOrFail();

            if ($payment->provider !== 'manual') {
                throw new FinanceException('Online payments are refunded through Paystack, which is not connected yet.');
            }

            if (! $payment->isSuccessful()) {
                throw new FinanceException('Only a successful payment can be refunded.');
            }

            if ($amountK <= 0) {
                throw new FinanceException('The refund amount must be more than zero.');
            }

            $refundable = (int) $payment->amount_k - $payment->refundedTotalK();

            if ($amountK > $refundable) {
                throw new FinanceException('Only ₦'.number_format($refundable / 100).' can still be refunded on that payment.');
            }

            $refund = $payment->refunds()->create([
                'amount_k' => $amountK,
                'reason' => $reason,
                'status' => 'processed',
                'processed_by_user_id' => $by?->id,
            ]);

            if ($payment->booking_id) {
                $booking = Booking::whereKey($payment->booking_id)->lockForUpdate()->first();

                if ($booking && in_array($booking->payment_status, ['paid', 'partially_refunded'], true)) {
                    $booking->update([
                        'payment_status' => $payment->refundedTotalK() >= (int) $payment->amount_k
                            ? 'refunded'
                            : 'partially_refunded',
                    ]);
                }
            }

            AuditLog::record('payment.refunded', $refund, null, [
                'payment_id' => $payment->id,
                'amount_k' => $amountK,
                'reason' => $reason,
            ], $by);

            return $refund;
        });
    }
}