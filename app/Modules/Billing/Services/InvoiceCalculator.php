<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Models\AccountBillingPeriod;
use App\Modules\Billing\Models\BillingAdjustment;
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
 * GST is one rate (18% by default), calculated per line (round half up)
 * and summed. Zoho Books splits it into CGST/SGST or IGST.
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
                $tax = Money::divideRound($amount * $rate, 10000);

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

            $lineTax = $adjustment->taxable ? Money::divideRound($signed * $rate, 10000) : 0;

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
                'tax_treatment' => 'gst',
                'cgst_minor' => 0,
                'sgst_minor' => 0,
                'igst_minor' => 0,
                'tax_minor' => $tax,
                'total_minor' => $subtotal + $adjustmentsTotal + $tax,
            ],
        ];
    }

    /**
     * One GST rate for every organisation (BILLING_DEFAULT_TAX_RATE, 18%).
     * The CGST/SGST/IGST split is made by Zoho Books from the customer's
     * place of supply; this application shows a single GST figure.
     */
    public function taxRate(Organisation $organisation): int
    {
        return Money::rateToBasisPoints((string) config('wcc.billing.default_tax_rate'));
    }

    /** Always a single GST figure here (decision D-09). */
    public function taxTreatment(Organisation $organisation): string
    {
        return 'gst';
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
