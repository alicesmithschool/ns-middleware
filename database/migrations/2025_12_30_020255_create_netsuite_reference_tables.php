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
        // Departments table
        Schema::create('netsuite_departments', function (Blueprint $table) {
            $table->id();
            $table->string('netsuite_id')->unique();
            $table->string('name');
            $table->boolean('is_inactive')->default(false);
            $table->timestamps();
            
            $table->index('netsuite_id');
            $table->index('name');
        });

        // Accounts table
        Schema::create('netsuite_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('netsuite_id')->unique();
            $table->string('name');
            $table->string('account_type')->nullable();
            $table->string('account_number')->nullable();
            $table->boolean('is_inactive')->default(false);
            $table->timestamps();
            
            $table->index('netsuite_id');
            $table->index('name');
            $table->index('account_type');
        });

        // Currencies table
        Schema::create('netsuite_currencies', function (Blueprint $table) {
            $table->id();
            $table->string('netsuite_id')->unique();
            $table->string('name');
            $table->string('symbol')->nullable();
            $table->string('currency_code')->nullable();
            $table->decimal('exchange_rate', 10, 6)->nullable();
            $table->boolean('is_base_currency')->default(false);
            $table->boolean('is_inactive')->default(false);
            $table->timestamps();
            
            $table->index('netsuite_id');
            $table->index('name');
            $table->index('currency_code');
        });

        // Locations table
        Schema::create('netsuite_locations', function (Blueprint $table) {
            $table->id();
            $table->string('netsuite_id')->unique();
            $table->string('name');
            $table->string('location_type')->nullable();
            $table->boolean('is_inactive')->default(false);
            $table->timestamps();
            
            $table->index('netsuite_id');
            $table->index('name');
        });

        // Vendors table (for reference)
        Schema::create('netsuite_vendors', function (Blueprint $table) {
            $table->id();
            $table->string('netsuite_id')->unique();
            $table->string('name');
            $table->string('entity_id')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('default_currency_id')->nullable();
            $table->json('supported_currencies')->nullable();
            $table->boolean('is_inactive')->default(false);
            $table->timestamps();
            
            $table->index('netsuite_id');
            $table->index('name');
            $table->index('entity_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('netsuite_vendors');
        Schema::dropIfExists('netsuite_locations');
        Schema::dropIfExists('netsuite_currencies');
        Schema::dropIfExists('netsuite_accounts');
        Schema::dropIfExists('netsuite_departments');
    }
};
