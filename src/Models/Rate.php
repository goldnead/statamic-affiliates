<?php

namespace Goldnead\Affiliates\Models;

use Goldnead\BrandContext\Concerns\HasBrand;
use Illuminate\Database\Eloquent\Model;

/**
 * The commission for one product.
 *
 * @property int $id
 * @property int $brand_id
 * @property string $product
 * @property string $type
 * @property string|null $percent
 * @property int|null $amount_cent
 * @property string $recurring
 * @property int|null $recurring_times
 * @property string|null $recurring_percent
 * @property bool $bumps
 * @property string|null $bump_percent
 * @property bool $upsells
 * @property bool $active
 */
class Rate extends Model
{
    use HasBrand;

    public const TYPE_PERCENT = 'percent';

    public const TYPE_FIXED = 'fixed';

    public const RECURRING_NONE = 'none';

    public const RECURRING_LIMITED = 'limited';

    public const RECURRING_ALWAYS = 'always';

    protected $table = 'affiliate_rates';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount_cent' => 'integer',
            'recurring_times' => 'integer',
            'bumps' => 'boolean',
            'upsells' => 'boolean',
            'active' => 'boolean',
        ];
    }
}
