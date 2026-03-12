<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gestion_logs', function (Blueprint $table) {
            $table->id();
            $table->string('batch_id', 36);
            $table->string('cedula', 20);
            $table->string('accion');
            $table->string('resultado');
            $table->text('comentario');
            $table->enum('status', ['pending', 'processing', 'success', 'failed'])->default('pending');
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index('batch_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gestion_logs');
    }
};
