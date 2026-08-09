<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/OrderPolicyPageLoader.php';

/**
 * Tests for the pure row-building logic behind Discount Abuse and Tag
 * Policy Audit, which previously had only "missing credentials" wiring
 * tests (see docs/audit-test-coverage-gaps.md). All accessed via reflection
 * (private methods).
 */
class OrderPolicyChecksTest extends TestCase
{
    private static \ReflectionMethod $discountAbuse;
    private static \ReflectionMethod $tagPolicyHasRules;
    private static \ReflectionMethod $tagPolicyRows;
    private static \ReflectionMethod $parseTagPolicyConfig;
    private static \ReflectionMethod $orderTags;

    public static function setUpBeforeClass(): void
    {
        $ref = new \ReflectionClass(OrderPolicyPageLoader::class);
        self::$discountAbuse        = $ref->getMethod('buildDiscountAbuseRows');
        self::$tagPolicyHasRules    = $ref->getMethod('tagPolicyHasRules');
        self::$tagPolicyRows        = $ref->getMethod('buildTagPolicyRows');
        self::$parseTagPolicyConfig = $ref->getMethod('parseTagPolicyConfig');
        self::$orderTags            = $ref->getMethod('orderTags');
    }

    // ── Discount Abuse ───────────────────────────────────────────────────────

    private function discountOrder(string $email, array $overrides = []): array
    {
        return array_merge([
            'id'                 => 1,
            'name'               => '#1001',
            'email'              => $email,
            'created_at'         => '2026-06-01T10:00:00Z',
            'total_price'        => '50.00',
            'financial_status'   => 'paid',
            'fulfillment_status' => null,
            'discount_codes'     => [['code' => 'SAVE10']],
            'shipping_address'   => [
                'first_name' => 'Jane', 'last_name' => 'Doe',
                'address1' => '123 Main St', 'city' => 'Boston',
                'zip' => '02101', 'country_code' => 'US',
            ],
        ], $overrides);
    }

    public function testExactlyMinEmailsIsFlagged(): void
    {
        $orders = [
            $this->discountOrder('a@x.com'),
            $this->discountOrder('b@x.com'),
            $this->discountOrder('c@x.com'),
        ];

        $rows = self::$discountAbuse->invoke(null, $orders, 3);

        $this->assertCount(1, $rows);
        $this->assertSame(3, $rows[0]['email_count']);
    }

    public function testBelowMinEmailsIsNotFlagged(): void
    {
        $orders = [
            $this->discountOrder('a@x.com'),
            $this->discountOrder('b@x.com'),
        ];

        $rows = self::$discountAbuse->invoke(null, $orders, 3);

        $this->assertSame([], $rows);
    }

    public function testSameEmailReusedAtSameAddressCodeIsNotDoubleCounted(): void
    {
        $orders = [
            $this->discountOrder('a@x.com'),
            $this->discountOrder('a@x.com'),
            $this->discountOrder('a@x.com'),
        ];

        $rows = self::$discountAbuse->invoke(null, $orders, 3);

        $this->assertSame([], $rows, 'one distinct email across 3 orders should not count as 3 distinct customers');
    }

    public function testDifferentAddressesAreNotGroupedTogether(): void
    {
        $orders = [
            $this->discountOrder('a@x.com'),
            $this->discountOrder('b@x.com'),
            $this->discountOrder('c@x.com', ['shipping_address' => [
                'first_name' => 'Bob', 'last_name' => 'Smith',
                'address1' => '999 Other Ave', 'city' => 'Denver',
                'zip' => '80202', 'country_code' => 'US',
            ]]),
        ];

        $rows = self::$discountAbuse->invoke(null, $orders, 3);

        $this->assertSame([], $rows);
    }

    // ── Tag Policy Audit: configured flag ───────────────────────────────────

    public function testEmptyConfigHasNoRules(): void
    {
        $this->assertFalse(self::$tagPolicyHasRules->invoke(null, []));
    }

    public function testConfigWithOnlyRequiredHasRules(): void
    {
        $config = ['required' => [['when' => ['vip'], 'must_have' => ['priority']]]];
        $this->assertTrue(self::$tagPolicyHasRules->invoke(null, $config));
    }

    // ── Tag Policy Audit: required rule semantics ───────────────────────────

    private function tagOrder(array $tags, array $overrides = []): array
    {
        return array_merge([
            'id'         => 1,
            'name'       => '#1001',
            'created_at' => '2026-06-01T10:00:00Z',
            'tags'       => $tags,
        ], $overrides);
    }

    public function testAllTriggersAndMissingRequiredTagIsFlagged(): void
    {
        $config = ['required' => [['name' => 'VIP needs priority', 'when' => ['vip'], 'must_have' => ['priority']]]];
        $order  = $this->tagOrder(['vip']);

        $rows = self::$tagPolicyRows->invoke(null, [$order], $config);

        $this->assertCount(1, $rows);
        $this->assertSame('required', $rows[0]['violations'][0]['type']);
    }

    public function testAllTriggersAndAllRequiredPresentIsNotFlagged(): void
    {
        $config = ['required' => [['name' => 'VIP needs priority', 'when' => ['vip'], 'must_have' => ['priority']]]];
        $order  = $this->tagOrder(['vip', 'priority']);

        $rows = self::$tagPolicyRows->invoke(null, [$order], $config);

        $this->assertSame([], $rows);
    }

    public function testOnlySomeTriggersPresentRuleDoesNotEvaluate(): void
    {
        $config = ['required' => [['name' => 'r', 'when' => ['vip', 'wholesale'], 'must_have' => ['priority']]]];
        $order  = $this->tagOrder(['vip']); // missing 'wholesale' trigger

        $rows = self::$tagPolicyRows->invoke(null, [$order], $config);

        $this->assertSame([], $rows, 'rule should not evaluate when only some triggers are present');
    }

    // ── Tag Policy Audit: forbidden rule semantics ──────────────────────────

    public function testTwoForbiddenTagsCoOccurringIsFlagged(): void
    {
        $config = ['forbidden' => [['name' => 'conflict', 'tags' => ['wholesale', 'retail']]]];
        $order  = $this->tagOrder(['wholesale', 'retail']);

        $rows = self::$tagPolicyRows->invoke(null, [$order], $config);

        $this->assertCount(1, $rows);
        $this->assertSame('forbidden', $rows[0]['violations'][0]['type']);
    }

    public function testOnlyOneForbiddenTagPresentIsNotFlagged(): void
    {
        $config = ['forbidden' => [['name' => 'conflict', 'tags' => ['wholesale', 'retail']]]];
        $order  = $this->tagOrder(['wholesale']);

        $rows = self::$tagPolicyRows->invoke(null, [$order], $config);

        $this->assertSame([], $rows);
    }

    // ── parseTagPolicyConfig ─────────────────────────────────────────────────

    public function testMalformedJsonFallsBackToEmptyArray(): void
    {
        $this->assertSame([], self::$parseTagPolicyConfig->invoke(null, '{not valid json'));
    }

    public function testNonObjectJsonFallsBackToEmptyArray(): void
    {
        $this->assertSame([], self::$parseTagPolicyConfig->invoke(null, '"just a string"'));
    }

    public function testValidJsonIsDecoded(): void
    {
        $result = self::$parseTagPolicyConfig->invoke(null, '{"required":[{"name":"r"}]}');
        $this->assertSame(['required' => [['name' => 'r']]], $result);
    }

    // ── orderTags: comma-string vs array normalization ──────────────────────

    public function testCommaStringTagsAreNormalized(): void
    {
        $tags = self::$orderTags->invoke(null, ['tags' => 'vip, priority,  wholesale']);
        $this->assertSame(['vip', 'priority', 'wholesale'], $tags);
    }

    public function testArrayTagsPassThroughTrimmedAndFiltered(): void
    {
        $tags = self::$orderTags->invoke(null, ['tags' => [' vip ', '', 'priority']]);
        $this->assertSame(['vip', 'priority'], $tags);
    }
}
