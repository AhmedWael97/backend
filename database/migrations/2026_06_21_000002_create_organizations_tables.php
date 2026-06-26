<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Organizations (agencies/teams) multi-tenancy.
 *
 * An organization is owned by one user (who holds the Agency plan subscription)
 * and can have up to N member accounts (seats). Domains belong to the org;
 * members are granted access to specific domains via `domain_access`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->index('owner_user_id');
        });

        Schema::create('organization_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // owner = billing + everything; admin = manage team + all domains; member = assigned domains only
            $table->string('role', 16)->default('member');
            $table->string('status', 16)->default('active'); // active | invited
            $table->timestamps();
            $table->unique(['organization_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create('organization_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('role', 16)->default('member');
            $table->string('token', 64)->unique();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'email']);
        });

        // Per-member domain grants. owner/admin see all org domains implicitly;
        // plain members only see domains they have a row here for.
        Schema::create('domain_access', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['domain_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::table('domains', function (Blueprint $table) {
            // Nullable: personal domains (no org) keep working unchanged.
            $table->foreignId('organization_id')->nullable()->after('user_id')
                ->constrained()->nullOnDelete();
            $table->index('organization_id');
        });
    }

    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->dropConstrainedForeignId('organization_id');
        });
        Schema::dropIfExists('domain_access');
        Schema::dropIfExists('organization_invitations');
        Schema::dropIfExists('organization_members');
        Schema::dropIfExists('organizations');
    }
};
