<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Wires bin/generate-tools-doc.php --check into `composer test`, so a new
 * audit page that isn't reflected in docs/tools.md fails the suite instead
 * of silently drifting - the exact gap that let 19 tools, then 10 more
 * TRIGGER_CATALOG entries, go undocumented in this same session.
 */
class DocsGeneratorTest extends TestCase
{
    public function testAuditSectionOfToolsDocMatchesToolRegistry(): void
    {
        $script = dirname(__DIR__, 2) . '/bin/generate-tools-doc.php';
        exec('php ' . escapeshellarg($script) . ' --check 2>&1', $output, $exitCode);

        $this->assertSame(
            0,
            $exitCode,
            "docs/tools.md is out of sync with ToolRegistry - run `composer docs`:\n" . implode("\n", $output)
        );
    }
}
