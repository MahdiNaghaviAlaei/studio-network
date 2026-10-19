<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equipment', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('label');
            $table->string('type')->default('server');
            $table->json('data')->nullable();
            $table->unsignedInteger('revision')->default(0);
            $table->timestamps();
        });
        Schema::table('network_nodes', function (Blueprint $table) {
            $table->foreignUuid('equipment_id')->nullable()->constrained('equipment')->restrictOnDelete();
            $table->unique(['network_id', 'equipment_id']);
        });

        // Keep existing inventory URLs and every existing map placement intact.
        DB::table('network_nodes')->where('kind', 'device')->orderBy('id')->chunk(100, function ($nodes) {
            foreach ($nodes as $node) {
                $data = json_decode($node->data ?? '{}', true) ?? [];
                unset($data['rack_u'], $data['height_u']);
                DB::table('equipment')->insert([
                    'id' => $node->id, 'label' => $node->label, 'type' => $node->type,
                    'data' => json_encode($data), 'revision' => 0,
                    'created_at' => $node->created_at, 'updated_at' => $node->updated_at,
                ]);
                DB::table('network_nodes')->where('id', $node->id)->update(['equipment_id' => $node->id]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('network_nodes', function (Blueprint $table) {
            $table->dropUnique(['network_id', 'equipment_id']);
            $table->dropConstrainedForeignId('equipment_id');
        });
        Schema::dropIfExists('equipment');
    }
};
