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
        Schema::create('netsuite_countries', function (Blueprint $table) {
            $table->id();
            $table->string('netsuite_id')->comment('Internal ID from NetSuite');
            $table->string('country_code')->comment('NetSuite country enum value (e.g., _singapore)');
            $table->string('name')->comment('Country name');
            $table->string('iso_code_2', 2)->nullable()->comment('2-letter ISO code (e.g., SG)');
            $table->string('iso_code_3', 3)->nullable()->comment('3-letter ISO code (e.g., SGP)');
            $table->boolean('is_sandbox')->default(true)->comment('Whether this is from sandbox or production');
            $table->timestamps();

            // Indexes
            $table->unique(['netsuite_id', 'is_sandbox']);
            $table->index('country_code');
            $table->index('iso_code_2');
            $table->index('iso_code_3');
            $table->index('name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('netsuite_countries');
    }
};
