<?php

namespace Database\Seeders;

use App\Enums\DramaStatus;
use App\Models\Category;
use App\Models\Drama;
use Illuminate\Database\Seeder;

/**
 * Three demo titles backed by real YouTube videos.
 *
 * Exists so the app has something that actually plays during development —
 * the main catalogue points at `cdn.test` hosts that do not resolve. Posters
 * come from YouTube's thumbnail service, so the cards render real artwork
 * instead of the initials fallback.
 *
 * Re-runnable: keyed on slug via `updateOrCreate`.
 */
class DemoVideoSeeder extends Seeder
{
    /**
     * @var list<array{slug: string, title: string, video: string, category: string, synopsis: string, tags: list<string>}>
     */
    private const DEMOS = [
        [
            'slug' => 'demo-the-substitute-bride',
            'title' => 'The Billionaire\'s Substitute Bride',
            'video' => 'https://www.youtube.com/embed/zBjJUV-lzHo',
            'category' => 'romance',
            'synopsis' => 'Elena agrees to marry a reclusive billionaire in her sister\'s place — only to discover the man she is bound to has been hiding a disability that changes everything.',
            'tags' => ['ceo', 'contract-marriage', 'revenge'],
        ],
        [
            'slug' => 'demo-midnight-oath',
            'title' => 'Midnight Oath',
            'video' => 'https://www.youtube.com/embed/2Z1UYAFdaIg',
            'category' => 'suspense',
            'synopsis' => 'A detective wakes with no memory of the last twelve hours and a stranger\'s blood on his hands. Every clue he finds points back at himself.',
            'tags' => ['mystery', 'thriller', 'amnesia'],
        ],
        [
            'slug' => 'demo-second-chance-summer',
            'title' => 'Second Chance Summer',
            'video' => 'https://www.youtube.com/embed/BSKWdAXx_mI',
            'category' => 'family',
            'synopsis' => 'Ten years after walking out on her hometown, Mei returns for one summer to settle her mother\'s estate — and finds the life she ran from waiting for her.',
            'tags' => ['second-chance', 'small-town', 'heartfelt'],
        ],
    ];

    public function run(): void
    {
        foreach (self::DEMOS as $position => $demo) {
            $category = Category::query()->firstOrCreate(
                ['slug' => $demo['category']],
                [
                    'name' => ucfirst($demo['category']),
                    'sort_order' => $position,
                    'is_active' => true,
                ],
            );

            $videoId = $this->youtubeId($demo['video']);
            $thumbnail = $videoId !== null
                ? "https://img.youtube.com/vi/{$videoId}/maxresdefault.jpg"
                : null;

            Drama::query()->updateOrCreate(
                ['slug' => $demo['slug']],
                [
                    'category_id' => $category->id,
                    'title' => $demo['title'],
                    'synopsis' => $demo['synopsis'],
                    'cover_image' => $thumbnail,
                    'banner_image' => $thumbnail,
                    'trailer_url' => $demo['video'],
                    'video_url' => $demo['video'],
                    'duration_seconds' => 180,
                    'language' => 'en',
                    'release_year' => (int) date('Y'),
                    'status' => DramaStatus::Published->value,
                    'is_featured' => $position === 0,
                    'tags' => $demo['tags'],
                    'published_at' => now()->subDays(count(self::DEMOS) - $position),
                ],
            );
        }

        $this->parkPlaceholderCatalogue();
    }

    /**
     * Park the placeholder catalogue as drafts.
     *
     * `ContentSeeder` creates eight titles whose videos point at
     * `cdn.dramaloop.test`, a host that does not exist. Playback of those would
     * die on a network error, so while the playable demos are seeded the
     * placeholders stay in the database — the admin panel still sees them, and
     * their watch history and reward rows stay intact — but they are no longer
     * published to the app. Flip them back to `published` once real media is
     * uploaded.
     */
    private function parkPlaceholderCatalogue(): void
    {
        Drama::query()
            ->whereNotIn('slug', array_column(self::DEMOS, 'slug'))
            ->where('status', DramaStatus::Published->value)
            ->where('video_url', 'like', '%cdn.dramaloop.test%')
            ->update([
                'status' => DramaStatus::Draft->value,
                'is_featured' => false,
            ]);
    }

    /**
     * Pull the video id out of either an /embed/ or a /watch?v= URL.
     */
    private function youtubeId(string $url): ?string
    {
        if (preg_match('~/embed/([A-Za-z0-9_-]{6,})~', $url, $matches) === 1) {
            return $matches[1];
        }

        if (preg_match('~[?&]v=([A-Za-z0-9_-]{6,})~', $url, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }
}
