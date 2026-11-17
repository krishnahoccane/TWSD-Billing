<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Models\AccountPriceOverride;
use App\Modules\Billing\Models\OrganisationPrice;
use App\Modules\Billing\Models\PricingPlan;
use App\Modules\Organisations\Models\Organisation;

/**
 * The monthly price of a mailbox on a given day.
 *
 * Precedence: 1. account override  2. organisation price  3. platform default.
 * Within a level the row that started most recently and covers the day wins.
 * Prices are loaded once per organisation and resolved in memory.
 */
class PriceResolver
{
    public const SERVICE = 'google_workspace_email';

    /** @var array<int, list<array{from: string, until: ?string, price: int}>> keyed by account id */
    private array $overrides = [];

    /** @var list<array{from: string, until: ?string, price: int}> */
    private array $organisation = [];

    /** @var list<array{from: string, until: ?string, price: int}> */
    private array $platform = [];

    public static function for(Organisation $organisation): self
    {
        $resolver = new self;

        foreach (AccountPriceOverride::query()->where('organisation_id', $organisation->getKey())->where('status', 'active')->get() as $row) {
            $resolver->overrides[$row->google_account_id][] = self::row($row);
        }

        foreach (OrganisationPrice::query()->where('organisation_id', $organisation->getKey())
            ->where('service_type', self::SERVICE)->where('status', 'active')->get() as $row) {
            $resolver->organisation[] = self::row($row);
        }

        foreach (PricingPlan::query()->where('service_type', self::SERVICE)->where('status', 'active')->get() as $row) {
            $resolver->platform[] = self::row($row);
        }

        return $resolver;
    }

    /**
     * @return array{price: int, source: string}|null null when no price covers that day
     */
    public function resolve(int $accountId, string $date): ?array
    {
        foreach ([
            'account' => $this->overrides[$accountId] ?? [],
            'organisation' => $this->organisation,
            'platform' => $this->platform,
        ] as $source => $rows) {
            $price = $this->match($rows, $date);

            if ($price !== null) {
                return ['price' => $price, 'source' => $source];
            }
        }

        return null;
    }

    /**
     * @param  list<array{from: string, until: ?string, price: int}>  $rows
     */
    private function match(array $rows, string $date): ?int
    {
        $best = null;

        foreach ($rows as $row) {
            if ($row['from'] <= $date && ($row['until'] === null || $row['until'] >= $date)
                && ($best === null || $row['from'] > $best['from'])) {
                $best = $row;
            }
        }

        return $best['price'] ?? null;
    }

    /**
     * @return array{from: string, until: ?string, price: int}
     */
    private static function row($model): array
    {
        return [
            'from' => $model->effective_from->toDateString(),
            'until' => $model->effective_until?->toDateString(),
            'price' => (int) $model->monthly_price_minor,
        ];
    }
}
