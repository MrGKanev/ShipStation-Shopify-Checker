<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/ReportRegistry.php';

use PHPUnit\Framework\TestCase;

final class ReportRegistryTest extends TestCase
{
    public function testKnownToolReturnsLabelIconPageAndPrefix(): void
    {
        $this->assertSame('Fulfilled Items', ReportRegistry::label('scan_fulfilleditems'));
        $this->assertSame('✅', ReportRegistry::icon('scan_fulfilleditems'));
        $this->assertSame('fulfilleditems', ReportRegistry::page('scan_fulfilleditems'));
        $this->assertSame('fi', ReportRegistry::prefix('scan_fulfilleditems'));
    }

    public function testGetReturnsFullTupleForKnownTool(): void
    {
        $this->assertSame(
            ['Fulfilled Items', '✅', 'fulfilleditems', 'fi'],
            ReportRegistry::get('scan_fulfilleditems')
        );
    }

    public function testGetReturnsNullForUnknownTool(): void
    {
        $this->assertNull(ReportRegistry::get('some_unregistered_tool'));
    }

    public function testUnknownToolFallsBackToToolNameAndGenericIcon(): void
    {
        $this->assertSame('some_unregistered_tool', ReportRegistry::label('some_unregistered_tool'));
        $this->assertSame('🧾', ReportRegistry::icon('some_unregistered_tool'));
        $this->assertNull(ReportRegistry::page('some_unregistered_tool'));
        $this->assertNull(ReportRegistry::prefix('some_unregistered_tool'));
    }

    public function testToolForPageFindsTheOwningTool(): void
    {
        $this->assertSame('scan_fulfilleditems', ReportRegistry::toolForPage('fulfilleditems'));
        $this->assertNull(ReportRegistry::toolForPage('some_unknown_page'));
    }

    public function testNoTwoToolsShareThePage(): void
    {
        $ref   = new \ReflectionClass(ReportRegistry::class);
        $tools = $ref->getConstant('TOOLS');

        $pages = array_column($tools, 2);
        $this->assertSame(count($pages), count(array_unique($pages)), 'Two tools point at the same page slug');
    }

    /**
     * Every ScanRunner::run() call site in the loaders passes both a trigger name
     * and a DateRange prefix. If a tool is registered here, the prefix must match
     * what the loader actually uses, or the "reopen this run" sidebar link will
     * 404 / silently fall back to a live scan instead of the saved snapshot.
     */
    public function testEveryRegisteredToolHasAllFourFields(): void
    {
        $ref  = new \ReflectionClass(ReportRegistry::class);
        $tools = $ref->getConstant('TOOLS');

        foreach ($tools as $tool => $fields) {
            $this->assertCount(4, $fields, "Tool {$tool} must have [label, icon, page, prefix]");
            foreach ($fields as $field) {
                $this->assertNotSame('', $field, "Tool {$tool} has an empty field");
            }
        }
    }
}
