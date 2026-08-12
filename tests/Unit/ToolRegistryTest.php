<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/ToolRegistry.php';

use PHPUnit\Framework\TestCase;

class ToolRegistryTest extends TestCase
{
    public function testKnownPagesHaveTitlesAndGroups(): void
    {
        $this->assertSame('Audit', ToolRegistry::title('hub-audit'));
        $this->assertSame('Duplicate Shipping Addresses', ToolRegistry::title('addrdupes'));
        $this->assertSame('search', ToolRegistry::groupOf('globalsearch'));
        $this->assertSame('manage', ToolRegistry::groupOf('runlog'));
        $this->assertSame('manage', ToolRegistry::groupOf('jobs'));
        $this->assertSame('settings', ToolRegistry::groupOf('apihealth'));
        $this->assertContains('inventoryoversell', ToolRegistry::allowedPages());
        $this->assertContains('shipmentaging', ToolRegistry::allowedPages());
        $this->assertContains('configcheck', ToolRegistry::allowedPages());
        $this->assertSame('Fraud & Compliance', array_key_last(ToolRegistry::hubSections('audit')));
    }

    public function testNormalizePageFallsBackForUnknownPages(): void
    {
        $this->assertSame('hub-audit', ToolRegistry::normalizePage('does-not-exist'));
        $this->assertSame('run', ToolRegistry::normalizePage('run'));
    }

    public function testHubSectionsIncludeAuditAndSearchTools(): void
    {
        $audit = ToolRegistry::hubSections('audit');
        $search = ToolRegistry::hubSections('search');

        $this->assertArrayHasKey('Core Audit', $audit);
        $this->assertArrayHasKey('Orders', $search);
        $this->assertSame('run', $audit['Core Audit'][1]['page']);
        $this->assertSame('spotcheck', $search['Orders'][0]['page']);
    }

    public function testGroupOfFallsBackToSettingsForUnknownPage(): void
    {
        $this->assertSame('settings', ToolRegistry::groupOf('does-not-exist'));
    }

    public function testTitleFallsBackToPageItselfForUnknownPage(): void
    {
        $this->assertSame('does-not-exist', ToolRegistry::title('does-not-exist'));
    }

    public function testTitlesIncludesEveryAllowedPage(): void
    {
        $titles = ToolRegistry::titles();

        $this->assertSame(count(ToolRegistry::allowedPages()), count($titles));
        $this->assertSame('Duplicate Shipping Addresses', $titles['addrdupes']);
        $this->assertSame('Run History', $titles['runlog']);
    }

    public function testGroupMetaIncludesAllFourGroups(): void
    {
        $meta = ToolRegistry::groupMeta();

        $this->assertSame(['audit', 'search', 'manage', 'settings'], array_keys($meta));
        $this->assertSame('?page=hub-audit', $meta['audit']['href']);
        $this->assertSame('Manage', $meta['manage']['label']);
    }

    public function testHubSectionsReturnsEmptyArrayForUnknownGroup(): void
    {
        $this->assertSame([], ToolRegistry::hubSections('does-not-exist'));
    }
}
