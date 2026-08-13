<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class CacheTest extends TestCase
{
    private string $tmpDir;
    private Cache  $cache;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/cache_test_' . uniqid();
        $this->cache  = new Cache($this->tmpDir, ttl: 60);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    // ── remember ──────────────────────────────────────────────────────────────

    public function testRememberCallsFetchOnMiss(): void
    {
        $calls  = 0;
        $result = $this->cache->remember('pfx', 'key', function () use (&$calls) {
            $calls++;
            return ['v' => 1];
        });

        $this->assertSame(1, $calls);
        $this->assertSame(['v' => 1], $result);
    }

    public function testRememberReturnsCachedValueWithoutCallingFetch(): void
    {
        $calls = 0;
        $fetch = function () use (&$calls) { $calls++; return ['v' => 42]; };

        $this->cache->remember('pfx', 'key', $fetch);
        $result = $this->cache->remember('pfx', 'key', $fetch);

        $this->assertSame(1, $calls);
        $this->assertSame(['v' => 42], $result);
    }

    public function testRememberStoresDataToDisk(): void
    {
        $this->cache->remember('pfx', 'key', fn() => ['stored' => true]);

        $files = glob($this->tmpDir . '/pfx_*.json');
        $this->assertCount(1, $files);
    }

    public function testRememberWithZeroTtlAlwaysCallsFetch(): void
    {
        $noCache = new Cache($this->tmpDir, ttl: 0);
        $calls   = 0;
        $fetch   = function () use (&$calls) { $calls++; return []; };

        $noCache->remember('pfx', 'key', $fetch);
        $noCache->remember('pfx', 'key', $fetch);

        $this->assertSame(2, $calls);
    }

    public function testRememberCapsAnEntryTtlWithoutChangingTheGlobalTtl(): void
    {
        $this->cache->remember('pfx', 'short-lived', fn() => ['v' => 1], 5);

        $file = $this->tmpDir . '/pfx_' . hash('sha256', 'short-lived') . '.json';
        $saved = json_decode((string) file_get_contents($file), true);

        $this->assertGreaterThanOrEqual(time() + 4, $saved['expires_at']);
        $this->assertLessThanOrEqual(time() + 5, $saved['expires_at']);
        $this->assertSame(60, $this->cache->getTtl());
    }

    public function testRememberNeverLetsAPerEntryTtlExceedTheGlobalTtl(): void
    {
        // Global ttl is 60s; a caller asking for a 1-day entry must still be
        // capped down to the cache's configured ceiling.
        $this->cache->remember('pfx', 'over-cap', fn() => ['v' => 1], 86400);

        $file = $this->tmpDir . '/pfx_' . hash('sha256', 'over-cap') . '.json';
        $saved = json_decode((string) file_get_contents($file), true);

        $this->assertLessThanOrEqual(time() + $this->cache->getTtl(), $saved['expires_at']);
    }

    public function testRememberTreatsWrapperMissingDataKeyAsNotFresh(): void
    {
        // A valid JSON object with a future expires_at but no "data" key should
        // never happen from AtomicFile::writeJson (both keys are always written
        // together), but if it ever does via a corrupted/hand-edited file, it
        // must trigger a re-fetch rather than silently handing back null.
        $file = $this->tmpDir . '/pfx_' . hash('sha256', 'k') . '.json';
        file_put_contents($file, json_encode(['expires_at' => time() + 60]));

        $calls  = 0;
        $result = $this->cache->remember('pfx', 'k', function () use (&$calls) {
            $calls++;
            return ['recovered' => true];
        });

        $this->assertSame(1, $calls, 'a wrapper without a data key must not be treated as a valid cache hit');
        $this->assertSame(['recovered' => true], $result);
    }

    public function testRememberHoldsAnExclusiveLockForTheDurationOfTheFetch(): void
    {
        // This is the whole point of the per-key lock: while one caller is
        // fetching, a second locker on the same key must not be able to
        // acquire it (which is what stops concurrent PHP workers from both
        // starting the same expensive paginated API request).
        $file = $this->tmpDir . '/pfx_' . hash('sha256', 'contended') . '.json';
        $acquiredDuringFetch = null;

        $this->cache->remember('pfx', 'contended', function () use ($file, &$acquiredDuringFetch) {
            $probe = fopen($file . '.lock', 'c+');
            $acquiredDuringFetch = flock($probe, LOCK_EX | LOCK_NB);
            if ($acquiredDuringFetch) {
                flock($probe, LOCK_UN);
            }
            fclose($probe);
            return ['v' => 1];
        });

        $this->assertFalse($acquiredDuringFetch, 'a second locker must not acquire the lock while a fetch is in flight');
    }

    public function testRememberReleasesLockAfterASuccessfulFetch(): void
    {
        $file = $this->tmpDir . '/pfx_' . hash('sha256', 'k') . '.json';
        $this->cache->remember('pfx', 'k', fn() => ['v' => 1]);

        $probe     = fopen($file . '.lock', 'c+');
        $reacquire = flock($probe, LOCK_EX | LOCK_NB);
        fclose($probe);

        $this->assertTrue($reacquire, 'the lock must be released once remember() returns');
    }

    public function testRememberReleasesLockEvenWhenFetchThrows(): void
    {
        $file = $this->tmpDir . '/pfx_' . hash('sha256', 'boom') . '.json';

        try {
            $this->cache->remember('pfx', 'boom', fn() => throw new RuntimeException('API unavailable'));
            $this->fail('Expected exception was not thrown.');
        } catch (RuntimeException) {
        }

        $probe     = fopen($file . '.lock', 'c+');
        $reacquire = flock($probe, LOCK_EX | LOCK_NB);
        fclose($probe);

        $this->assertTrue($reacquire, 'a fetch failure must not leave the lock held forever and deadlock the next request');
    }

    public function testRememberWithZeroTtlNeverCreatesALockFile(): void
    {
        $noCache = new Cache($this->tmpDir, ttl: 0);
        $noCache->remember('pfx', 'key', fn() => ['v' => 1]);

        $this->assertEmpty(glob($this->tmpDir . '/*.lock'), 'a disabled cache must not touch the filesystem at all');
    }

    // ── wasHit ────────────────────────────────────────────────────────────────

    public function testWasHitFalseBeforeAnyCall(): void
    {
        $this->assertFalse($this->cache->wasHit('pfx'));
    }

    public function testWasHitFalseOnFirstCall(): void
    {
        $this->cache->remember('pfx', 'key', fn() => []);
        $this->assertFalse($this->cache->wasHit('pfx'));
    }

    public function testWasHitTrueAfterCacheHit(): void
    {
        $this->cache->remember('pfx', 'key', fn() => []);
        $this->cache->remember('pfx', 'key', fn() => []);

        $this->assertTrue($this->cache->wasHit('pfx'));
    }

    // ── isFresh ───────────────────────────────────────────────────────────────

    public function testIsFreshFalseWhenNotCached(): void
    {
        $this->assertFalse($this->cache->isFresh('pfx', 'missing'));
    }

    public function testIsFreshTrueAfterRemember(): void
    {
        $this->cache->remember('pfx', 'key', fn() => ['x' => 1]);
        $this->assertTrue($this->cache->isFresh('pfx', 'key'));
    }

    public function testIsFreshFalseForZeroTtlCache(): void
    {
        $noCache = new Cache($this->tmpDir, ttl: 0);
        $this->assertFalse($noCache->isFresh('pfx', 'key'));
    }

    // ── synchronized ──────────────────────────────────────────────────────────

    public function testSynchronizedReturnsTheWorkResultAndNeverTouchesCacheData(): void
    {
        $result = $this->cache->synchronized('pfx', 'key', fn() => ['computed' => true]);

        $this->assertSame(['computed' => true], $result);
        $this->assertEmpty(glob($this->tmpDir . '/*.json'), 'synchronized() must not write a cache entry itself');
    }

    public function testSynchronizedRunsWorkDirectlyForZeroTtlCache(): void
    {
        $noCache = new Cache($this->tmpDir, ttl: 0);
        $calls   = 0;

        $noCache->synchronized('pfx', 'key', function () use (&$calls) {
            $calls++;
            return 'ok';
        });

        $this->assertSame(1, $calls);
        $this->assertEmpty(glob($this->tmpDir . '/*.lock'));
    }

    public function testSynchronizedHoldsAnExclusiveLockForTheDurationOfWork(): void
    {
        $lockFile = $this->cache->getDir() . '/pfx_' . hash('sha256', 'checkpoint-key') . '.json.lock';
        $acquiredDuringWork = null;

        $this->cache->synchronized('pfx', 'checkpoint-key', function () use ($lockFile, &$acquiredDuringWork) {
            $probe = fopen($lockFile, 'c+');
            $acquiredDuringWork = flock($probe, LOCK_EX | LOCK_NB);
            if ($acquiredDuringWork) {
                flock($probe, LOCK_UN);
            }
            fclose($probe);
            return 'done';
        });

        $this->assertFalse($acquiredDuringWork, 'a second locker must not acquire the lock while work is in flight');
    }

    public function testSynchronizedReleasesLockEvenWhenWorkThrows(): void
    {
        $lockFile = $this->cache->getDir() . '/pfx_' . hash('sha256', 'checkpoint-key') . '.json.lock';

        try {
            $this->cache->synchronized('pfx', 'checkpoint-key', fn() => throw new RuntimeException('fetch page failed'));
            $this->fail('Expected exception was not thrown.');
        } catch (RuntimeException) {
        }

        $probe     = fopen($lockFile, 'c+');
        $reacquire = flock($probe, LOCK_EX | LOCK_NB);
        fclose($probe);

        $this->assertTrue($reacquire, 'a failed checkpoint refresh must not deadlock the next request for the same range');
    }

    // ── put ───────────────────────────────────────────────────────────────────

    public function testPutStoresValueReadableViaRemember(): void
    {
        $this->cache->put('pfx', 'key', ['direct' => true]);

        $calls  = 0;
        $result = $this->cache->remember('pfx', 'key', function () use (&$calls) {
            $calls++;
            return [];
        });

        $this->assertSame(0, $calls);
        $this->assertSame(['direct' => true], $result);
    }

    public function testPutIsNoOpForZeroTtlCache(): void
    {
        $noCache = new Cache($this->tmpDir, ttl: 0);
        $noCache->put('pfx', 'key', ['x' => 1]);

        $this->assertFalse($noCache->isFresh('pfx', 'key'));
    }

    // ── flush ─────────────────────────────────────────────────────────────────

    public function testFlushRemovesAllFiles(): void
    {
        $this->cache->remember('a', 'k1', fn() => [1]);
        $this->cache->remember('b', 'k2', fn() => [2]);

        $deleted = $this->cache->flush();

        $this->assertSame(2, $deleted);
        $this->assertEmpty(glob($this->tmpDir . '/*.json'));
    }

    public function testFlushWithPrefixRemovesOnlyMatchingFiles(): void
    {
        $this->cache->remember('aa', 'k1', fn() => []);
        $this->cache->remember('bb', 'k2', fn() => []);

        $deleted = $this->cache->flush('aa');

        $this->assertSame(1, $deleted);
        $this->assertCount(1, glob($this->tmpDir . '/*.json'));
    }

    public function testFlushOnEmptyDirReturnsZero(): void
    {
        $this->assertSame(0, $this->cache->flush());
    }

    public function testFlushRemovesLockFiles(): void
    {
        $this->cache->remember('a', 'k1', fn() => [1]);

        $this->cache->flush();

        $this->assertEmpty(glob($this->tmpDir . '/*.lock'));
    }

    public function testFlushRemovesCheckpointDirectories(): void
    {
        $dir = $this->cache->checkpointDir('ss', '2026-01-01|2026-01-02');
        mkdir($dir, 0755, true);
        file_put_contents($dir . '/page_1.json', '[]');
        file_put_contents($dir . '/_meta.json', json_encode(['expires_at' => time() + 60]));

        $deleted = $this->cache->flush();

        $this->assertSame(2, $deleted);
        $this->assertFalse(is_dir($dir));
    }

    public function testFlushWithPrefixRemovesOnlyMatchingCheckpoints(): void
    {
        $ssDir = $this->cache->checkpointDir('ss', 'one');
        $shopDir = $this->cache->checkpointDir('shopify', 'one');
        mkdir($ssDir, 0755, true);
        mkdir($shopDir, 0755, true);
        file_put_contents($ssDir . '/page_1.json', '[]');
        file_put_contents($shopDir . '/page_1.json', '[]');

        $deleted = $this->cache->flush('ss');

        $this->assertSame(1, $deleted);
        $this->assertFalse(is_dir($ssDir));
        $this->assertTrue(is_dir($shopDir));
    }

    // ── entries ───────────────────────────────────────────────────────────────

    public function testEntriesReturnsMetadataForCachedFiles(): void
    {
        $this->cache->remember('shop', 'k1', fn() => ['a']);
        $this->cache->remember('ss',   'k2', fn() => ['b']);

        $entries = $this->cache->entries();

        $this->assertCount(2, $entries);
        $prefixes = array_column($entries, 'prefix');
        $this->assertContains('shop', $prefixes);
        $this->assertContains('ss',   $prefixes);
    }

    public function testEntriesIncludesRequiredKeys(): void
    {
        $this->cache->remember('pfx', 'key', fn() => []);
        $entry = $this->cache->entries()[0];

        $this->assertArrayHasKey('file',       $entry);
        $this->assertArrayHasKey('prefix',     $entry);
        $this->assertArrayHasKey('expires_at', $entry);
        $this->assertArrayHasKey('expired',    $entry);
        $this->assertArrayHasKey('size_kb',    $entry);
    }

    public function testEntriesFreshEntryIsNotMarkedExpired(): void
    {
        $this->cache->remember('pfx', 'key', fn() => []);
        $this->assertFalse($this->cache->entries()[0]['expired']);
    }

    public function testEntriesReturnsEmptyArrayWhenNoCachedFiles(): void
    {
        $this->assertSame([], $this->cache->entries());
    }

    // ── getTtl ────────────────────────────────────────────────────────────────

    public function testGetTtlReturnsConfiguredValue(): void
    {
        $this->assertSame(60, $this->cache->getTtl());
    }

    // ── expiry behaviour ──────────────────────────────────────────────────────

    public function testRememberBypassesExpiredEntryAndCallsFetchAgain(): void
    {
        // Pre-populate an already-expired cache file for the key
        $file = $this->tmpDir . '/pfx_' . hash('sha256', 'key') . '.json';
        file_put_contents($file, json_encode(['expires_at' => time() - 10, 'data' => ['old' => true]]));

        $calls  = 0;
        $result = $this->cache->remember('pfx', 'key', function () use (&$calls) {
            $calls++;
            return ['new' => true];
        });

        $this->assertSame(1, $calls);
        $this->assertSame(['new' => true], $result);
    }

    public function testExpiredShortLivedEntryCannotMaskNewData(): void
    {
        $this->cache->remember('shopify_scan', 'range-a', fn() => ['version' => 'old'], 900);
        $file = $this->tmpDir . '/shopify_scan_' . hash('sha256', 'range-a') . '.json';
        file_put_contents($file, json_encode(['expires_at' => time() - 1, 'data' => ['version' => 'old']]));

        $this->assertSame(['version' => 'new'], $this->cache->remember('shopify_scan', 'range-a', fn() => ['version' => 'new'], 900));
    }

    public function testCorruptCacheFileIsIgnoredAndFetchedAgain(): void
    {
        $file = $this->tmpDir . '/shopify_scan_' . hash('sha256', 'broken') . '.json';
        file_put_contents($file, '{not valid json');

        $this->assertSame(['recovered' => true], $this->cache->remember('shopify_scan', 'broken', fn() => ['recovered' => true]));
    }

    public function testFailedRefreshDoesNotOverwriteExistingExpiredPayload(): void
    {
        $file = $this->tmpDir . '/shopify_scan_' . hash('sha256', 'failed-refresh') . '.json';
        file_put_contents($file, json_encode(['expires_at' => time() - 1, 'data' => ['last_known' => true]]));

        try {
            $this->cache->remember('shopify_scan', 'failed-refresh', fn() => throw new RuntimeException('API unavailable'));
            $this->fail('Expected refresh failure.');
        } catch (RuntimeException $e) {
            $this->assertSame('API unavailable', $e->getMessage());
        }

        $saved = json_decode((string)file_get_contents($file), true);
        $this->assertSame(['last_known' => true], $saved['data']);
    }

    public function testSameKeyUnderDifferentPrefixesNeverCollides(): void
    {
        $this->assertSame(['source' => 'shopify'], $this->cache->remember('shopify_scan', 'same-key', fn() => ['source' => 'shopify']));
        $this->assertSame(['source' => 'shipstation'], $this->cache->remember('ss_shipments', 'same-key', fn() => ['source' => 'shipstation']));
    }

    public function testExpiredFileRemainsOnDiskUntilExplicitlyPruned(): void
    {
        // Write an expired file for key A
        $fileA = $this->tmpDir . '/pfx_' . hash('sha256', 'keyA') . '.json';
        file_put_contents($fileA, json_encode(['expires_at' => time() - 10, 'data' => ['stale']]));

        // Call remember() for a completely different key — should not touch key A's file
        $this->cache->remember('pfx', 'keyB', fn() => ['fresh']);

        $this->assertTrue(file_exists($fileA), 'Expired file must stay on disk until pruneExpired() is called');
    }

    // ── pruneExpired ──────────────────────────────────────────────────────────

    public function testPruneExpiredDeletesFilesPastRetentionPeriod(): void
    {
        // Use a short retention window so we can trigger deletion without sleeping
        $pruneCache = new Cache($this->tmpDir, ttl: 60, retention: 60);

        $file = $this->tmpDir . '/old_' . hash('sha256', 'k') . '.json';
        // Expired 2 minutes ago — past the 60 s retention
        file_put_contents($file, json_encode(['expires_at' => time() - 120, 'data' => []]));

        $deleted = $pruneCache->pruneExpired();

        $this->assertSame(1, $deleted);
        $this->assertFalse(file_exists($file));
    }

    public function testPruneExpiredKeepsRecentlyExpiredFilesWithinRetentionWindow(): void
    {
        // retention = 1 hour; file expired only 30 s ago — should be kept
        $pruneCache = new Cache($this->tmpDir, ttl: 60, retention: 3600);

        $file = $this->tmpDir . '/recent_' . hash('sha256', 'k') . '.json';
        file_put_contents($file, json_encode(['expires_at' => time() - 30, 'data' => []]));

        $deleted = $pruneCache->pruneExpired();

        $this->assertSame(0, $deleted);
        $this->assertTrue(file_exists($file));
    }

    public function testPruneExpiredKeepsFreshFiles(): void
    {
        $this->cache->remember('pfx', 'fresh', fn() => ['ok' => true]);

        $deleted = $this->cache->pruneExpired();

        $this->assertSame(0, $deleted);
        $this->assertCount(1, glob($this->tmpDir . '/*.json'));
    }

    public function testPruneExpiredRemovesLockFileAlongsideItsExpiredEntry(): void
    {
        $pruneCache = new Cache($this->tmpDir, ttl: 60, retention: 60);
        $pruneCache->remember('pfx', 'k', fn() => ['ok' => true]);
        $file = $this->tmpDir . '/pfx_' . hash('sha256', 'k') . '.json';
        file_put_contents($file, json_encode(['expires_at' => time() - 120, 'data' => []]));

        $pruneCache->pruneExpired();

        $this->assertFalse(file_exists($file . '.lock'), 'lock file must not outlive its cache entry');
    }

    public function testPruneExpiredRemovesOrphanLockFilesPastRetention(): void
    {
        $pruneCache = new Cache($this->tmpDir, ttl: 60, retention: 60);
        // synchronized()-only keys (e.g. ShipStation checkpoints) never get a .json sibling
        $lockFile = $this->tmpDir . '/ss_' . hash('sha256', 'checkpoint-key') . '.json.lock';
        touch($lockFile, time() - 120);

        $pruneCache->pruneExpired();

        $this->assertFalse(file_exists($lockFile));
    }

    public function testPruneExpiredKeepsRecentOrphanLockFiles(): void
    {
        $pruneCache = new Cache($this->tmpDir, ttl: 60, retention: 3600);
        $lockFile = $this->tmpDir . '/ss_' . hash('sha256', 'checkpoint-key') . '.json.lock';
        touch($lockFile);

        $pruneCache->pruneExpired();

        $this->assertTrue(file_exists($lockFile), 'a lock file still in active use should not be deleted');
    }

    public function testPruneExpiredDeletesCheckpointDirectoriesPastRetention(): void
    {
        $pruneCache = new Cache($this->tmpDir, ttl: 60, retention: 60);
        $dir = $pruneCache->checkpointDir('ss', 'old');
        mkdir($dir, 0755, true);
        file_put_contents($dir . '/page_1.json', '[]');
        file_put_contents($dir . '/_meta.json', json_encode(['expires_at' => time() - 120]));

        $deleted = $pruneCache->pruneExpired();

        $this->assertSame(2, $deleted);
        $this->assertFalse(is_dir($dir));
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
