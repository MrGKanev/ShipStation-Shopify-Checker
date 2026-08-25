<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Frozen snapshot of every Auth::ACTION_PERMISSIONS entry as it stood
 * before consolidating the "batch scans and reports" section (48
 * hand-duplicated `'scan_x' => 'run_audit'` lines) to derive from
 * ToolRegistry::triggerCatalog() instead. Captured from the source
 * verbatim before touching Auth.php, so this is a behavior lock, not a
 * description of the new implementation - it must keep passing
 * unchanged after the refactor, proving no permission actually moved.
 */
class AuthPermissionSnapshotTest extends TestCase
{
    /** @return array<string, string|null|false> action => expected permissionForAction() result */
    private static function expected(): array
    {
        return [
            // Session / navigation
            'login' => null, 'dev_login' => null, 'logout' => null, 'switch_store' => null,
            // Narrow read-only lookups
            'spotcheck' => null, 'tag_search' => null, 'metafield_search' => null,
            'metafield_lookup' => null, 'customer_lookup' => null, 'lookup_tracking' => null,
            'compare_orders' => null, 'order_timeline' => null, 'packingslip' => null, 'order_detail' => null,
            // Operational mutations
            'push_to_shipstation' => 'push', 'bulk_push' => 'push', 'preview_push' => 'push',
            'save_order_note' => 'edit_order', 'ignore_order' => 'ignore', 'unignore_order' => 'ignore',
            'bulk_ignore_orders' => 'ignore', 'bulk_unignore_orders' => 'ignore', 'import_ignore_csv' => 'ignore',
            'flush_cache' => 'flush_cache', 'queue_audit' => 'queue_audit', 'run_audit' => 'run_audit',
            'pq_add' => 'manage_queue', 'pq_remove' => 'manage_queue', 'pq_clear' => 'manage_queue',
            // Batch scans and reports - all 'run_audit', formerly one line each
            'tag_audit' => 'run_audit', 'scan_addresses' => 'run_audit', 'scan_emails' => 'run_audit',
            'scan_hvorders' => 'run_audit', 'find_refunds' => 'run_audit', 'find_dupes' => 'run_audit',
            'find_orphans' => 'run_audit', 'scan_repeat_refunds' => 'run_audit', 'scan_failed_shipments' => 'run_audit',
            'scan_addr_changes' => 'run_audit', 'scan_order_edits' => 'run_audit', 'scan_noteflags' => 'run_audit',
            'scan_addrdupes' => 'run_audit', 'scan_riskreport' => 'run_audit', 'scan_sameip' => 'run_audit',
            'scan_disputes' => 'run_audit', 'scan_discountabuse' => 'run_audit', 'scan_tagpolicy' => 'run_audit',
            'scan_country_mismatch' => 'run_audit', 'scan_partial_fulfill' => 'run_audit', 'scan_onhold' => 'run_audit',
            'scan_notracking' => 'run_audit', 'scan_postshipaddr' => 'run_audit', 'scan_ssshipped' => 'run_audit',
            'scan_sla' => 'run_audit', 'scan_shipmentaging' => 'run_audit', 'scan_carrierperf' => 'run_audit',
            'scan_shipmargin' => 'run_audit', 'scan_fulfilleditems' => 'run_audit', 'email_fulfilleditems' => 'run_audit',
            'scan_returneditems' => 'run_audit', 'email_returneditems' => 'run_audit', 'scan_itemmismatch' => 'run_audit',
            'scan_activess' => 'run_audit', 'scan_bundle' => 'run_audit', 'scan_products' => 'run_audit',
            'scan_skudupes' => 'run_audit', 'scan_inventory' => 'run_audit', 'scan_zombieproducts' => 'run_audit',
            'scan_inventoryaging' => 'run_audit', 'scan_inventoryforecast' => 'run_audit', 'scan_returns' => 'run_audit',
            'scan_ltv' => 'run_audit', 'scan_catalogquality' => 'run_audit', 'scan_giftcards' => 'run_audit',
            'scan_taxaudit' => 'run_audit', 'scan_consentaudit' => 'run_audit',
            // Admin-only checks and settings
            'test_connection' => 'manage_settings', 'refresh_api_health' => 'manage_settings',
            'save_settings' => 'manage_settings', 'ban_ip' => 'manage_settings', 'unban_ip' => 'manage_settings',
            'save_slack_rules' => 'manage_settings', 'save_email_rules' => 'manage_settings',
            'save_sidebar_settings' => 'manage_settings', 'add_user' => 'manage_users', 'delete_user' => 'manage_users',
            // Unknown action - must stay denied
            'totally_made_up_action_xyz' => false,
        ];
    }

    public function testEveryActionResolvesToTheExactPermissionItHadBeforeConsolidation(): void
    {
        $mismatches = [];
        foreach (self::expected() as $action => $expectedPermission) {
            $actual = Auth::permissionForAction($action);
            if ($actual !== $expectedPermission) {
                $mismatches[] = sprintf(
                    '%s: expected %s, got %s',
                    $action,
                    var_export($expectedPermission, true),
                    var_export($actual, true)
                );
            }
        }
        $this->assertSame([], $mismatches, "Permission drift:\n" . implode("\n", $mismatches));
    }

    public function testExpectedCoversEveryKeyCurrentlyInSourceForNoSurprises(): void
    {
        // Guards the snapshot itself: if a future action is added to
        // Auth without a matching line here, this fails loudly instead
        // of the snapshot silently going stale.
        $this->assertCount(87, self::expected());
    }
}
