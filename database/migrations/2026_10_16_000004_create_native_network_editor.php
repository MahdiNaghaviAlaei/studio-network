<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('networks', function (Blueprint $table) {
            $table->json('settings')->nullable();
            $table->json('viewport')->nullable();
            $table->unsignedInteger('revision')->default(0);
        });
        Schema::create('network_nodes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('network_id')->constrained()->cascadeOnDelete();
            $table->uuid('parent_id')->nullable();
            $table->string('kind')->default('device');
            $table->string('type')->default('server');
            $table->string('label');
            $table->double('x')->default(0);
            $table->double('y')->default(0);
            $table->double('width')->default(172);
            $table->double('height')->default(78);
            $table->json('data')->nullable();
            $table->json('style')->nullable();
            $table->timestamps();
        });
        Schema::create('network_edges', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('network_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('source')->constrained('network_nodes')->cascadeOnDelete();
            $table->foreignUuid('target')->constrained('network_nodes')->cascadeOnDelete();
            $table->string('type')->default('ethernet');
            $table->string('label')->nullable();
            $table->json('data')->nullable();
            $table->json('style')->nullable();
            $table->timestamps();
        });
        Schema::create('network_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('network_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('revision');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->json('graph');
            $table->timestamps();
            $table->unique(['network_id', 'revision']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('network_versions');
        Schema::dropIfExists('network_edges');
        Schema::dropIfExists('network_nodes');
        Schema::table('networks', fn (Blueprint $table) => $table->dropColumn(['settings', 'viewport', 'revision']));
    }
};
