<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// QRide admin panel: a copy of each shop account's password, encrypted with the app key, so the panel can show it.
// Login still checks the hashed `password`. Additive only (run with --path).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admins', function (Blueprint $table) {
            $table->text('panel_password')->nullable();
        });
        Schema::table('super_admins', function (Blueprint $table) {
            $table->text('panel_password')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('admins', function (Blueprint $table) {
            $table->dropColumn('panel_password');
        });
        Schema::table('super_admins', function (Blueprint $table) {
            $table->dropColumn('panel_password');
        });
    }
};
