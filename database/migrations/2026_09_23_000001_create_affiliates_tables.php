<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The partner programme's own tables. None of them points into another
 * addon's schema with a foreign key: `payment_id` names a statamic-payments
 * row, but payments is optional, and a payment deleted there must not take a
 * commission that was already paid out with it.
 *
 * Amounts in minor units, like every money column in the family.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('affiliate_partners', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('brand_id')->default(0)->index();
            // A Statamic user id. Null until somebody signs in and claims the
            // record through an invitation.
            $table->string('user_id', 64)->nullable()->index();
            $table->string('name', 191);
            $table->string('email', 191);
            // What stands in the link. Unique per brand, never reused.
            $table->string('code', 64);
            $table->string('status', 16)->default('pending')->index();
            // Overrides the percentage of every percent rate for this partner.
            $table->decimal('commission_percent', 5, 2)->nullable();
            $table->string('payout_method', 16)->nullable();
            // Bank or PayPal details. Encrypted at rest by the model cast.
            $table->text('payout_details')->nullable();
            $table->json('coupon_codes')->nullable();
            $table->string('website', 500)->nullable();
            $table->text('message')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('notify')->default(true);
            $table->string('invite_token', 64)->nullable()->unique();
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->unique(['brand_id', 'code']);
            $table->unique(['brand_id', 'email']);
        });

        Schema::create('affiliate_clicks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('brand_id')->default(0)->index();
            $table->unsignedBigInteger('partner_id');
            // Path only. No IP address, no user agent: a click count needs
            // neither, and what is not stored cannot leak.
            $table->string('landing', 500)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['partner_id', 'created_at']);
        });

        Schema::create('affiliate_referrals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('brand_id')->default(0)->index();
            $table->unsignedBigInteger('partner_id')->index();
            // One referral per payment. The unique index is what keeps a
            // redelivered webhook from attributing a sale twice.
            $table->unsignedBigInteger('payment_id')->unique();
            $table->string('source', 16);
            $table->string('coupon_code', 64)->nullable();
            $table->timestamp('clicked_at')->nullable();
            // Set when the payment is paid. A referral noted at a checkout
            // nobody finished is not a sale.
            $table->timestamp('paid_at')->nullable()->index();
            // Refunded in full, or bought by the partner themselves: noted,
            // and left out of the partner's sales.
            $table->timestamp('refunded_at')->nullable();
            $table->boolean('self_purchase')->default(false);
            $table->timestamps();
        });

        Schema::create('affiliate_rates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('brand_id')->default(0)->index();
            // The product handle as statamic-payments records it.
            $table->string('product', 191);
            $table->string('type', 16)->default('percent');
            $table->decimal('percent', 5, 2)->nullable();
            $table->unsignedInteger('amount_cent')->nullable();
            $table->string('recurring', 16)->default('none');
            $table->unsignedSmallInteger('recurring_times')->nullable();
            $table->decimal('recurring_percent', 5, 2)->nullable();
            $table->boolean('bumps')->default(false);
            $table->decimal('bump_percent', 5, 2)->nullable();
            $table->boolean('upsells')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['brand_id', 'product']);
        });

        Schema::create('affiliate_jv_contracts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('brand_id')->default(0)->index();
            $table->unsignedBigInteger('partner_id')->index();
            $table->string('name', 191);
            // Product handles. Empty means every product.
            $table->json('products')->nullable();
            $table->decimal('percent', 5, 2);
            $table->decimal('bump_percent', 5, 2)->nullable();
            $table->decimal('upsell_percent', 5, 2)->nullable();
            $table->boolean('recurring')->default(true);
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('affiliate_commissions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('brand_id')->default(0)->index();
            $table->unsignedBigInteger('partner_id')->index();
            $table->unsignedBigInteger('payment_id')->index();
            $table->unsignedBigInteger('payment_item_id')->nullable();
            $table->unsignedBigInteger('origin_payment_id')->nullable()->index();
            $table->unsignedBigInteger('jv_contract_id')->nullable()->index();
            $table->unsignedBigInteger('reverses_id')->nullable()->index();
            $table->string('kind', 16);
            $table->string('product', 191)->nullable();
            $table->unsignedSmallInteger('cycle')->nullable();
            $table->integer('base_cent');
            // Negative only for a claw-back of a commission already paid out.
            $table->integer('amount_cent');
            $table->integer('reversed_cent')->default(0);
            $table->string('currency', 3);
            $table->string('status', 16)->default('pending')->index();
            $table->string('rate', 64)->nullable();
            // When the sale happened; created_at is when it was booked.
            $table->timestamp('sold_at')->nullable()->index();
            $table->timestamp('available_at')->nullable()->index();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->string('reason', 191)->nullable();
            $table->unsignedBigInteger('payout_id')->nullable()->index();
            // What makes booking idempotent: payment, partner, kind, line,
            // contract. A second PaymentPaid for the same payment finds it.
            $table->string('dedupe_key', 191)->unique();
            $table->timestamps();
        });

        Schema::create('affiliate_payouts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('brand_id')->default(0)->index();
            $table->unsignedBigInteger('partner_id')->index();
            $table->integer('amount_cent');
            $table->string('currency', 3);
            $table->unsignedInteger('commission_count')->default(0);
            $table->string('status', 16)->default('open')->index();
            $table->string('reference', 64);
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('affiliate_payouts');
        Schema::dropIfExists('affiliate_commissions');
        Schema::dropIfExists('affiliate_jv_contracts');
        Schema::dropIfExists('affiliate_rates');
        Schema::dropIfExists('affiliate_referrals');
        Schema::dropIfExists('affiliate_clicks');
        Schema::dropIfExists('affiliate_partners');
    }
};
