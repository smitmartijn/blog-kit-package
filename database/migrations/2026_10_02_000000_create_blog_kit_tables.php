<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blog_kit_posts', function (Blueprint $table) {
            $table->id();
            $table->string('source', 20)->default('marketing_os');
            $table->string('content_item_id')->nullable()->unique();
            $table->string('product_id')->nullable();
            $table->string('slug')->unique();
            $table->string('title');
            $table->string('meta_title')->nullable();
            $table->text('meta_description')->nullable();
            $table->text('excerpt')->nullable();
            $table->json('featured_image')->nullable();
            $table->longText('body_html');
            $table->longText('body_markdown')->nullable();
            $table->json('faq')->nullable();
            $table->json('cta')->nullable();
            $table->json('internal_links')->nullable();
            $table->json('external_sources')->nullable();
            $table->json('schema_suggestions')->nullable();
            $table->json('images')->nullable();
            $table->string('target_keyword')->nullable();
            $table->json('secondary_keywords')->nullable();
            $table->string('post_type')->nullable();
            $table->timestamp('published_at')->nullable()->index();
            $table->timestamp('source_updated_at')->nullable();
            $table->timestamp('unpublished_at')->nullable();
            $table->timestamp('hidden_at')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->string('locked_by')->nullable();
            $table->string('images_status', 20)->default('none');
            $table->text('image_error')->nullable();
            $table->timestamp('images_processed_at')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->string('last_delivery_id')->nullable();
            $table->timestamps();
        });

        Schema::create('blog_kit_tags', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });

        Schema::create('blog_kit_post_tag', function (Blueprint $table) {
            $table->foreignId('post_id')->constrained('blog_kit_posts')->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained('blog_kit_tags')->cascadeOnDelete();
            $table->primary(['post_id', 'tag_id']);
        });

        Schema::create('blog_kit_redirects', function (Blueprint $table) {
            $table->id();
            $table->string('from_slug')->unique();
            $table->foreignId('post_id')->constrained('blog_kit_posts')->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('blog_kit_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained('blog_kit_posts')->cascadeOnDelete();
            $table->text('source_url');
            $table->char('source_hash', 64);
            $table->string('status', 20);
            $table->string('disk')->nullable();
            $table->string('path')->nullable();
            $table->text('url')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('bytes')->nullable();
            $table->text('error')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamps();
            $table->unique(['post_id', 'source_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blog_kit_images');
        Schema::dropIfExists('blog_kit_redirects');
        Schema::dropIfExists('blog_kit_post_tag');
        Schema::dropIfExists('blog_kit_tags');
        Schema::dropIfExists('blog_kit_posts');
    }
};
