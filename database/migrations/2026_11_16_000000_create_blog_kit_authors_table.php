<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Authors with their own page. Sites that had the single configured author
 * (BLOG_KIT_AUTHOR_NAME / BLOG_KIT_AUTHOR_URL) get that author as the first,
 * default author, and every existing post is assigned to them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blog_kit_authors', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('job_title')->nullable();
            $table->text('bio')->nullable();
            $table->text('avatar_url')->nullable();
            $table->text('website_url')->nullable();
            $table->json('social_links')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        Schema::table('blog_kit_posts', function (Blueprint $table) {
            $table->foreignId('author_id')->nullable()->after('product_id')->constrained('blog_kit_authors')->nullOnDelete();
        });

        $name = trim((string) config('blog-kit.author.name'));

        if ($name === '') {
            return;
        }

        $now = now();
        $id = DB::table('blog_kit_authors')->insertGetId([
            'name' => $name,
            'slug' => Str::slug($name) ?: 'author',
            'website_url' => config('blog-kit.author.url') ?: null,
            'is_default' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('blog_kit_posts')->whereNull('author_id')->update(['author_id' => $id]);
    }

    public function down(): void
    {
        Schema::table('blog_kit_posts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('author_id');
        });

        Schema::dropIfExists('blog_kit_authors');
    }
};
