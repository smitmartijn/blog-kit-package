<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Lostdomain\BlogKit\Support\PostText;

/**
 * Plain, lowercased text of each post for the read API's full-text search.
 * Kept up to date on save; existing posts are filled here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('blog_kit_posts', function (Blueprint $table) {
            $table->longText('search_text')->nullable()->after('body_format');
        });

        DB::table('blog_kit_posts')
            ->select(['id', 'title', 'excerpt', 'body_html', 'faq'])
            ->orderBy('id')
            ->chunkById(100, function ($posts) {
                foreach ($posts as $post) {
                    $faq = is_string($post->faq) ? json_decode($post->faq, true) : null;

                    DB::table('blog_kit_posts')->where('id', $post->id)->update([
                        'search_text' => PostText::searchText($post->title, $post->excerpt, $post->body_html, is_array($faq) ? $faq : null),
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('blog_kit_posts', function (Blueprint $table) {
            $table->dropColumn('search_text');
        });
    }
};
