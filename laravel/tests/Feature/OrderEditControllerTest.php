<?php

namespace Tests\Feature;

use Tests\TestCase;

class OrderEditControllerTest extends TestCase
{
    /**
     * A basic feature test example.
     */
    public function test_guest_is_redirected(): void
    {
        $this->get('/reports/order-edits')->assertRedirect(route('login'));
    }
}
