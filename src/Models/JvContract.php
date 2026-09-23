<?php

namespace Goldnead\Affiliates\Models;

use Goldnead\BrandContext\Concerns\HasBrand;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A joint venture: a fixed share of every sale of the named products, with
 * no link and no cookie. Settled like a commission, listed on its own.
 *
 * @property int $id
 * @property int $brand_id
 * @property int $partner_id
 * @property string $name
 * @property list<string>|null $products
 * @property string $percent
 * @property string|null $bump_percent
 * @property string|null $upsell_percent
 * @property bool $recurring
 * @property Carbon|null $starts_on
 * @property Carbon|null $ends_on
 * @property bool $active
 */
class JvContract extends Model
{
    use HasBrand;

    protected $table = 'affiliate_jv_contracts';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'products' => 'array',
            'recurring' => 'boolean',
            'active' => 'boolean',
            'starts_on' => 'date',
            'ends_on' => 'date',
        ];
    }

    /** @return BelongsTo<Partner, $this> */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'partner_id');
    }

    /** Whether a product sold at `$at` falls under this contract. */
    public function covers(string $product, Carbon $at): bool
    {
        if (! $this->active) {
            return false;
        }

        if ($this->starts_on !== null && $at->lt($this->starts_on->copy()->startOfDay())) {
            return false;
        }

        if ($this->ends_on !== null && $at->gt($this->ends_on->copy()->endOfDay())) {
            return false;
        }

        $products = array_values(array_filter($this->products ?? [], fn ($p) => is_string($p) && $p !== ''));

        return $products === [] || in_array($product, $products, true);
    }
}
