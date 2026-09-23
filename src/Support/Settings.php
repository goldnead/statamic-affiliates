<?php

namespace Goldnead\Affiliates\Support;

use Goldnead\BrandContext\Contracts\DescribesSettingsScreen;
use Goldnead\BrandContext\Contracts\ProvidesSettings;

/**
 * What an operator may change per brand, through statamic-brand-context's
 * shared settings screen. Only overrides are stored; everything unset keeps
 * following config/affiliates.php.
 *
 * **Not here, on purpose:** `routes.*` and `cp.enabled` are read while routes
 * and nav are registered, before the layer applies its values.
 * `tracking.cookie_name` and `tracking.parameter` are the site's contract with
 * links already handed out; changing them from a form would break every one.
 */
class Settings implements DescribesSettingsScreen, ProvidesSettings
{
    public static function settingsNamespace(): string
    {
        return 'affiliates';
    }

    public static function settingsConfigPath(): string
    {
        return 'affiliates';
    }

    public static function settingsPermission(): string
    {
        return 'manage affiliates settings';
    }

    public static function settingsOrder(): int
    {
        return 60;
    }

    public static function settingsIcon(): string
    {
        return 'users';
    }

    /**
     * @return array<int, array{title: string, description: string, fields: array<int, array<string, mixed>>}>
     */
    public static function settingsGroups(): array
    {
        return [
            [
                'title' => __('affiliates::settings.groups.commissions.title'),
                'description' => __('affiliates::settings.groups.commissions.description'),
                'fields' => [
                    static::field('commissions.default.percent', 'integer', ['min' => 0, 'max' => 100]),
                    static::field('commissions.default.recurring', 'select', ['options' => static::options('recurring', ['none', 'limited', 'always'])]),
                    static::field('commissions.default.recurring_times', 'integer', ['min' => 1, 'max' => 120]),
                    static::field('commissions.default.bumps', 'boolean'),
                    static::field('commissions.default.upsells', 'boolean'),
                    static::field('commissions.vat_percent', 'integer', ['min' => 0, 'max' => 50]),
                    static::field('commissions.coupon_wins', 'boolean'),
                    static::field('commissions.self_referral', 'boolean'),
                ],
            ],
            [
                'title' => __('affiliates::settings.groups.tracking.title'),
                'description' => __('affiliates::settings.groups.tracking.description'),
                'fields' => [
                    static::field('tracking.attribution', 'select', ['options' => static::options('attribution', ['first', 'last'])]),
                    static::field('tracking.cookie_days', 'integer', ['min' => 0, 'max' => 3650]),
                    static::field('consent.mode', 'select', ['options' => static::options('consent_mode', ['auto', 'always', 'never'])]),
                    static::field('consent.session_fallback', 'boolean'),
                ],
            ],
            [
                'title' => __('affiliates::settings.groups.payouts.title'),
                'description' => __('affiliates::settings.groups.payouts.description'),
                'fields' => [
                    static::field('commissions.hold_days', 'integer', ['min' => 0, 'max' => 365]),
                    static::field('payouts.minimum_cent', 'integer', ['min' => 0]),
                ],
            ],
            [
                'title' => __('affiliates::settings.groups.partners.title'),
                'description' => __('affiliates::settings.groups.partners.description'),
                'fields' => [
                    static::field('signup.enabled', 'boolean'),
                    static::field('signup.approval', 'select', ['options' => static::options('approval', ['manual', 'auto'])]),
                    static::field('mail.commission', 'boolean'),
                    static::field('mail.approved', 'boolean'),
                ],
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
            $options[$value] = __("affiliates::settings.options.{$group}.{$value}");
        }

        return $options;
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected static function field(string $key, string $type, array $extra = []): array
    {
        $handle = str_replace('.', '_', $key);

        return array_merge([
            'key' => $key,
            'type' => $type,
            'label' => __("affiliates::settings.fields.{$handle}.label"),
            'description' => __("affiliates::settings.fields.{$handle}.description"),
            'nullable' => true,
        ], $extra);
    }
}
