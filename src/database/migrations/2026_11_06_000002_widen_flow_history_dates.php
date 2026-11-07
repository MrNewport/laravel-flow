<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }
        Schema::table('flow_instance_steps', function (Blueprint $table): void {
            $table->dateTime('started_at')->useCurrent()->change();
            $table->dateTime('finished_at')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Deliberately retain the wider type: narrowing can destroy accepted saved history.
    }
};
