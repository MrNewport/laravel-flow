<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use MrNewport\LaravelFlow\Models\FlowStep;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flow_steps', function (Blueprint $table) {
            $table->string('id',100)->primary();
            $table->string('name',255);
            $table->boolean('notify')->default(false);
            $table->string('assignment_strategy',100)->nullable();
            $table->json('assignment_params')->nullable();
            $table->timestamps();
        });

        FlowStep::create([
            'id'=>'END','name'=>'Flow End','notify'=>false,
            'assignment_strategy'=>null,'assignment_params'=>null
        ]);

        Schema::create('flow_transitions', function (Blueprint $table) {
            $table->string('step_id',100);
            $table->string('action',100);
            $table->string('next_step_id',100);
            $table->unique(['step_id','action','next_step_id'],'flow_transitions_uidx');
        });

        Schema::create('flow_instances', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('current_step_id',100)->index();
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->timestamps();
        });

        Schema::create('flow_instance_steps', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('flow_instance_id')->index();
            $table->string('step_id',100);
            $table->string('action_taken',100)->nullable();
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('finished_at')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('flow_step_assignees', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('flow_instance_step_id')->index();
            $table->string('assignee_type',50)->nullable();
            $table->string('assignee_value',255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flow_step_assignees');
        Schema::dropIfExists('flow_instance_steps');
        Schema::dropIfExists('flow_instances');
        Schema::dropIfExists('flow_transitions');
        Schema::dropIfExists('flow_steps');
    }
};
