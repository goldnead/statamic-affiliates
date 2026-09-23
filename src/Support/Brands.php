<?php

namespace Goldnead\Affiliates\Support;

use Closure;
use Goldnead\BrandContext\BrandManager;
use Throwable;

/**
 * The seam to statamic-brand-context, which this addon requires.
 *
 * Two things happen outside any request that names a brand: a payment
 * webhook, and a console command. There, the brand scope on the models would
 * fail closed and find nothing. The rows that matter carry their brand, so
 * work on them runs *in* that brand ({@see self::runFor()}), and lookups by a
 * globally unique key (a link code, a payment id) run across brands.
 */
final class Brands
{
    public static function manager(): ?BrandManager
    {
        try {
            $manager = app('brand-context');
        } catch (Throwable) {
            return null;
        }

        return $manager instanceof BrandManager ? $manager : null;
    }

    public static function multi(): bool
    {
        try {
            return (bool) self::manager()?->multiBrandEnabled();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Run `$callback` with `$brandId` current, so config reads see that
     * brand's settings. A no-op on a single-brand install.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function runFor(?int $brandId, Closure $callback): mixed
    {
        $manager = self::manager();

        if ($manager === null || ! $brandId || ! self::multi()) {
            return $callback();
        }

        return $manager->runFor($brandId, $callback);
    }

    /**
     * Whether a partner of `$partnerBrand` may earn on a payment stamped
     * `$paymentBrand`. statamic-payments writes 0 on a single-brand install,
     * brand-context the default brand's id, so the two are only compared when
     * there really are several brands.
     */
    public static function same(int $partnerBrand, int $paymentBrand): bool
    {
        return ! self::multi() || $partnerBrand === $paymentBrand;
    }
}
