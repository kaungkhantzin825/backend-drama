<?php

namespace Tests\Unit;

use App\Enums\UserRole;
use PHPUnit\Framework\TestCase;

class UserRoleTest extends TestCase
{
    public function test_admin_can_manage_revenue(): void
    {
        $this->assertTrue(UserRole::Admin->canManageRevenue());
        $this->assertFalse(UserRole::Editor->canManageRevenue());
    }

    public function test_values_lists_both_roles(): void
    {
        $this->assertSame(['admin', 'editor'], UserRole::values());
    }

    public function test_labels_are_human_readable(): void
    {
        $labels = UserRole::labels();

        $this->assertSame('Administrator', $labels['admin']);
        $this->assertSame('Content Editor', $labels['editor']);
    }
}
