<?php

namespace Tests\Feature;

use Tests\TestCase;

class TagPolicyConfigurationTest extends TestCase
{
    public function test_default_policy_is_safely_disabled(): void
    {
        $this->assertSame([], config('tag-policy.required'));
        $this->assertSame([], config('tag-policy.forbidden'));
    }
}
