<?php

namespace Goldnead\Affiliates\Support;

use NumberFormatter;

final class Money
{
    /** Minor units as a localised amount with currency, e.g. "29,70 €". */
    public static function format(int $cent, string $currency, ?string $locale = null): string
    {
        $locale ??= str_replace('_', '-', app()->getLocale());

        if (class_exists(NumberFormatter::class)) {
            $formatter = new NumberFormatter($locale, NumberFormatter::CURRENCY);
            $formatted = $formatter->formatCurrency($cent / 100, mb_strtoupper($currency));

            if ($formatted !== false) {
                return $formatted;
            }
        }

        return number_format($cent / 100, 2, ',', '.').' '.mb_strtoupper($currency);
    }

    /**
     * Minor units as a plain decimal, for CSV: "29,70" in German, "29.70"
     * otherwise. A German spreadsheet reads "29.70" as a date or as text.
     */
    public static function decimal(int $cent, ?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        return number_format($cent / 100, 2, str_starts_with($locale, 'de') ? ',' : '.', '');
    }
}
