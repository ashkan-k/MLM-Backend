<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_level_chapters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_level_id')->constrained('course_levels')->cascadeOnDelete();
            $table->string('title');
            $table->unsignedInteger('sort_order')->default(1);
            $table->string('content_type')->default('text');
            $table->longText('content_body')->nullable();
            $table->string('content_url')->nullable();
            $table->string('attachment_path')->nullable();
            $table->string('attachment_name')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['course_level_id', 'sort_order']);
        });

        Schema::create('user_course_chapter_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_level_id')->constrained('course_levels')->cascadeOnDelete();
            $table->foreignId('course_level_chapter_id')->constrained('course_level_chapters')->cascadeOnDelete();
            $table->string('status')->default('completed');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'course_level_chapter_id'], 'user_chapter_unique');
        });

        if (Schema::hasTable('course_levels')) {
            $levels = DB::table('course_levels')->orderBy('id')->get();
            foreach ($levels as $level) {
                $hasContent = filled($level->content_body ?? null)
                    || filled($level->content_url ?? null)
                    || filled($level->attachment_path ?? null);
                DB::table('course_level_chapters')->insert([
                    'course_level_id' => $level->id,
                    'title' => 'فصل ۱',
                    'sort_order' => 1,
                    'content_type' => $level->content_type ?? 'text',
                    'content_body' => $level->content_body,
                    'content_url' => $level->content_url,
                    'attachment_path' => $level->attachment_path,
                    'attachment_name' => $level->attachment_name,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                // If level had no content, still create an empty chapter so UI can edit.
                if (! $hasContent) {
                    // already inserted empty chapter above
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('user_course_chapter_progress');
        Schema::dropIfExists('course_level_chapters');
    }
};
