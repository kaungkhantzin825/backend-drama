<?php

namespace Tests\Feature\Api\V1;

use App\Models\Category;
use App\Models\Drama;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Public catalogue endpoints.
 *
 * The recurring theme in these tests is visibility: a draft, archived or
 * not-yet-released title must never reach the mobile client. Every video is
 * free, so list items carry their stream URL for the swipe feed.
 */
class ContentTest extends TestCase
{
    use RefreshDatabase;

    // ─── Home ─────────────────────────────────────────────────────────────────

    public function test_home_returns_every_rail_in_one_response(): void
    {
        Drama::factory()->featured()->create();
        Drama::factory()->create();
        Category::factory()->create(['name' => 'Romance']);

        $this->getJson('/api/v1/home')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'featured' => [['id', 'title', 'slug', 'cover_image', 'video_url', 'duration_seconds']],
                    'latest',
                    'trending',
                    'categories' => [['id', 'name', 'slug']],
                ],
            ]);
    }

    public function test_home_never_includes_unpublished_dramas(): void
    {
        Drama::factory()->create(['title' => 'Live Title']);
        Drama::factory()->draft()->create(['title' => 'Draft Title']);
        Drama::factory()->archived()->create(['title' => 'Archived Title']);
        Drama::factory()->scheduled()->create(['title' => 'Future Title']);

        $body = $this->getJson('/api/v1/home')->assertOk()->json('data');

        $titles = collect($body['latest'])->pluck('title');

        $this->assertTrue($titles->contains('Live Title'));
        $this->assertFalse($titles->contains('Draft Title'));
        $this->assertFalse($titles->contains('Archived Title'));
        $this->assertFalse($titles->contains('Future Title'));
    }

    // ─── Categories ───────────────────────────────────────────────────────────

    public function test_categories_list_only_active_shelves_in_display_order(): void
    {
        Category::factory()->create(['name' => 'Second', 'slug' => 'second', 'sort_order' => 2]);
        Category::factory()->create(['name' => 'First', 'slug' => 'first', 'sort_order' => 1]);
        Category::factory()->inactive()->create(['name' => 'Hidden', 'slug' => 'hidden']);

        $response = $this->getJson('/api/v1/categories')->assertOk();

        $slugs = collect($response->json('data'))->pluck('slug');

        $this->assertSame(['first', 'second'], $slugs->all());
        $this->assertNotContains('hidden', $slugs);
    }

    public function test_category_drama_count_ignores_drafts(): void
    {
        $category = Category::factory()->create(['slug' => 'romance']);

        Drama::factory()->inCategory($category)->create();
        Drama::factory()->inCategory($category)->draft()->create();

        $body = $this->getJson('/api/v1/categories')->assertOk()->json('data.0');

        $this->assertSame(1, $body['drama_count']);
    }

    // ─── Drama index ──────────────────────────────────────────────────────────

    public function test_drama_index_returns_a_paginated_envelope(): void
    {
        Drama::factory()->count(3)->create();

        $response = $this->getJson('/api/v1/dramas')->assertOk();

        $response->assertJsonPath('success', true)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonStructure([
                'data' => ['data' => [['id', 'title', 'slug', 'tagline', 'category']]],
                'meta' => ['current_page', 'last_page', 'per_page', 'total', 'from', 'to'],
            ]);
    }

    public function test_drama_index_hides_unpublished_titles(): void
    {
        Drama::factory()->create(['title' => 'Visible']);
        Drama::factory()->draft()->create(['title' => 'Invisible']);
        Drama::factory()->archived()->create(['title' => 'Gone']);

        $response = $this->getJson('/api/v1/dramas')->assertOk();

        $this->assertSame(1, $response->json('meta.total'));
        $this->assertSame('Visible', $response->json('data.data.0.title'));
    }

    public function test_drama_index_filters_by_category_slug(): void
    {
        $romance = Category::factory()->create(['slug' => 'romance']);
        $revenge = Category::factory()->create(['slug' => 'revenge']);

        Drama::factory()->inCategory($romance)->create(['title' => 'A Romance']);
        Drama::factory()->inCategory($revenge)->create(['title' => 'A Revenge']);

        $response = $this->getJson('/api/v1/dramas?category=romance')->assertOk();

        $this->assertSame(1, $response->json('meta.total'));
        $this->assertSame('A Romance', $response->json('data.data.0.title'));
    }

    public function test_drama_index_filters_by_category_id(): void
    {
        $romance = Category::factory()->create(['slug' => 'romance']);
        Drama::factory()->inCategory($romance)->create();
        Drama::factory()->create();

        $response = $this->getJson('/api/v1/dramas?category='.$romance->id)->assertOk();

        $this->assertSame(1, $response->json('meta.total'));
    }

    public function test_drama_index_searches_title_and_synopsis(): void
    {
        Drama::factory()->create(['title' => 'Billionaire Boss', 'synopsis' => 'A corner office.']);
        Drama::factory()->create(['title' => 'Other Title', 'synopsis' => 'A billionaire arrives.']);

        $response = $this->getJson('/api/v1/dramas?q=billionaire')->assertOk();

        $this->assertSame(2, $response->json('meta.total'));
    }

    public function test_drama_index_filters_featured(): void
    {
        Drama::factory()->featured()->create();
        Drama::factory()->create();

        $response = $this->getJson('/api/v1/dramas?featured=1')->assertOk();

        $this->assertSame(1, $response->json('meta.total'));
    }

    public function test_drama_index_sorts_by_popularity(): void
    {
        Drama::factory()->create(['title' => 'Quiet', 'view_count' => 10]);
        Drama::factory()->create(['title' => 'Huge', 'view_count' => 900000]);

        $response = $this->getJson('/api/v1/dramas?sort=popular')->assertOk();

        $this->assertSame('Huge', $response->json('data.data.0.title'));
    }

    public function test_drama_index_rejects_an_unknown_sort(): void
    {
        $this->getJson('/api/v1/dramas?sort=; DROP TABLE dramas')
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['data' => ['errors' => ['sort']]]);
    }

    public function test_drama_index_rejects_a_one_character_search(): void
    {
        $this->getJson('/api/v1/dramas?q=a')->assertStatus(422);
    }

    public function test_drama_index_rejects_an_oversized_page_size(): void
    {
        Drama::factory()->count(3)->create();

        $this->getJson('/api/v1/dramas?per_page=500')
            ->assertStatus(422);
    }

    public function test_drama_index_items_carry_their_stream_url(): void
    {
        Drama::factory()->create([
            'video_url' => 'https://cdn.test/feed.m3u8',
            'duration_seconds' => 95,
        ]);

        $this->getJson('/api/v1/dramas')
            ->assertOk()
            ->assertJsonPath('data.data.0.video_url', 'https://cdn.test/feed.m3u8')
            ->assertJsonPath('data.data.0.duration_seconds', 95)
            ->assertJsonPath('data.data.0.duration_label', '1:35');
    }

    // ─── Drama detail ─────────────────────────────────────────────────────────

    public function test_drama_detail_returns_the_video_and_synopsis(): void
    {
        Drama::factory()->create([
            'slug' => 'my-drama',
            'title' => 'My Drama',
            'synopsis' => 'The whole story.',
            'video_url' => 'https://cdn.test/my-drama.m3u8',
            'duration_seconds' => 120,
        ]);

        $this->getJson('/api/v1/dramas/my-drama')
            ->assertOk()
            ->assertJsonPath('data.slug', 'my-drama')
            ->assertJsonPath('data.title', 'My Drama')
            ->assertJsonPath('data.synopsis', 'The whole story.')
            ->assertJsonPath('data.video_url', 'https://cdn.test/my-drama.m3u8')
            ->assertJsonPath('data.duration_seconds', 120)
            ->assertJsonPath('data.is_favorite', false)
            ->assertJsonMissingPath('data.episodes');
    }

    public function test_drama_detail_returns_404_for_an_unpublished_drama(): void
    {
        Drama::factory()->draft()->create(['slug' => 'hidden-drama']);

        $this->getJson('/api/v1/dramas/hidden-drama')
            ->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Drama not found.');
    }

    public function test_drama_detail_returns_404_for_a_scheduled_drama(): void
    {
        Drama::factory()->scheduled()->create(['slug' => 'later']);

        $this->getJson('/api/v1/dramas/later')->assertStatus(404);
    }

    public function test_drama_detail_returns_404_for_an_unknown_slug(): void
    {
        $this->getJson('/api/v1/dramas/nope')->assertStatus(404);
    }

    public function test_drama_detail_records_a_view(): void
    {
        $drama = Drama::factory()->create(['slug' => 'watched', 'view_count' => 5]);

        $this->getJson('/api/v1/dramas/watched')->assertOk();

        $this->assertSame(6, $drama->fresh()->view_count);
    }

    public function test_the_episode_endpoints_are_gone(): void
    {
        Drama::factory()->create(['slug' => 'single']);

        $this->getJson('/api/v1/dramas/single/episodes')->assertStatus(404);
        $this->getJson('/api/v1/dramas/single/episodes/1')->assertStatus(404);
    }
}
