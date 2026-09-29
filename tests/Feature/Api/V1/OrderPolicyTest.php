<?php

namespace Tests\Feature\Api\V1;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_view_and_delete_own_order_but_cannot_update_it(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->for($user)->create();

        $this->assertTrue($user->can('view', $order));
        $this->assertTrue($user->can('delete', $order));
        $this->assertTrue($user->cannot('update', $order));
        $this->assertTrue($user->can('create', Order::class));
        $this->assertTrue($user->can('viewAny', Order::class));
    }

    public function test_user_cannot_view_update_or_delete_another_users_order(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $order = Order::factory()->for($owner)->create();

        $this->assertTrue($other->cannot('view', $order));
        $this->assertTrue($other->cannot('update', $order));
        $this->assertTrue($other->cannot('delete', $order));
    }
}
