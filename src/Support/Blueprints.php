<?php

namespace Goldnead\Affiliates\Support;

use Goldnead\Affiliates\Models\JvContract;
use Goldnead\Affiliates\Models\Partner;
use Goldnead\Affiliates\Models\Rate;
use Statamic\Facades\Blueprint;
use Statamic\Fields\Blueprint as BlueprintInstance;
use Throwable;

/**
 * The blueprints behind the Control Panel forms, rendered by core's
 * `PublishForm`. Built in PHP because the product list comes from
 * statamic-payments' catalogue when it is installed.
 */
class Blueprints
{
    public static function partner(bool $creating, bool $payouts = true): BlueprintInstance
    {
        // Without `manage affiliate payouts` the IBAN is not in the form at
        // all: a masked, read-only line says that something is on file.
        $details = $payouts
            ? self::field('payout_details', ['type' => 'textarea', 'validate' => ['nullable', 'max:500']])
            : self::field('payout_details_masked', ['type' => 'text', 'read_only' => true, 'display' => __('affiliates::cp.field_payout_details'), 'instructions' => __('affiliates::cp.payout_details_hidden')]);

        $main = [
            self::field('name', ['type' => 'text', 'validate' => ['required', 'max:191'], 'width' => 50]),
            self::field('email', ['type' => 'text', 'input_type' => 'email', 'validate' => ['required', 'email', 'max:191'], 'width' => 50]),
            self::field('code', ['type' => 'slug', 'validate' => ['nullable', 'max:64', 'alpha_dash'], 'width' => 50]),
            self::field('status', [
                'type' => 'select',
                'options' => self::options('partner', Partner::statuses()),
                'default' => Partner::STATUS_ACTIVE,
                'validate' => ['required'],
                'width' => 50,
            ]),
        ];

        if ($creating) {
            $main[] = self::field('send_invitation', ['type' => 'toggle', 'default' => true]);
        }

        return Blueprint::make()->setContents(['tabs' => [
            'main' => [
                'display' => __('affiliates::cp.tab_partner'),
                'sections' => [
                    ['display' => __('affiliates::cp.section_partner'), 'fields' => $main],
                    ['display' => __('affiliates::cp.section_commission'), 'fields' => [
                        self::field('commission_percent', ['type' => 'float', 'validate' => ['nullable', 'numeric', 'min:0', 'max:100'], 'width' => 50]),
                        self::field('coupon_codes', ['type' => 'list', 'width' => 50]),
                        self::field('notify', ['type' => 'toggle', 'default' => true]),
                    ]],
                    ['display' => __('affiliates::cp.section_payout'), 'fields' => [
                        self::field('payout_method', [
                            'type' => 'select',
                            'options' => self::options('method', ['bank', 'paypal', 'other']),
                            'clearable' => true,
                            'width' => 50,
                        ]),
                        $details,
                        self::field('notes', ['type' => 'textarea', 'validate' => ['nullable', 'max:2000']]),
                    ]],
                ],
            ],
        ]]);
    }

    public static function rate(): BlueprintInstance
    {
        return Blueprint::make()->setContents(['tabs' => [
            'main' => [
                'display' => __('affiliates::cp.tab_rate'),
                'sections' => [
                    ['display' => __('affiliates::cp.section_rate'), 'fields' => [
                        self::productField('product', false),
                        self::field('type', [
                            'type' => 'button_group',
                            'options' => self::options('type', [Rate::TYPE_PERCENT, Rate::TYPE_FIXED]),
                            'default' => Rate::TYPE_PERCENT,
                            'width' => 50,
                        ]),
                        self::field('active', ['type' => 'toggle', 'default' => true, 'width' => 50]),
                        self::field('percent', ['type' => 'float', 'validate' => ['nullable', 'numeric', 'min:0', 'max:100', 'required_if:type,percent'], 'if' => ['type' => 'equals percent'], 'width' => 50]),
                        self::field('amount', ['type' => 'float', 'validate' => ['nullable', 'numeric', 'min:0', 'required_if:type,fixed'], 'if' => ['type' => 'equals fixed'], 'width' => 50]),
                    ]],
                    ['display' => __('affiliates::cp.section_recurring'), 'fields' => [
                        self::field('recurring', [
                            'type' => 'select',
                            'options' => self::options('recurring', [Rate::RECURRING_NONE, Rate::RECURRING_LIMITED, Rate::RECURRING_ALWAYS]),
                            'default' => Rate::RECURRING_NONE,
                            'width' => 50,
                        ]),
                        self::field('recurring_times', ['type' => 'integer', 'validate' => ['nullable', 'integer', 'min:1', 'max:120'], 'if' => ['recurring' => 'equals limited'], 'width' => 25]),
                        self::field('recurring_percent', ['type' => 'float', 'validate' => ['nullable', 'numeric', 'min:0', 'max:100'], 'unless' => ['recurring' => 'equals none'], 'width' => 25]),
                    ]],
                    ['display' => __('affiliates::cp.section_bumps'), 'fields' => [
                        self::field('bumps', ['type' => 'toggle', 'width' => 50]),
                        self::field('bump_percent', ['type' => 'float', 'validate' => ['nullable', 'numeric', 'min:0', 'max:100'], 'if' => ['bumps' => 'equals true'], 'width' => 50]),
                        self::field('upsells', ['type' => 'toggle', 'width' => 50]),
                    ]],
                ],
            ],
        ]]);
    }

    public static function jv(): BlueprintInstance
    {
        $partners = Partner::query()
            ->whereIn('status', [Partner::STATUS_ACTIVE, Partner::STATUS_PENDING])
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Partner $p) => [(string) $p->id => $p->name.' ('.$p->email.')'])
            ->all();

        return Blueprint::make()->setContents(['tabs' => [
            'main' => [
                'display' => __('affiliates::cp.tab_jv'),
                'sections' => [
                    ['display' => __('affiliates::cp.section_jv'), 'fields' => [
                        self::field('name', ['type' => 'text', 'validate' => ['required', 'max:191'], 'width' => 50]),
                        self::field('partner_id', ['type' => 'select', 'options' => $partners, 'validate' => ['required'], 'width' => 50]),
                        self::productField('products', true),
                        self::field('active', ['type' => 'toggle', 'default' => true, 'width' => 50]),
                        self::field('recurring', ['type' => 'toggle', 'default' => true, 'width' => 50, 'display' => __('affiliates::cp.field_jv_recurring')]),
                    ]],
                    ['display' => __('affiliates::cp.section_jv_share'), 'fields' => [
                        self::field('percent', ['type' => 'float', 'validate' => ['required', 'numeric', 'min:0', 'max:100'], 'width' => 33]),
                        self::field('bump_percent', ['type' => 'float', 'validate' => ['nullable', 'numeric', 'min:0', 'max:100'], 'width' => 33]),
                        self::field('upsell_percent', ['type' => 'float', 'validate' => ['nullable', 'numeric', 'min:0', 'max:100'], 'width' => 33]),
                        // `format` without a time: the value travels as Y-m-d,
                        // a contract runs from a day to a day.
                        self::field('starts_on', ['type' => 'date', 'format' => 'Y-m-d', 'width' => 50]),
                        self::field('ends_on', ['type' => 'date', 'format' => 'Y-m-d', 'width' => 50]),
                    ]],
                ],
            ],
        ]]);
    }

    /** @return array<string, mixed> */
    public static function partnerValues(Partner $partner, bool $payouts = true): array
    {
        $values = self::partnerFields($partner);

        if (! $payouts) {
            unset($values['payout_details']);
            $values['payout_details_masked'] = self::mask($partner->payout_details);
        }

        return $values;
    }

    /** "•••• 2051": that something is on file, and which, without the rest. */
    public static function mask(?string $details): ?string
    {
        $plain = preg_replace('/\s+/', '', (string) $details) ?? '';

        return $plain === '' ? null : '•••• '.mb_substr($plain, -4);
    }

    /** @return array<string, mixed> */
    protected static function partnerFields(Partner $partner): array
    {
        return [
            'name' => $partner->name,
            'email' => $partner->email,
            'code' => $partner->code,
            'status' => $partner->status,
            'commission_percent' => $partner->commission_percent !== null ? (float) $partner->commission_percent : null,
            'coupon_codes' => $partner->couponCodes(),
            'notify' => $partner->notify,
            'payout_method' => $partner->payout_method,
            'payout_details' => $partner->payout_details,
            'notes' => $partner->notes,
        ];
    }

    /** @return array<string, mixed> */
    public static function rateValues(Rate $rate): array
    {
        return [
            'product' => $rate->product,
            'type' => $rate->type,
            'active' => $rate->active,
            'percent' => $rate->percent !== null ? (float) $rate->percent : null,
            'amount' => $rate->amount_cent !== null ? $rate->amount_cent / 100 : null,
            'recurring' => $rate->recurring,
            'recurring_times' => $rate->recurring_times,
            'recurring_percent' => $rate->recurring_percent !== null ? (float) $rate->recurring_percent : null,
            'bumps' => $rate->bumps,
            'bump_percent' => $rate->bump_percent !== null ? (float) $rate->bump_percent : null,
            'upsells' => $rate->upsells,
        ];
    }

    /** @return array<string, mixed> */
    public static function jvValues(JvContract $contract): array
    {
        return [
            'name' => $contract->name,
            'partner_id' => (string) $contract->partner_id,
            'products' => $contract->products ?? [],
            'active' => $contract->active,
            'recurring' => $contract->recurring,
            'percent' => (float) $contract->percent,
            'bump_percent' => $contract->bump_percent !== null ? (float) $contract->bump_percent : null,
            'upsell_percent' => $contract->upsell_percent !== null ? (float) $contract->upsell_percent : null,
            'starts_on' => $contract->starts_on?->format('Y-m-d'),
            'ends_on' => $contract->ends_on?->format('Y-m-d'),
        ];
    }

    /**
     * The products statamic-payments knows, by handle. Empty without it; the
     * field then takes any handle typed in.
     *
     * @return array<string, string>
     */
    public static function products(): array
    {
        $catalogue = '\Goldnead\StatamicPayments\Support\Catalogue';

        if (! class_exists($catalogue)) {
            return [];
        }

        try {
            $all = app($catalogue)->all();
        } catch (Throwable) {
            return [];
        }

        $options = [];

        foreach ($all as $handle => $product) {
            $name = is_array($product) && is_string($product['name'] ?? null) ? $product['name'] : $handle;
            $options[(string) $handle] = $name === $handle ? (string) $handle : $name.' ('.$handle.')';
        }

        return $options;
    }

    /** @return array<string, mixed> */
    protected static function productField(string $handle, bool $multiple): array
    {
        return self::field($handle, [
            'type' => 'select',
            'options' => self::products(),
            'taggable' => true,
            'multiple' => $multiple,
            'clearable' => $multiple,
            'validate' => $multiple ? ['nullable', 'array'] : ['required'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{handle: string, field: array<string, mixed>}
     */
    protected static function field(string $handle, array $config): array
    {
        $instructions = __('affiliates::cp.field_'.$handle.'_instructions');

        return [
            'handle' => $handle,
            'field' => [
                'display' => __('affiliates::cp.field_'.$handle),
                'instructions' => $instructions === 'affiliates::cp.field_'.$handle.'_instructions' ? null : $instructions,
                ...$config,
            ],
        ];
    }

    /**
     * @param  list<string>  $values
     * @return array<string, string>
     */
    protected static function options(string $group, array $values): array
    {
        $options = [];

        foreach ($values as $value) {
            $options[$value] = __('affiliates::cp.'.$group.'_'.$value);
        }

        return $options;
    }
}
