<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * The site root is the admin panel entry point, not a framework splash page.
     */
    public function test_the_root_url_redirects_to_the_admin_panel(): void
    {
        $this->get('/')->assertRedirect(route('admin.dashboard'));
    }

    /**
     * Guests are bounced to the admin login screen.
     */
    public function test_the_admin_panel_requires_authentication(): void
    {
        $this->get(route('admin.dashboard'))->assertRedirect(route('admin.login'));
    }
}
