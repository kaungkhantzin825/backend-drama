<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Episodes are gone: every title is now one standalone video, shown in a
     * TikTok-style feed.
     *
     * Existing content is split, not dropped. A drama keeps its first episode
     * as its own video, and every later episode becomes a new title of its own
     * (same category, artwork and status), so nothing that was uploaded is
     * lost. Watch progress follows its episode to the new title.
     *
     * Watch-time rewards move from "minutes of one episode" to a running total
     * per user (`watch_reward_states`), so a minute can be completed across
     * several videos.
     */
    public function up(): void
    {
        Schema::table('dramas', function (Blueprint $table) {
            $table->string('video_url')->nullable()->after('trailer_url');
            $table->unsignedInteger('duration_seconds')->default(0)->after('video_url');
        });

        $this->splitEpisodesIntoVideos();

        Schema::table('watch_progress', function (Blueprint $table) {
            $table->dropForeign(['episode_id']);
        });

        Schema::table('watch_progress', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'episode_id']);
        });

        Schema::table('watch_progress', function (Blueprint $table) {
            $table->dropColumn(['episode_id', 'credited_intervals']);
            // One row per (user, video) now. Replaces the plain index.
            $table->unique(['user_id', 'drama_id']);
        });

        Schema::table('watch_progress', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'drama_id']);
        });

        Schema::dropIfExists('episode_unlocks');
        Schema::dropIfExists('episodes');

        Schema::table('dramas', function (Blueprint $table) {
            $table->dropColumn('total_episodes');
        });

        Schema::create('watch_reward_states', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();

            // Watch time banked toward the next reward, in seconds. Carries
            // over from one video to the next.
            $table->unsignedInteger('carry_seconds')->default(0);

            // Lifetime count of paid intervals; numbers the idempotency keys.
            $table->unsignedInteger('intervals_paid')->default(0);

            // Watch time has been credited up to this instant. Credits may not
            // run ahead of the wall clock, so a scripted client cannot earn a
            // minute in less than a minute.
            $table->timestamp('credited_until')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Restores the episode schema with each video as episode 1 of its own
     * drama. The original series grouping is not recoverable.
     */
    public function down(): void
    {
        Schema::dropIfExists('watch_reward_states');

        Schema::table('dramas', function (Blueprint $table) {
            $table->unsignedInteger('total_episodes')->default(0)->after('release_year');
        });

        Schema::create('episodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('drama_id')->constrained('dramas')->cascadeOnDelete();
            $table->unsignedInteger('episode_number');
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->string('video_url')->nullable();
            $table->string('thumbnail_url')->nullable();
            $table->unsignedInteger('duration_seconds')->default(0);
            $table->boolean('is_free')->default(true);
            $table->unsignedInteger('unlock_cost_points')->default(0);
            $table->unsignedBigInteger('view_count')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique(['drama_id', 'episode_number']);
            $table->index(['drama_id', 'episode_number']);
        });

        Schema::create('episode_unlocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('episode_id')->constrained('episodes')->cascadeOnDelete();
            $table->unsignedInteger('points_spent')->default(0);
            $table->timestamp('created_at')->nullable();
            $table->unique(['user_id', 'episode_id']);
            $table->index(['episode_id', 'created_at']);
        });

        Schema::table('watch_progress', function (Blueprint $table) {
            $table->unsignedBigInteger('episode_id')->nullable()->after('user_id');
            $table->unsignedInteger('credited_intervals')->default(0)->after('duration_seconds');
            $table->index(['user_id', 'drama_id']);
        });

        $now = now();

        foreach (DB::table('dramas')->orderBy('id')->get() as $video) {
            $episodeId = DB::table('episodes')->insertGetId([
                'drama_id' => $video->id,
                'episode_number' => 1,
                'video_url' => $video->video_url,
                'thumbnail_url' => $video->cover_image,
                'duration_seconds' => $video->duration_seconds,
                'published_at' => $video->published_at,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('dramas')->where('id', $video->id)->update(['total_episodes' => 1]);
            DB::table('watch_progress')->where('drama_id', $video->id)->update(['episode_id' => $episodeId]);
        }

        Schema::table('watch_progress', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'drama_id']);
            $table->unsignedBigInteger('episode_id')->nullable(false)->change();
            $table->foreign('episode_id')->references('id')->on('episodes')->cascadeOnDelete();
            $table->unique(['user_id', 'episode_id']);
        });

        Schema::table('dramas', function (Blueprint $table) {
            $table->dropColumn(['video_url', 'duration_seconds']);
        });
    }

    /**
     * Episode 1 moves onto its drama; every later episode becomes a new title.
     */
    private function splitEpisodesIntoVideos(): void
    {
        $now = now();

        foreach (DB::table('dramas')->orderBy('id')->get() as $drama) {
            $episodes = DB::table('episodes')
                ->where('drama_id', $drama->id)
                ->orderBy('episode_number')
                ->get();

            foreach ($episodes as $position => $episode) {
                if ($position === 0) {
                    // Progress rows for this episode already point at the drama.
                    DB::table('dramas')->where('id', $drama->id)->update([
                        'video_url' => $episode->video_url,
                        'duration_seconds' => $episode->duration_seconds,
                        'cover_image' => $drama->cover_image ?? $episode->thumbnail_url,
                    ]);

                    continue;
                }

                $title = $this->partTitle($drama->title, $episode);

                $videoId = DB::table('dramas')->insertGetId([
                    'category_id' => $drama->category_id,
                    'title' => $title,
                    'slug' => $this->uniqueSlug($title),
                    'synopsis' => $episode->description ?: $drama->synopsis,
                    'cover_image' => $episode->thumbnail_url ?: $drama->cover_image,
                    'banner_image' => $drama->banner_image,
                    'video_url' => $episode->video_url,
                    'duration_seconds' => $episode->duration_seconds,
                    'language' => $drama->language,
                    'release_year' => $drama->release_year,
                    'status' => $drama->status,
                    // One featured slot per original series, not one per part.
                    'is_featured' => false,
                    'view_count' => $episode->view_count,
                    'tags' => $drama->tags,
                    'published_at' => $this->later($drama->published_at, $episode->published_at),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                DB::table('watch_progress')
                    ->where('episode_id', $episode->id)
                    ->update(['drama_id' => $videoId]);
            }
        }
    }

    /**
     * "Series · The Reckoning", or "Series · Part 3" for a generic title.
     */
    private function partTitle(string $series, object $episode): string
    {
        $title = trim((string) $episode->title);

        if ($title === '' || preg_match('/^(episode|ep\.?)\s*\d+$/i', $title) === 1) {
            $title = 'Part '.$episode->episode_number;
        }

        return Str::limit($series.' · '.$title, 250, '');
    }

    private function uniqueSlug(string $title): string
    {
        $base = Str::slug($title) ?: 'video';
        $slug = $base;
        $suffix = 2;

        while (DB::table('dramas')->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }

    /**
     * A part releases when both its series and the episode itself have.
     */
    private function later(?string $first, ?string $second): ?string
    {
        if ($first === null || $second === null) {
            return $first ?? $second;
        }

        return max($first, $second);
    }
};
