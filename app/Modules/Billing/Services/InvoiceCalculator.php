<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Models\AccountBillingPeriod;
use App\Modules\Billing\Models\BillingAdjustment;
use App\Modules\Billing\Support\GstState;
use App\Modules\Billing\Support\Money;
use App\Modules\GoogleWorkspace\Models\GoogleAccount;
use App\Modules\Organisations\Models\Organisation;
use Carbon\CarbonImmutable;

/**
 * Works out what an organisation owes for a billing period. Reads only;
 * InvoiceService persists the result.
 *
 * Proration ("prorate_daily", the default): each line is
 *   round_half_up(monthly_price × billable_days ÷ days_in_period)
 * with start and end dates inclusive. A price change inside the period
 * splits the mailbox into one line per price. "full_month" charges the
 * whole price for any mailbox billable on at least one day.
 *
 * GST is one rate (18% by default), split and rounded the way Zoho Books
 * does it: within the supplier's state CGST and SGST are each half the rate
 * and each rounded half up per line (₹39.48 → 3.55 + 3.55 = 7.10, not 7.11);
 * otherwise IGST at the full rate, rounded per line. Lines are summed.
 */
class InvoiceCalculator
{
    /**
     * @return array{lines: list<array<string, mixed>>, adjustments: list<BillingAdjustment>, totals: array<string, int|string>}
     */
    public function calculate(Organisation $organisation, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $prices = PriceResolver::for($organisation);
        $daysInPeriod = (int) $start->diffInDays($end) + 1;
        $policy = (string) config('wcc.billing.proration_policy', 'prorate_daily');
        $rate = $this->taxRate($organisation);
        $treatment = $this->taxTreatment($organisation);
        $cgst = $sgst = $igst = 0;

        $periods = AccountBillingPeriod::query()
            ->where('organisation_id', $organisation->getKey())
            ->whereDate('starts_on', '<=', $end->toDateString())
            ->where(fn ($query) => $query->whereNull('ends_on')->orWhereDate('ends_on', '>=', $start->toDateString()))
            ->orderBy('google_account_id')->orderBy('starts_on')
            ->get()
            ->groupBy('google_account_id');

        $accounts = GoogleAccount::query()->whereIn('id', $periods->keys())->get()->keyBy('id');
        $lines = [];

        foreach ($periods as $accountId => $rows) {
            $account = $accounts[$accountId];

            // Which days of the period is this mailbox billable?
            $billable = [];

            foreach ($rows as $row) {
                $from = $row->starts_on->max($start);
                $to = ($row->ends_on ?? $end)->min($end);

                for ($day = CarbonImmutable::parse($from->toDateString()); $day->lte($to); $day = $day->addDay()) {
                    $billable[$day->toDateString()] = true;
                }
            }

            if ($billable === []) {
                continue;
            }

            ksort($billable);

            foreach ($this->segments(array_keys($billable), (int) $accountId, $prices, $account->primary_email) as $segment) {
                $days = $policy === 'full_month' ? $daysInPeriod : $segment['days'];
                $amount = Money::divideRound($segment['price'] * $days, $daysInPeriod);
                $split = GstState::lineTax($amount, $rate, $treatment);
                $tax = $split['tax'];
                $cgst += $split['cgst'];
                $sgst += $split['sgst'];
                $igst += $split['igst'];

                $lines[] = [
                    'type' => 'mailbox',
                    'google_account_id' => (int) $accountId,
                    'description' => 'Google Workspace mailbox',
                    'account_email' => $account->primary_email,
                    'account_name' => $account->fullName(),
                    'account_status' => $account->account_status->value,
                    'period_start' => $segment['from'],
                    'period_end' => $segment['to'],
                    'days' => $days,
                    'days_in_period' => $daysInPeriod,
                    'unit_price_minor' => $segment['price'],
                    'price_source' => $segment['source'],
                    'amount_minor' => $amount,
                    'tax_rate_bp' => $rate,
                    'tax_minor' => $tax,
                    'total_minor' => $amount + $tax,
                ];

                if ($policy === 'full_month') {
                    break; // one full-price line per mailbox
                }
            }
        }

        usort($lines, fn (array $a, array $b) => [$a['account_email'], $a['period_start']] <=> [$b['account_email'], $b['period_start']]);

        $subtotal = array_sum(array_column($lines, 'amount_minor'));
        $tax = array_sum(array_column($lines, 'tax_minor'));

        // Approved adjustments. A credit is applied only while the invoice
        // stays at or above zero; otherwise it waits for the next cycle.
        $applied = [];
        $adjustmentsTotal = 0;
        $running = $subtotal;

        foreach (BillingAdjustment::query()->where('organisation_id', $organisation->getKey())
            ->where('status', 'approved')->whereNull('invoice_id')->orderBy('id')->get() as $adjustment) {
            $signed = $adjustment->signedAmount();

            if ($running + $signed < 0) {
                continue;
            }

            $split = GstState::lineTax($signed, $adjustment->taxable ? $rate : 0, $treatment);
            $lineTax = $split['tax'];
            $cgst += $split['cgst'];
            $sgst += $split['sgst'];
            $igst += $split['igst'];

            $lines[] = [
                'type' => 'adjustment',
                'billing_adjustment_id' => $adjustment->getKey(),
                'description' => ($adjustment->type === 'credit' ? 'Credit: ' : 'Charge: ').$adjustment->description,
                'amount_minor' => $signed,
                'tax_rate_bp' => $adjustment->taxable ? $rate : 0,
                'tax_minor' => $lineTax,
                'total_minor' => $signed + $lineTax,
            ];

            $applied[] = $adjustment;
            $adjustmentsTotal += $signed;
            $tax += $lineTax;
            $running += $signed;
        }

        return [
            'lines' => $lines,
            'adjustments' => $applied,
            'totals' => [
                'subtotal_minor' => $subtotal,
                'adjustments_minor' => $adjustmentsTotal,
                'taxable_minor' => $subtotal + $adjustmentsTotal,
                'tax_rate_bp' => $rate,
                'tax_treatment' => $treatment,
                'cgst_minor' => $cgst,
                'sgst_minor' => $sgst,
                'igst_minor' => $igst,
                'tax_minor' => $tax,
                'total_minor' => $subtotal + $adjustmentsTotal + $tax,
            ],
        ];
    }

    /** One GST rate for every organisation (BILLING_DEFAULT_TAX_RATE, 18%). */
    public function taxRate(Organisation $organisation): int
    {
        return Money::rateToBasisPoints((string) config('wcc.billing.default_tax_rate'));
    }

    /**
     * intra_state (CGST + SGST) or inter_state (IGST), from the customer's
     * GSTIN or state against BILLING_SUPPLIER_STATE_CODE — the same place-of-
     * supply rule Zoho Books applies, so both systems arrive at the same tax.
     */
    public function taxTreatment(Organisation $organisation): string
    {
        $supplier = config('wcc.billing.supplier_state_code');

        return GstState::treatment(
            $supplier === null ? null : (string) $supplier,
            GstState::code($organisation->gstin, $organisation->state),
            $organisation->country,
        );
    }

    /**
     * Consecutive billable days at the same price become one line.
     *
     * @param  list<string>  $days  sorted Y-m-d
     * @return list<array{from: string, to: string, days: int, price: int, source: string}>
     */
    private function segments(array $days, int $accountId, PriceResolver $prices, string $email): array
    {
        $segments = [];
        $current = null;
        $previous = null;

        foreach ($days as $day) {
            $price = $prices->resolve($accountId, $day)
                ?? throw new BillingException("No price is configured for {$email} on {$day}. Set an organisation price or a platform default.");

            $contiguous = $previous !== null && CarbonImmutable::parse($previous)->addDay()->toDateString() === $day;

            if ($current !== null && $contiguous && $current['price'] === $price['price'] && $current['source'] === $price['source']) {
                $current['to'] = $day;
                $current['days']++;
            } else {
                if ($current !== null) {
                    $segments[] = $current;
                }

                $current = ['from' => $day, 'to' => $day, 'days' => 1, 'price' => $price['price'], 'source' => $price['source']];
            }

            $previous = $day;
        }

        if ($current !== null) {
            $segments[] = $current;
        }

        return $segments;
    }
}
