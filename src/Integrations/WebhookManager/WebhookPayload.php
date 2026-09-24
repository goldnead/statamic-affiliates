<?php

namespace Goldnead\Affiliates\Integrations\WebhookManager;

use Closure;
use Goldnead\Affiliates\Events\CommissionEarned;
use Goldnead\Affiliates\Events\CommissionReversed;
use Goldnead\Affiliates\Events\PartnerApplied;
use Goldnead\Affiliates\Events\PartnerApproved;
use Goldnead\Affiliates\Models\Commission;
use Goldnead\Affiliates\Models\Partner;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * What a webhook receiver is told about a partner moment, and nothing more.
 *
 * A chosen list, never the row. A partner row carries the payout method and
 * details (IBAN, PayPal address), the invitation token and the operator's
 * private notes; none of that may leave the site because somebody wired a
 * Slack message to "partner applied".
 *
 * The shape every addon of the suite sends:
 *
 *     {
 *       "event": "affiliates.commission_earned",
 *       "occurred_at": "2026-09-24T10:12:03+02:00",
 *       "brand": {"id": 2, "handle": "nordlicht"} | null,
 *       "subject_type": "commission", "subject_id": 9,
 *       "<object>": { ... }
 *     }
 *
 * Money is `*_cent` next to `currency`; times are ISO 8601 or null.
 *
 * Names no class of the webhook manager, so it loads on every install.
 */
final class WebhookPayload
{
    /**
     * Moment => event class. The handle is `affiliates.<moment>`, the same the
     * automations addon uses.
     *
     * @var array<string, class-string>
     */
    public const MOMENTS = [
        'commission_earned' => CommissionEarned::class,
        'commission_reversed' => CommissionReversed::class,
        'partner_applied' => PartnerApplied::class,
        'partner_approved' => PartnerApproved::class,
    ];

    public const PREFIX = 'affiliates.';

    /** @var array<int, array{id: int, handle: string}|null> */
    private static array $brands = [];

    /**
     * @return array<string, mixed>
     */
    public static function build(string $moment, object $event, ?\DateTimeInterface $at = null): array
    {
        [$type, $id] = self::subjectOf($event);

        return array_merge([
            'event' => self::PREFIX.$moment,
            'occurred_at' => self::date($at ?? now()),
            'brand' => self::brand(self::brandIdOf($event)),
            'subject_type' => $type,
            'subject_id' => $id,
        ], self::body($event));
    }

    /**
     * @return array{0: string|null, 1: int|null}
     */
    public static function subjectOf(object $event): array
    {
        return match (true) {
            isset($event->commission) && $event->commission instanceof Commission => ['commission', (int) $event->commission->getKey()],
            isset($event->partner) && $event->partner instanceof Partner => ['partner', (int) $event->partner->getKey()],
            default => [null, null],
        };
    }

    public static function referenceOf(object $event): ?string
    {
        $id = self::subjectOf($event)[1];

        return $id === null ? null : (string) $id;
    }

    /**
     * The brand stamped on the row. A commission is booked in a payment
     * webhook, where no brand is current.
     */
    public static function brandIdOf(object $event): ?int
    {
        $model = $event->commission ?? $event->partner ?? null;
        $brand = is_object($model) ? ($model->brand_id ?? null) : null;

        if (is_numeric($brand) && (int) $brand > 0) {
            return (int) $brand;
        }

        try {
            if (! app()->bound('brand-context')) {
                return null;
            }

            $manager = app('brand-context');

            return $manager->hasCurrent() ? (int) $manager->currentId() : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{id: int, handle: string}|null
     */
    public static function brand(?int $id): ?array
    {
        $model = 'Goldnead\\BrandContext\\Models\\Brand';

        if ($id === null || $id < 1 || ! class_exists($model)) {
            return null;
        }

        if (array_key_exists($id, self::$brands)) {
            return self::$brands[$id];
        }

        try {
            $brand = $model::query()->find($id);
            $answer = $brand === null ? null : ['id' => (int) $brand->getKey(), 'handle' => (string) $brand->getAttribute('handle')];
        } catch (Throwable) {
            $answer = null;
        }

        return self::$brands[$id] = $answer;
    }

    public static function forgetBrands(): void
    {
        self::$brands = [];
    }

    /**
     * Run the dispatch as the row's brand, restoring whatever was current. A
     * brand that cannot be set is logged and the dispatch runs without it.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function runFor(?int $brand, Closure $callback): mixed
    {
        if (! $brand || ! app()->bound('brand-context')) {
            return $callback();
        }

        $ran = false;

        try {
            return app('brand-context')->runFor($brand, function () use ($callback, &$ran) {
                $ran = true;

                return $callback();
            });
        } catch (Throwable $e) {
            if ($ran) {
                throw $e;
            }

            Log::warning('statamic-affiliates: the brand of this row could not be set for the webhook; it ran without it.', [
                'brand_id' => $brand,
                'exception' => $e->getMessage(),
            ]);

            return $callback();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function body(object $event): array
    {
        return match (true) {
            $event instanceof CommissionEarned,
            $event instanceof CommissionReversed => [
                'commission' => self::commission($event->commission),
                'partner' => ($partner = $event->commission->partner) instanceof Partner ? self::partner($partner) : null,
            ],
            $event instanceof PartnerApplied => [
                // What the applicant wrote is the point of this moment: the
                // operator decides on it.
                'partner' => self::partner($event->partner) + ['message' => $event->partner->message],
            ],
            $event instanceof PartnerApproved => [
                'partner' => self::partner($event->partner),
            ],
            default => [],
        };
    }

    /**
     * Not here: payout method and details, the invitation token, the
     * operator's notes, the linked user account.
     *
     * @return array<string, mixed>
     */
    public static function partner(Partner $partner): array
    {
        return [
            'id' => (int) $partner->getKey(),
            'name' => $partner->name,
            'email' => $partner->email,
            'code' => $partner->code,
            'status' => $partner->status,
            'commission_percent' => $partner->commission_percent,
            'website' => $partner->website,
            'created_at' => self::date($partner->created_at),
            'approved_at' => self::date($partner->approved_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function commission(Commission $commission): array
    {
        return [
            'id' => (int) $commission->getKey(),
            'kind' => $commission->kind,
            'status' => $commission->status,
            'product' => $commission->product,
            'cycle' => $commission->cycle === null ? null : (int) $commission->cycle,
            'base_cent' => (int) $commission->base_cent,
            'amount_cent' => (int) $commission->amount_cent,
            'reversed_cent' => (int) $commission->reversed_cent,
            'currency' => $commission->currency,
            'rate' => $commission->rate,
            'payment_id' => $commission->payment_id === null ? null : (int) $commission->payment_id,
            'reverses_id' => $commission->reverses_id === null ? null : (int) $commission->reverses_id,
            'reason' => $commission->reason,
            'sold_at' => self::date($commission->sold_at),
            'available_at' => self::date($commission->available_at),
            'approved_at' => self::date($commission->approved_at),
            'reversed_at' => self::date($commission->reversed_at),
            'created_at' => self::date($commission->created_at),
        ];
    }

    public static function date(mixed $value): ?string
    {
        return $value instanceof \DateTimeInterface ? $value->format(\DATE_ATOM) : null;
    }
}
