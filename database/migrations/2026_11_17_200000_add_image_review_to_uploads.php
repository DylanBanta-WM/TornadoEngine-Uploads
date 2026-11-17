<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Images: text the broker would replace is covered; faces and district logos are shown to the uploader first.
    public function up(): void
    {
        Schema::table('uploads', function (Blueprint $table) {
            $table->boolean('student')->default(false)->after('share');
            $table->json('findings')->nullable()->after('faces_confirmed');
            $table->boolean('answered')->default(false)->after('findings');
            $table->boolean('cover')->default(false)->after('answered');
            $table->unsignedInteger('covered')->default(0)->after('cover');
        });
    }

    public function down(): void
    {
        Schema::table('uploads', function (Blueprint $table) {
            $table->dropColumn(['student', 'findings', 'answered', 'cover', 'covered']);
        });
    }
};
