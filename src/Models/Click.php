<?php

namespace Goldnead\Affiliates\Models;

use Goldnead\BrandContext\Concerns\HasBrand;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One visit through a partner's link. Counted once per visit, path only.
 *
 * @property int $id
 * @property int $brand_id
 * @property int $partner_id
 * @property string|null $landing
 * @property Carbon|null $created_at
 */
class Click extends Model
{
    use HasBrand;

    public const UPDATED_AT = null;

    protected $table = 'affiliate_clicks';

    protected $guarded = [];
}
