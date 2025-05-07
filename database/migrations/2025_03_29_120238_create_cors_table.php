<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::create('cors', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('location');
            $table->string('username');
            $table->string('password');
            $table->string('url');
            $table->string('station_list');
            $table->string('latitude');
            $table->string('longitude');
            $table->string('ip_address');
            $table->string('port');
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('cors');
    }
};
