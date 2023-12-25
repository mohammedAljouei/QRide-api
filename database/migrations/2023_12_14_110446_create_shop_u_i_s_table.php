<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('shop_u_i_s', function (Blueprint $table) {
            $table->id();
            $table->integer('menu_id');
            $table->string('pr_color');
            $table->string('sec_color');
            $table->string('slogo');
            $table->string('title');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shop_u_i_s');
    }
};
