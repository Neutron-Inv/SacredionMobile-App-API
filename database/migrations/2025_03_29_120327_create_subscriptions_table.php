<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('cors_id')->constrained('cors')->onDelete('cascade');
            $table->string('payment_reference')->unique();
            $table->date('expiry_date');
            $table->integer('user_limit'); // Max number of users allowed
            $table->integer('days_limit'); // Days the subscription is valid
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('subscriptions');
    }
};
