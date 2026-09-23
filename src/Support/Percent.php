<?php

namespace Goldnead\Affiliates\Support;

final class Percent
{
    /**
     * A percentage without trailing zeros, as stored: "30", "12.5".
     *
     * number_format first: a decimal column comes back as "30.00" from MySQL
     * and as "30" from SQLite, and trimming zeros off the second turns 30
     * into 3.
     */
    public static function plain(mixed $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
    }

    /** For display: "30 %", "12,5 %" in German, "12.5 %" otherwise. */
    public static function format(mixed $value, ?string $locale = null): string
    {
        $locale ??= app()->getLocale();
        $plain = self::plain($value);

        return (str_starts_with($locale, 'de') ? str_replace('.', ',', $plain) : $plain).' %';
    }
}
