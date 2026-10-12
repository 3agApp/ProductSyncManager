<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keep Nalda and Temu orders in one table.
 *
 * Both are imported into WooCommerce the same way and report their delivery
 * back the same way, so the Temu orders table becomes the marketplace orders
 * table: the rows already there are Temu's, and Nalda's join them. The
 * delivery fields are what the WooCommerce plugin records and this app sends
 * back to the marketplace.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Indexes keep the name of the table they were made on, so the old ones
        // go before the rename and new ones are made after it. MySQL will not
        // drop the last index the shop_id foreign key can use, so it gets one
        // of its own first. Each step is skipped when done already, so a run
        // that stopped halfway can be finished.
        if (! Schema::hasIndex('temu_orders', 'marketplace_orders_shop_id_index')) {
            Schema::table('temu_orders', function (Blueprint $table) {
                $table->index('shop_id', 'marketplace_orders_shop_id_index');
            });
        }

        if (Schema::hasIndex('temu_orders', 'temu_orders_shop_id_parent_order_sn_unique')) {
            Schema::table('temu_orders', function (Blueprint $table) {
                $table->dropUnique(['shop_id', 'parent_order_sn']);
            });
        }

        if (Schema::hasIndex('temu_orders', 'temu_orders_shop_id_status_index')) {
            Schema::table('temu_orders', function (Blueprint $table) {
                $table->dropIndex(['shop_id', 'status']);
            });
        }

        Schema::rename('temu_orders', 'marketplace_orders');

        Schema::table('marketplace_orders', function (Blueprint $table) {
            $table->renameColumn('parent_order_sn', 'external_order_id');
        });

        Schema::table('marketplace_orders', function (Blueprint $table) {
            $table->renameColumn('temu_status', 'marketplace_status');
        });

        Schema::table('marketplace_orders', function (Blueprint $table) {
            $table->string('marketplace_status', 32)->change();
            $table->string('marketplace', 16)->default('temu')->after('shop_id');
            $table->decimal('customer_total', 12, 2)->nullable()->after('message');
            $table->decimal('commission', 12, 2)->nullable()->after('customer_total');
            $table->string('payout_status', 32)->nullable()->after('commission');
            $table->string('delivery_status', 32)->nullable()->after('payout_status');
            $table->string('tracking_code')->nullable()->after('delivery_status');
            $table->date('expected_delivery_on')->nullable()->after('tracking_code');
            // The order's GTINs, one status row per article in Nalda's export.
            $table->json('gtins')->nullable()->after('expected_delivery_on');
            $table->timestamp('woocommerce_modified_at')->nullable()->after('imported_at');
        });

        Schema::table('marketplace_orders', function (Blueprint $table) {
            $table->string('marketplace', 16)->default(null)->change();
            $table->unique(['shop_id', 'marketplace', 'external_order_id']);
            $table->index(['shop_id', 'marketplace', 'status']);
        });

        Schema::table('nalda_connections', function (Blueprint $table) {
            $table->text('api_key')->nullable()->after('sftp_password');
            $table->boolean('import_orders')->default(false)->after('upload_after_sync');
            $table->timestamp('orders_checked_at')->nullable()->after('last_exported_at');
            $table->timestamp('statuses_exported_at')->nullable()->after('orders_checked_at');
        });

        // The last time the delivery details of marketplace orders were read
        // back from the store.
        Schema::table('woocommerce_connections', function (Blueprint $table) {
            $table->timestamp('marketplace_orders_checked_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('woocommerce_connections', function (Blueprint $table) {
            $table->dropColumn('marketplace_orders_checked_at');
        });

        Schema::table('nalda_connections', function (Blueprint $table) {
            $table->dropColumn(['api_key', 'import_orders', 'orders_checked_at', 'statuses_exported_at']);
        });

        Schema::table('marketplace_orders', function (Blueprint $table) {
            $table->dropUnique(['shop_id', 'marketplace', 'external_order_id']);
            $table->dropIndex(['shop_id', 'marketplace', 'status']);
        });

        Schema::table('marketplace_orders', function (Blueprint $table) {
            $table->dropColumn([
                'marketplace', 'customer_total', 'commission', 'payout_status',
                'delivery_status', 'tracking_code', 'expected_delivery_on', 'gtins', 'woocommerce_modified_at',
            ]);
        });

        Schema::table('marketplace_orders', function (Blueprint $table) {
            $table->renameColumn('external_order_id', 'parent_order_sn');
        });

        Schema::table('marketplace_orders', function (Blueprint $table) {
            $table->renameColumn('marketplace_status', 'temu_status');
        });

        Schema::rename('marketplace_orders', 'temu_orders');

        Schema::table('temu_orders', function (Blueprint $table) {
            $table->unique(['shop_id', 'parent_order_sn']);
            $table->index(['shop_id', 'status']);
        });

        Schema::table('temu_orders', function (Blueprint $table) {
            $table->dropIndex('marketplace_orders_shop_id_index');
        });
    }
};
