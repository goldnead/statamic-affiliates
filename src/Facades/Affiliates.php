<?php

namespace Goldnead\Affiliates\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Goldnead\Affiliates\Models\Partner|null partnerFor(\Statamic\Contracts\Auth\User|null $user)
 * @method static \Goldnead\Affiliates\Models\Partner apply(\Statamic\Contracts\Auth\User $user, array $data)
 * @method static \Goldnead\Affiliates\Models\Partner invite(\Goldnead\Affiliates\Models\Partner $partner)
 * @method static \Goldnead\Affiliates\Models\Partner approve(\Goldnead\Affiliates\Models\Partner $partner)
 * @method static string link(\Goldnead\Affiliates\Models\Partner $partner, ?string $url = null)
 * @method static array stats(\Goldnead\Affiliates\Models\Partner $partner)
 *
 * @see \Goldnead\Affiliates\Affiliates
 */
class Affiliates extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Goldnead\Affiliates\Affiliates::class;
    }
}
