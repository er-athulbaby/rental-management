<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('number_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('key', 32);
            $table->unsignedSmallInteger('year');
            $table->string('prefix', 8);
            $table->unsignedInteger('next_value')->default(1);
            $table->unsignedTinyInteger('padding')->default(6);
            $table->timestamps();
            $table->unique(['key', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('number_sequences');
    }
};
