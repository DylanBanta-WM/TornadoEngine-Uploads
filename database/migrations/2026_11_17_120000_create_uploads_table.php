<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('uploads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size');
            $table->string('path');
            // What Claude gets: none, text (filtered), or image (filtered text and the image).
            $table->string('share')->default('text');
            // Faces found in an image (null: not checked or couldn't check); the image waits for faces_confirmed.
            $table->integer('faces')->nullable();
            $table->boolean('faces_confirmed')->default(false);
            $table->boolean('has_text')->default(false);
            $table->string('error')->nullable();
            // What it's attached to, for example source chat, reference the message.
            $table->string('source')->nullable();
            $table->string('reference')->nullable();
            $table->timestamps();
            $table->index(['source', 'reference']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('uploads');
    }
};
