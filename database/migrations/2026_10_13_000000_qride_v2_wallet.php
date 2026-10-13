<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * QRide v2: cashback + wallet.
 *
 * Additive only: new tables and new nullable/defaulted columns, so v1 code keeps working on a v2 database.
 * Rolling this migration back removes the wallet completely, including every balance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('phone')->nullable()->unique(); // normalised: 9665XXXXXXXX
            $table->timestamp('phone_verified_at')->nullable();
            $table->timestamps();
        });

        // One wallet per customer per shop
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('menu_id')->index();
            $table->unsignedBigInteger('customer_id')->index();
            $table->timestamps();
            $table->unique(['menu_id', 'customer_id']);
        });

        // Ledger: the balance is the sum of posted amounts
        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('wallet_id')->index();
            $table->string('type');                         // earn | redeem
            $table->decimal('amount', 10, 2);               // positive = in, negative = out
            $table->string('status')->default('posted');    // posted | pending | void
            $table->string('source_type');                  // order | counter
            $table->unsignedBigInteger('source_id')->nullable();
            $table->timestamps();
            $table->unique(['source_type', 'source_id', 'type']); // an order can only earn once
        });

        Schema::table('menus', function (Blueprint $table) {
            $table->boolean('wallet_enabled')->default(false);
            $table->decimal('cashback_percent', 5, 2)->default(0);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->decimal('server_total', 10, 2)->nullable();
            $table->decimal('cashback_amount', 10, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['customer_id', 'server_total', 'cashback_amount']);
        });

        Schema::table('menus', function (Blueprint $table) {
            $table->dropColumn(['wallet_enabled', 'cashback_percent']);
        });

        Schema::dropIfExists('wallet_transactions');
        Schema::dropIfExists('wallets');
        Schema::dropIfExists('customers');

        // Customer login tokens live in the shared tokens table
        DB::table('personal_access_tokens')->where('tokenable_type', 'App\\Models\\Customer')->delete();
    }
};
