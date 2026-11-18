<?php

namespace App\Modules\Billing\Support;

/**
 * Indian GST state codes (the first two digits of a GSTIN) and the
 * intra-state / inter-state decision that picks CGST + SGST or IGST.
 */
final class GstState
{
    /** @var array<string, string> code => name */
    public const STATES = [
        '01' => 'Jammu and Kashmir', '02' => 'Himachal Pradesh', '03' => 'Punjab', '04' => 'Chandigarh',
        '05' => 'Uttarakhand', '06' => 'Haryana', '07' => 'Delhi', '08' => 'Rajasthan', '09' => 'Uttar Pradesh',
        '10' => 'Bihar', '11' => 'Sikkim', '12' => 'Arunachal Pradesh', '13' => 'Nagaland', '14' => 'Manipur',
        '15' => 'Mizoram', '16' => 'Tripura', '17' => 'Meghalaya', '18' => 'Assam', '19' => 'West Bengal',
        '20' => 'Jharkhand', '21' => 'Odisha', '22' => 'Chhattisgarh', '23' => 'Madhya Pradesh', '24' => 'Gujarat',
        '26' => 'Dadra and Nagar Haveli and Daman and Diu', '27' => 'Maharashtra', '29' => 'Karnataka', '30' => 'Goa',
        '31' => 'Lakshadweep', '32' => 'Kerala', '33' => 'Tamil Nadu', '34' => 'Puducherry',
        '35' => 'Andaman and Nicobar Islands', '36' => 'Telangana', '37' => 'Andhra Pradesh', '38' => 'Ladakh',
        '97' => 'Other Territory',
    ];

    /** Other spellings seen in addresses. */
    private const ALIASES = [
        'new delhi' => '07', 'nct of delhi' => '07', 'national capital territory of delhi' => '07',
        'orissa' => '21', 'pondicherry' => '34', 'daman and diu' => '26', 'dadra and nagar haveli' => '26',
        'andaman and nicobar' => '35', 'jammu & kashmir' => '01', 'j&k' => '01',
    ];

    public const INTRA = 'intra_state';

    public const INTER = 'inter_state';

    /** State code from a GSTIN (preferred) or a state name; null when neither says. */
    public static function code(?string $gstin, ?string $stateName = null): ?string
    {
        if ($gstin !== null && preg_match('/^(\d{2})[A-Z0-9]{13}$/i', trim($gstin), $m) && isset(self::STATES[$m[1]])) {
            return $m[1];
        }

        $name = strtolower(trim(preg_replace('/\s+/', ' ', (string) $stateName)));

        if ($name === '') {
            return null;
        }

        if (preg_match('/^\d{1,2}$/', $name) && isset(self::STATES[str_pad($name, 2, '0', STR_PAD_LEFT)])) {
            return str_pad($name, 2, '0', STR_PAD_LEFT);
        }

        foreach (self::STATES as $code => $state) {
            if (strtolower($state) === $name) {
                return $code;
            }
        }

        return self::ALIASES[str_replace(' & ', ' and ', $name)] ?? self::ALIASES[$name] ?? null;
    }

    /**
     * CGST + SGST when the customer is in the supplier's state, IGST
     * otherwise. When either side is unknown Zoho Books falls back to the
     * organisation's own state, i.e. intra-state, and so do we.
     */
    public static function treatment(?string $supplierCode, ?string $customerCode, ?string $country = 'IN'): string
    {
        if ($country !== null && $country !== '' && strtoupper($country) !== 'IN') {
            return self::INTER;
        }

        if ($supplierCode === null || $supplierCode === '' || $customerCode === null) {
            return self::INTRA;
        }

        return str_pad($supplierCode, 2, '0', STR_PAD_LEFT) === $customerCode ? self::INTRA : self::INTER;
    }

    /**
     * GST on one line, rounded the way Zoho Books shows it: intra-state is
     * two taxes of half the rate, each rounded half up on the line; inter-
     * state is one IGST amount rounded half up.
     *
     * @return array{cgst: int, sgst: int, igst: int, tax: int}
     */
    public static function lineTax(int $amountMinor, int $rateBp, string $treatment): array
    {
        if ($rateBp === 0 || $amountMinor === 0) {
            return ['cgst' => 0, 'sgst' => 0, 'igst' => 0, 'tax' => 0];
        }

        if ($treatment === self::INTRA) {
            $half = Money::divideRound($amountMinor * $rateBp, 20000);

            return ['cgst' => $half, 'sgst' => $half, 'igst' => 0, 'tax' => 2 * $half];
        }

        $igst = Money::divideRound($amountMinor * $rateBp, 10000);

        return ['cgst' => 0, 'sgst' => 0, 'igst' => $igst, 'tax' => $igst];
    }
}
