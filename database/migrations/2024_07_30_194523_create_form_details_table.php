<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateFormDetailsTable extends Migration
{
    public function up()
    {
        Schema::create('form_details', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('phone');
            $table->string('resName');
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('form_details');
    }
}
