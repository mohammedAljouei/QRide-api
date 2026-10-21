<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Builds the v1 tables the way the live database has them (the repo's old migrations are out of date),
 * then runs the real v2 migration on top. Tests only, never used in production.
 *
 * Default: an in-memory SQLite copy. With QRIDE_SCHEMA_SQL pointing to a structure-only export of the live
 * database and DB_CONNECTION=mysql, the tests run on that exact structure instead (MariaDB).
 */
trait LegacySchema
{
    protected function buildSchema(): void
    {
        $dump = env('QRIDE_SCHEMA_SQL');
        if ($dump && DB::getDriverName() === 'mysql') {
            $this->loadLiveSchema($dump);
        } else {
            $this->buildSqliteSchema();
        }

        Artisan::call('migrate', ['--path' => [
            'database/migrations/2026_10_13_000000_qride_v2_wallet.php',
            'database/migrations/2026_10_22_000000_qride_panel_passwords.php',
        ]]);
    }

    private function loadLiveSchema(string $dump): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach (DB::select('SHOW TABLES') as $row) {
            DB::statement('DROP TABLE `' . array_values((array) $row)[0] . '`');
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
        DB::unprepared(file_get_contents($dump));
    }

    private function buildSqliteSchema(): void
    {
        Schema::create('admins', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('email')->unique(); $t->string('password'); $t->timestamps();
        });
        Schema::create('super_admins', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('email'); $t->string('password'); $t->timestamps();
        });
        Schema::create('menus', function (Blueprint $t) {
            $t->id(); $t->integer('admin_id'); $t->integer('super_admin_id'); $t->integer('version')->default(1);
            $t->string('status')->default('active'); $t->json('payment_methods')->nullable(); $t->json('platforms')->nullable();
            $t->string('color')->nullable(); $t->string('name')->nullable(); $t->string('slogan')->nullable(); $t->timestamps();
        });
        Schema::create('sections', function (Blueprint $t) {
            $t->id(); $t->integer('menu_id'); $t->string('name'); $t->integer('sort'); $t->timestamps();
        });
        Schema::create('meals', function (Blueprint $t) {
            $t->id(); $t->integer('section_id'); $t->string('description')->default(''); $t->string('image_path')->default('');
            $t->string('name'); $t->double('price', 8, 2); $t->string('status')->default('1'); $t->integer('sort')->default(1); $t->timestamps();
        });
        Schema::create('add_on_titles', function (Blueprint $t) {
            $t->id(); $t->integer('meal_id'); $t->string('title'); $t->integer('min')->default(0); $t->integer('max')->default(1); $t->timestamps();
        });
        Schema::create('add_on_infos', function (Blueprint $t) {
            $t->id(); $t->integer('add_on_title_id'); $t->string('name'); $t->double('price', 8, 2); $t->timestamps();
        });
        Schema::create('orders', function (Blueprint $t) {
            $t->id(); $t->integer('menu_id'); $t->string('note'); $t->json('order_info'); $t->string('car_color');
            $t->integer('phone'); $t->string('car_size'); $t->string('status'); $t->string('payment_method');
            $t->string('payment_id'); $t->string('total_price'); $t->timestamps();
        });
        Schema::create('customer_checkins', function (Blueprint $t) {
            $t->id(); $t->integer('order_id'); $t->timestamp('checkin_time')->nullable();
        });
        Schema::create('users', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('email'); $t->timestamp('email_verified_at')->nullable();
            $t->string('password'); $t->string('remember_token')->nullable(); $t->timestamps();
        });
        Schema::create('timeslots', function (Blueprint $t) {
            $t->increments('id'); $t->integer('menu_id'); $t->string('day'); $t->time('start_time'); $t->time('end_time');
        });

        Artisan::call('migrate', ['--path' => 'database/migrations/2019_12_14_000001_create_personal_access_tokens_table.php']);
    }
}
