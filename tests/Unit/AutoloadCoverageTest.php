<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Every class/interface/trait/enum declared under src/ must be resolvable
 * through Composer's classmap autoloader alone (vendor/autoload.php), with
 * no manual require.
 *
 * Regression test for a real bug: adding a new class file under src/ does
 * not update vendor/composer/autoload_classmap.php until
 * `composer dump-autoload` (or `composer install`) runs. Every other test
 * in this suite manually `require_once`s its dependencies, so they all
 * pass regardless - only the live app, which relies purely on
 * autoloading, fatals with "Class not found". This test exercises the
 * same path the app takes.
 */
class AutoloadCoverageTest extends TestCase
{
    public function testEveryDeclaredSymbolUnderSrcIsAutoloadable(): void
    {
        $root = dirname(__DIR__, 2) . '/src';
        $missing = [];

        foreach ($this->declaredSymbols($root) as [$type, $fqcn]) {
            $exists = match ($type) {
                'class'     => class_exists($fqcn, true),
                'interface' => interface_exists($fqcn, true),
                'trait'     => trait_exists($fqcn, true),
                'enum'      => enum_exists($fqcn, true),
            };
            if (!$exists) {
                $missing[] = "{$type} {$fqcn}";
            }
        }

        $this->assertSame(
            [],
            $missing,
            "Not autoloadable - run `composer dump-autoload`: " . implode(', ', $missing)
        );
    }

    /** @return array<int, array{0: string, 1: string}> list of [type, FQCN] */
    private function declaredSymbols(string $dir): array
    {
        $symbols = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() === 'php') {
                $symbols = array_merge($symbols, $this->symbolsInFile((string) $file));
            }
        }
        return $symbols;
    }

    private const TYPE_TOKENS = [T_CLASS => 'class', T_INTERFACE => 'interface', T_TRAIT => 'trait', T_ENUM => 'enum'];
    private const NAME_TOKENS = [T_STRING, T_NS_SEPARATOR, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED];

    /** @return array<int, array{0: string, 1: string}> */
    private function symbolsInFile(string $path): array
    {
        $tokens = token_get_all((string) file_get_contents($path));
        $namespace = '';
        $symbols = [];

        foreach ($tokens as $i => $token) {
            if (!is_array($token)) continue;

            if ($token[0] === T_NAMESPACE) {
                $namespace = ltrim($this->readName($tokens, $i + 1), '\\');
            } elseif (isset(self::TYPE_TOKENS[$token[0]])) {
                $name = $this->readName($tokens, $i + 1);
                if ($name === '' || str_contains($name, '\\')) continue; // skip anonymous `new class`
                $fqcn = $namespace !== '' ? "{$namespace}\\{$name}" : $name;
                $symbols[] = [self::TYPE_TOKENS[$token[0]], $fqcn];
            }
        }
        return $symbols;
    }

    private function readName(array $tokens, int $start): string
    {
        $name = '';
        for ($i = $start; $i < count($tokens); $i++) {
            $token = $tokens[$i];
            if (is_array($token) && $token[0] === T_WHITESPACE) continue;
            if (is_array($token) && in_array($token[0], self::NAME_TOKENS, true)) {
                $name .= $token[1];
                continue;
            }
            break;
        }
        return $name;
    }
}
