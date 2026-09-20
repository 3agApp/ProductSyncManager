<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Put back what the move to 3AG Accounts SSO took away.
 *
 * Authentication lives in this app again, so Fortify needs its two-factor
 * columns and the passkeys table back, and the `sso_id` that pointed at an
 * Accounts subject no longer points at anything.
 *
 * Every step is guarded. The migration has to be correct both on a database
 * that ran the SSO migrations and on one built from scratch, and the two
 * arrive here in different shapes.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'two_factor_secret')) {
                $table->text('two_factor_secret')->after('password')->nullable();
                $table->text('two_factor_recovery_codes')->after('two_factor_secret')->nullable();
                $table->timestamp('two_factor_confirmed_at')->after('two_factor_recovery_codes')->nullable();
            }
        });

        if (! Schema::hasTable('passkeys')) {
            Schema::create('passkeys', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('name');
                $table->string('credential_id')->unique();
                $table->json('credential');
                $table->timestamp('last_used_at')->nullable();
                $table->timestamps();

                $table->index('user_id');
            });
        }

        if (Schema::hasColumn('users', 'sso_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropUnique(['sso_id']);
                $table->dropColumn('sso_id');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'sso_id')) {
                $table->string('sso_id')->nullable()->unique()->after('id');
            }
        });

        Schema::dropIfExists('passkeys');

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'two_factor_secret')) {
                $table->dropColumn([
                    'two_factor_secret',
                    'two_factor_recovery_codes',
                    'two_factor_confirmed_at',
                ]);
            }
        });
    }
};
