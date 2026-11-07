<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasIndex('flow_instances', 'flow_instances_subject_idx')) {
            Schema::table('flow_instances', fn (Blueprint $table) => $table->index(['model_type', 'model_id'], 'flow_instances_subject_idx'));
        }
        if (! Schema::hasIndex('flow_instance_steps', 'flow_instance_steps_pending_idx')) {
            Schema::table('flow_instance_steps', fn (Blueprint $table) => $table->index(['flow_instance_id', 'finished_at', 'deleted_at', 'id'], 'flow_instance_steps_pending_idx'));
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('flow_instance_steps') && Schema::hasIndex('flow_instance_steps', 'flow_instance_steps_pending_idx')) {
            Schema::table('flow_instance_steps', fn (Blueprint $table) => $table->dropIndex('flow_instance_steps_pending_idx'));
        }
        if (Schema::hasTable('flow_instances') && Schema::hasIndex('flow_instances', 'flow_instances_subject_idx')) {
            Schema::table('flow_instances', fn (Blueprint $table) => $table->dropIndex('flow_instances_subject_idx'));
        }
    }
};
