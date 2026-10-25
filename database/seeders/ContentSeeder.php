<?php

namespace Database\Seeders;

use App\Enums\DramaStatus;
use App\Models\Category;
use App\Models\Drama;
use Illuminate\Database\Seeder;

/**
 * Demo catalogue: categories and standalone videos.
 *
 * The data is deliberately realistic — vertical short-drama titles with real
 * synopses — so the Flutter client and the admin panel can be developed
 * against a believable catalogue instead of lorem ipsum.
 *
 * Every write is keyed on a stable slug, so re-running the seeder updates in
 * place rather than duplicating content.
 */
class ContentSeeder extends Seeder
{
    /**
     * Categories in display order.
     *
     * @var list<array{name: string, slug: string, description: string}>
     */
    private const CATEGORIES = [
        ['name' => 'Romance', 'slug' => 'romance', 'description' => 'Slow burns, second chances and sweeping love stories.'],
        ['name' => 'Revenge', 'slug' => 'revenge', 'description' => 'Betrayed once, never again. The comeback arcs.'],
        ['name' => 'CEO', 'slug' => 'ceo', 'description' => 'Boardrooms, power plays and the boss who falls first.'],
        ['name' => 'Billionaire', 'slug' => 'billionaire', 'description' => 'Old money, hidden heirs and impossible wealth.'],
        ['name' => 'Rebirth', 'slug' => 'rebirth', 'description' => 'A second life, and this time they know everything.'],
        ['name' => 'Contract Marriage', 'slug' => 'contract-marriage', 'description' => 'Signed for convenience, undone by feelings.'],
    ];

    /**
     * @var list<array{title: string, slug: string, category: string, synopsis: string, featured: bool, tags: list<string>}>
     */
    private const VIDEOS = [
        [
            'title' => 'Married to My Billionaire Boss',
            'slug' => 'married-to-my-billionaire-boss',
            'category' => 'ceo',
            'synopsis' => 'When a mix-up at city hall leaves intern Lila Chen married to the coldest CEO in the country, she expects a quick annulment. Instead he offers a deal: six months, one penthouse, and nobody at the office can ever know. But the man behind the closed boardroom door is nothing like the monster the tabloids describe.',
            'featured' => true,
            'tags' => ['ceo', 'contract-marriage', 'romance'],
        ],
        [
            'title' => "The CEO's Secret Bride",
            'slug' => 'the-ceos-secret-bride',
            'category' => 'ceo',
            'synopsis' => 'Three years ago she signed her name on a marriage certificate and disappeared. Now she is back as the consultant his company cannot afford to lose — and the only person who knows the truth about the night that ruined his family.',
            'featured' => false,
            'tags' => ['ceo', 'hidden-identity', 'romance'],
        ],
        [
            'title' => 'Rebirth of the Vengeful Heiress',
            'slug' => 'rebirth-of-the-vengeful-heiress',
            'category' => 'rebirth',
            'synopsis' => 'They pushed her off the rooftop of her own building. She wakes up ten years earlier, on the morning of the engagement party, with every memory of how it ends. This time she is not the one who falls.',
            'featured' => true,
            'tags' => ['rebirth', 'revenge', 'heiress'],
        ],
        [
            'title' => 'Contract Marriage with the Cold Tycoon',
            'slug' => 'contract-marriage-with-the-cold-tycoon',
            'category' => 'contract-marriage',
            'synopsis' => 'One year. Two signatures. Three rules, the first of which is "never fall in love". Rule one does not survive the week.',
            'featured' => false,
            'tags' => ['contract-marriage', 'billionaire', 'romance'],
        ],
        [
            'title' => 'Revenge of the Abandoned Wife',
            'slug' => 'revenge-of-the-abandoned-wife',
            'category' => 'revenge',
            'synopsis' => 'He left her with nothing but a divorce paper and a debt. Five years later she owns the bank that holds his mortgage.',
            'featured' => false,
            'tags' => ['revenge', 'comeback', 'romance'],
        ],
        [
            'title' => 'Hidden Heir of the Morgan Family',
            'slug' => 'hidden-heir-of-the-morgan-family',
            'category' => 'billionaire',
            'synopsis' => 'The family spent thirty years pretending he did not exist. The will names him sole heir. Now every cousin in the estate has a reason to make him disappear.',
            'featured' => false,
            'tags' => ['billionaire', 'hidden-identity', 'family'],
        ],
        [
            'title' => "My Ex-Husband's Regret",
            'slug' => 'my-ex-husbands-regret',
            'category' => 'revenge',
            'synopsis' => 'He signed the papers without reading them. He should have. Now the woman he discarded runs the only company that can save his.',
            'featured' => false,
            'tags' => ['revenge', 'second-chance', 'romance'],
        ],
        [
            'title' => 'Love After Betrayal',
            'slug' => 'love-after-betrayal',
            'category' => 'romance',
            'synopsis' => 'She caught them on her wedding day and walked out in the dress. The stranger who held the door open turns out to be the one person her ex is terrified of.',
            'featured' => false,
            'tags' => ['romance', 'second-chance', 'betrayal'],
        ],
    ];

    public function run(): void
    {
        $categories = $this->seedCategories();

        foreach (self::VIDEOS as $index => $definition) {
            $this->seedVideo($definition, $categories[$definition['category']] ?? null, $index);
        }
    }

    /**
     * @return array<string, Category> keyed by slug
     */
    private function seedCategories(): array
    {
        $out = [];

        foreach (self::CATEGORIES as $position => $definition) {
            $out[$definition['slug']] = Category::query()->updateOrCreate(
                ['slug' => $definition['slug']],
                [
                    'name' => $definition['name'],
                    'description' => $definition['description'],
                    'cover_image' => 'https://cdn.dramaloop.test/categories/'.$definition['slug'].'.jpg',
                    'sort_order' => $position,
                    'is_active' => true,
                ],
            );
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function seedVideo(array $definition, ?Category $category, int $index): Drama
    {
        return Drama::query()->updateOrCreate(
            ['slug' => $definition['slug']],
            [
                'category_id' => $category?->id,
                'title' => $definition['title'],
                'synopsis' => $definition['synopsis'],
                'cover_image' => 'https://cdn.dramaloop.test/dramas/'.$definition['slug'].'/cover.jpg',
                'banner_image' => 'https://cdn.dramaloop.test/dramas/'.$definition['slug'].'/banner.jpg',
                'trailer_url' => 'https://cdn.dramaloop.test/dramas/'.$definition['slug'].'/trailer.m3u8',
                'video_url' => 'https://cdn.dramaloop.test/dramas/'.$definition['slug'].'/video.m3u8',
                'duration_seconds' => 95 + (($index * 17) % 70),
                'language' => 'en',
                'release_year' => 2026,
                'status' => DramaStatus::Published,
                'is_featured' => $definition['featured'],
                'view_count' => 12000 + ($index * 7351),
                'like_count' => 900 + ($index * 431),
                'rating' => round(4.2 + (($index % 5) * 0.15), 2),
                'tags' => $definition['tags'],
                'published_at' => now()->subDays(30 - $index),
            ],
        );
    }
}
