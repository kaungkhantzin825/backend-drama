<?php

namespace Tests\Feature\Admin;

use App\Enums\DramaStatus;
use App\Models\Category;
use App\Models\Drama;
use App\Models\User;
use App\Models\WatchProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Admin content-management API.
 *
 * Covers the two things that actually break in a CMS: authorisation (who may
 * write) and invariants (slugs, counters, cascade deletes).
 */
class AdminContentTest extends TestCase
{
    use RefreshDatabase;

    // ─── Authorisation ────────────────────────────────────────────────────────

    public function test_guests_cannot_reach_the_admin_content_api(): void
    {
        $this->getJson('/admin/api/dramas')
            ->assertStatus(401)
            ->assertJsonPath('success', false);
    }

    public function test_mobile_users_cannot_reach_the_admin_content_api(): void
    {
        $user = User::factory()->guest()->create();

        $this->actingAs($user, 'web')
            ->getJson('/admin/api/dramas')
            ->assertStatus(401);
    }

    public function test_editors_may_manage_content(): void
    {
        $editor = User::factory()->editor()->create();

        $this->actingAs($editor, 'admin')
            ->postJson('/admin/api/categories', ['name' => 'Romance'])
            ->assertStatus(201);
    }

    // ─── Categories ───────────────────────────────────────────────────────────

    public function test_creating_a_category_derives_its_slug(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'admin')
            ->postJson('/admin/api/categories', [
                'name' => 'Contract Marriage',
                'sort_order' => 3,
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.name', 'Contract Marriage')
            ->assertJsonPath('data.slug', 'contract-marriage')
            ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('categories', ['slug' => 'contract-marriage']);
    }

    public function test_a_category_requires_a_name(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'admin')
            ->postJson('/admin/api/categories', [])
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['errors' => ['name']]]);
    }

    public function test_a_category_can_be_hidden(): void
    {
        $admin = User::factory()->admin()->create();
        $category = Category::factory()->create();

        $this->actingAs($admin, 'admin')
            ->patchJson("/admin/api/categories/{$category->id}", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertFalse($category->fresh()->is_active);
    }

    public function test_deleting_a_category_keeps_its_dramas(): void
    {
        $admin = User::factory()->admin()->create();
        $category = Category::factory()->create();
        $drama = Drama::factory()->inCategory($category)->create();

        $this->actingAs($admin, 'admin')
            ->deleteJson("/admin/api/categories/{$category->id}")
            ->assertOk();

        $this->assertDatabaseMissing('categories', ['id' => $category->id]);
        // The drama survives, unshelved.
        $this->assertDatabaseHas('dramas', ['id' => $drama->id, 'category_id' => null]);
    }

    // ─── Dramas ───────────────────────────────────────────────────────────────

    public function test_creating_a_drama_generates_a_unique_slug(): void
    {
        $admin = User::factory()->admin()->create();

        $first = $this->actingAs($admin, 'admin')
            ->postJson('/admin/api/dramas', ['title' => 'The CEO Bride'])
            ->assertStatus(201);

        $second = $this->actingAs($admin, 'admin')
            ->postJson('/admin/api/dramas', ['title' => 'The CEO Bride'])
            ->assertStatus(201);

        $this->assertSame('the-ceo-bride', $first->json('data.slug'));
        $this->assertSame('the-ceo-bride-2', $second->json('data.slug'));
    }

    public function test_a_drama_requires_a_title(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'admin')
            ->postJson('/admin/api/dramas', [])
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['errors' => ['title']]]);
    }

    public function test_a_new_drama_starts_as_a_draft(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'admin')
            ->postJson('/admin/api/dramas', ['title' => 'Unreleased'])
            ->assertStatus(201)
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.is_visible', false)
            ->assertJsonPath('data.published_at', null);
    }

    public function test_publishing_a_drama_stamps_the_release_date(): void
    {
        $admin = User::factory()->admin()->create();
        $drama = Drama::factory()->draft()->create();

        $this->actingAs($admin, 'admin')
            ->postJson("/admin/api/dramas/{$drama->id}/publish")
            ->assertOk()
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.is_visible', true);

        $this->assertNotNull($drama->fresh()->published_at);
    }

    public function test_counters_cannot_be_set_through_the_api(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin, 'admin')
            ->postJson('/admin/api/dramas', [
                'title' => 'Inflated',
                'view_count' => 9_999_999,
                'like_count' => 9_999_999,
            ])
            ->assertStatus(201);

        $this->assertSame(0, $response->json('data.view_count'));
        $this->assertSame(0, $response->json('data.like_count'));
    }

    public function test_unfeaturing_a_drama_is_persisted(): void
    {
        $admin = User::factory()->admin()->create();
        $drama = Drama::factory()->featured()->create();

        $this->actingAs($admin, 'admin')
            ->patchJson("/admin/api/dramas/{$drama->id}", ['is_featured' => false])
            ->assertOk()
            ->assertJsonPath('data.is_featured', false);

        $this->assertFalse($drama->fresh()->is_featured);
    }

    public function test_drama_listing_can_be_filtered_by_status(): void
    {
        $admin = User::factory()->admin()->create();

        Drama::factory()->create(['title' => 'Live']);
        Drama::factory()->draft()->create(['title' => 'Draft']);
        Drama::factory()->archived()->create(['title' => 'Archived']);

        $response = $this->actingAs($admin, 'admin')
            ->getJson('/admin/api/dramas?status='.DramaStatus::Draft->value)
            ->assertOk();

        $this->assertSame(1, $response->json('meta.total'));
        $this->assertSame('Draft', $response->json('data.data.0.title'));
    }

    public function test_a_video_url_and_duration_can_be_set(): void
    {
        $admin = User::factory()->admin()->create();

        $created = $this->actingAs($admin, 'admin')
            ->postJson('/admin/api/dramas', [
                'title' => 'Standalone',
                'video_url' => 'https://cdn.test/standalone.m3u8',
                'duration_seconds' => 125,
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.video_url', 'https://cdn.test/standalone.m3u8')
            ->assertJsonPath('data.duration_seconds', 125)
            ->assertJsonPath('data.duration_label', '2:05');

        $this->actingAs($admin, 'admin')
            ->patchJson('/admin/api/dramas/'.$created->json('data.id'), [
                'video_url' => 'https://www.youtube.com/embed/abcdefgh',
            ])
            ->assertOk()
            ->assertJsonPath('data.video_url', 'https://www.youtube.com/embed/abcdefgh')
            ->assertJsonPath('data.duration_seconds', 125);
    }

    public function test_a_duration_must_be_sane(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'admin')
            ->postJson('/admin/api/dramas', ['title' => 'Too long', 'duration_seconds' => 99999])
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['errors' => ['duration_seconds']]]);
    }

    public function test_drama_detail_returns_the_video(): void
    {
        $admin = User::factory()->admin()->create();
        $drama = Drama::factory()->create(['video_url' => 'https://cdn.test/one.m3u8']);

        $this->actingAs($admin, 'admin')
            ->getJson("/admin/api/dramas/{$drama->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $drama->id)
            ->assertJsonPath('data.video_url', 'https://cdn.test/one.m3u8');
    }

    public function test_deleting_a_drama_deletes_its_watch_history(): void
    {
        $admin = User::factory()->admin()->create();
        $drama = Drama::factory()->create();
        $viewer = User::factory()->create();

        WatchProgress::query()->create([
            'user_id' => $viewer->id,
            'drama_id' => $drama->id,
            'position_seconds' => 30,
            'duration_seconds' => 120,
        ]);

        $this->actingAs($admin, 'admin')
            ->deleteJson("/admin/api/dramas/{$drama->id}")
            ->assertOk();

        $this->assertDatabaseMissing('dramas', ['id' => $drama->id]);
        $this->assertDatabaseMissing('watch_progress', ['drama_id' => $drama->id]);
    }

    public function test_the_episode_admin_endpoints_are_gone(): void
    {
        $admin = User::factory()->admin()->create();
        $drama = Drama::factory()->create();

        $this->actingAs($admin, 'admin')
            ->postJson("/admin/api/dramas/{$drama->id}/episodes", ['title' => 'One'])
            ->assertStatus(404);
    }
}
