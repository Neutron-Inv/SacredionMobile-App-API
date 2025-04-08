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
            $table->string('station_list'); // Stores a list of stations in JSON format
            $table->string('latitude'); // Stores a list of stations in JSON format
            $table->string('longitude'); // Stores a list of stations in JSON format
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('cors');
    }
};
