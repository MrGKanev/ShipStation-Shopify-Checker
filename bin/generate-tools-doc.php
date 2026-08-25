#!/usr/bin/env php
<?php
declare(strict_types=1);

// Regenerates the "## Audit" section of docs/tools.md from
// ToolRegistry::hubSections('audit') - the same data that drives the
// in-app sidebar - so a new audit page can never again ship undocumented
// (see: 19 tools, then 10 more TRIGGER_CATALOG entries, found missing in
// the same session).
//
// Usage:
//   php bin/generate-tools-doc.php          Write docs/tools.md in place
//   php bin/generate-tools-doc.php --check  Exit 1 if the file would change (no write)
//   php bin/generate-tools-doc.php --print  Print the generated section only

require_once __DIR__ . '/../vendor/autoload.php';

const START_MARKER = '<!-- AUTO-GENERATED:AUDIT-SECTION:START -->';
const END_MARKER   = '<!-- AUTO-GENERATED:AUDIT-SECTION:END -->';

function renderAuditSection(): string
{
    $lines = [START_MARKER, ''];
    foreach (ToolRegistry::hubSections('audit') as $sectionName => $tools) {
        $lines[] = "### {$sectionName}";
        $lines[] = '';
        $lines[] = '| Page | What it does |';
        $lines[] = '| --- | --- |';
        foreach ($tools as $tool) {
            $name = $tool['title'] ?? $tool['name'];
            $lines[] = "| **{$name}** | {$tool['desc']} |";
        }
        $lines[] = '';
    }
    $lines[] = END_MARKER;
    return implode("\n", $lines);
}

function spliceIntoDocsFile(string $docsPath, string $generatedSection): string
{
    $current = file_get_contents($docsPath);
    if ($current === false) {
        fwrite(STDERR, "Could not read {$docsPath}\n");
        exit(1);
    }

    $startPos = strpos($current, START_MARKER);
    $endPos   = strpos($current, END_MARKER);
    if ($startPos === false || $endPos === false) {
        fwrite(STDERR, "Markers not found in {$docsPath} - add " . START_MARKER . " / " . END_MARKER . " around the Audit section.\n");
        exit(1);
    }

    return substr($current, 0, $startPos)
        . $generatedSection
        . substr($current, $endPos + strlen(END_MARKER));
}

$docsPath  = __DIR__ . '/../docs/tools.md';
$generated = renderAuditSection();

$args = array_slice($argv, 1);

if (in_array('--print', $args, true)) {
    echo $generated . "\n";
    exit(0);
}

$newContent = spliceIntoDocsFile($docsPath, $generated);
$oldContent = file_get_contents($docsPath);

if (in_array('--check', $args, true)) {
    if ($newContent !== $oldContent) {
        fwrite(STDERR, "docs/tools.md is out of date - run `composer docs` to regenerate it.\n");
        exit(1);
    }
    echo "docs/tools.md is up to date.\n";
    exit(0);
}

if ($newContent === $oldContent) {
    echo "docs/tools.md already up to date.\n";
    exit(0);
}

file_put_contents($docsPath, $newContent);
echo "docs/tools.md regenerated.\n";
