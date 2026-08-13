<?php
declare(strict_types=1);

require_once __DIR__ . '/AtomicFile.php';

/**
 * Simple file-based JSON cache.
 *
 * Each entry is stored as   cache/<prefix>_<hash>.json
 * with a wrapper: { "expires_at": <unix>, "data": [...] }
 *
 * Two separate time controls:
 *   $ttl       - how long the data is considered fresh (reused on next request)
 *   $retention - how long the file stays on disk AFTER expiry (for debugging)
 */
class Cache
{
    /** Tracks which prefixes were served from a warm cache on this request. */
    private array $hits = [];

    public function __construct(
        private readonly string $dir,
        private readonly int    $ttl       = 82800,   // data validity (default 23 h)
        private readonly int    $retention = 1209600  // keep file on disk after expiry (default 2 weeks)
    ) {
        if ($this->ttl > 0 && !is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }

    /**
     * Return cached value if fresh, otherwise call $fetch, store, and return.
     *
     * @template T
     * @param  string   $prefix  Short label ('ss', 'shopify').
     * @param  string   $key     Anything that uniquely identifies the request.
     * @param  callable $fetch   Called with no args; must return the value to cache.
     * @param  int|null $ttl     Optional per-entry freshness cap. The cache's
     *                            configured TTL remains the upper bound.
     * @return T
     */
    public function remember(string $prefix, string $key, callable $fetch, ?int $ttl = null): mixed
    {
        if ($this->ttl <= 0 || ($ttl !== null && $ttl <= 0)) {
            return $fetch();
        }

        $file = $this->path($prefix, $key);

        if ($this->readFresh($file, $cached)) {
            $this->hits[$prefix] = true;
            return $cached;
        }

        // A cold cache can otherwise cause multiple PHP workers to start the
        // same expensive paginated API request. Lock per cache entry, then
        // check again after acquiring it so only the first worker fetches.
        $lock = fopen($file . '.lock', 'c+');
        if ($lock === false) {
            throw new RuntimeException("Could not open cache lock for {$file}");
        }

        try {
            flock($lock, LOCK_EX);
            if ($this->readFresh($file, $cached)) {
                $this->hits[$prefix] = true;
                return $cached;
            }

            $data = $fetch();
            AtomicFile::writeJson($file, [
                'expires_at' => time() + $this->effectiveTtl($ttl),
                'data'       => $data,
            ], 0);
            return $data;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function getTtl(): int       { return $this->ttl; }
    public function getRetention(): int { return $this->retention; }
    public function getDir(): string    { return $this->dir; }

    public function checkpointDir(string $prefix, string $key): string
    {
        return $this->dir . '/checkpoints/' . $prefix . '_' . hash('sha256', $key);
    }

    public function wasHit(string $prefix): bool
    {
        return isset($this->hits[$prefix]);
    }

    /**
     * Check without fetching whether an entry is fresh.
     */
    public function isFresh(string $prefix, string $key): bool
    {
        if ($this->ttl <= 0) return false;
        $file = $this->path($prefix, $key);
        if (!file_exists($file)) return false;
        $fh = fopen($file, 'r');
        if (!$fh) return false;
        $head = fread($fh, 64);
        fclose($fh);
        preg_match('/"expires_at"\s*:\s*(\d+)/', $head, $m);
        return isset($m[1]) && (int) $m[1] > time();
    }

    /**
     * Serialise work for one cache key without changing its cached value.
     * Useful for cache formats such as ShipStation's paged checkpoints, where
     * the fetch itself is not stored in Cache::remember().
     */
    public function synchronized(string $prefix, string $key, callable $work): mixed
    {
        if ($this->ttl <= 0) {
            return $work();
        }

        $lock = fopen($this->path($prefix, $key) . '.lock', 'c+');
        if ($lock === false) {
            throw new RuntimeException("Could not open cache lock for {$prefix}");
        }

        try {
            flock($lock, LOCK_EX);
            return $work();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Write a value directly to the cache (bypasses the fetch callable).
     */
    public function put(string $prefix, string $key, mixed $data): void
    {
        if ($this->ttl <= 0) return;
        $wrapper = ['expires_at' => time() + $this->ttl, 'data' => $data];
        AtomicFile::writeJson($this->path($prefix, $key), $wrapper, 0);
    }

    /**
     * Delete files that have been expired longer than $retention seconds.
     * Fresh files and recently-expired files (kept for debugging) are left alone.
     */
    public function pruneExpired(): int
    {
        $deadline = time() - $this->retention;
        $count    = 0;

        foreach (glob($this->dir . '/*.json') ?: [] as $f) {
            $fh   = fopen($f, 'r');
            $head = $fh ? fread($fh, 64) : '';
            if ($fh) fclose($fh);
            preg_match('/"expires_at"\s*:\s*(\d+)/', $head, $m);
            if (isset($m[1]) && (int) $m[1] < $deadline) {
                @unlink($f);
                @unlink($f . '.lock');
                $count++;
            }
        }

        // remember()/synchronized() open a per-key .lock file that outlives its
        // .json entry (or, for synchronized()-only keys such as ShipStation
        // checkpoints, never has one). Age those out too so the cache dir
        // doesn't accumulate one file per key forever.
        foreach (glob($this->dir . '/*.lock') ?: [] as $lockFile) {
            $jsonFile = substr($lockFile, 0, -strlen('.lock'));
            if (!file_exists($jsonFile) && (@filemtime($lockFile) ?: 0) < $deadline) {
                @unlink($lockFile);
            }
        }

        // Prune ShipStation checkpoint directories
        $cpBase = $this->dir . '/checkpoints';
        if (is_dir($cpBase)) {
            foreach (glob($cpBase . '/*/') ?: [] as $cpDir) {
                $metaFile = rtrim($cpDir, '/') . '/_meta.json';
                if (!file_exists($metaFile)) continue;
                $meta = json_decode(file_get_contents($metaFile), true);
                if (is_array($meta) && ($meta['expires_at'] ?? 0) < $deadline) {
                    $count += self::removeTree(rtrim($cpDir, '/'));
                }
            }
        }

        return $count;
    }

    /**
     * Delete all cache files, or only those matching a prefix.
     */
    public function flush(string $prefix = '*'): int
    {
        $pattern = $this->dir . '/' . $prefix . '_*.json';
        $files   = glob($pattern) ?: [];
        foreach ($files as $f) {
            unlink($f);
            @unlink($f . '.lock');
        }
        foreach (glob($this->dir . '/' . $prefix . '_*.json.lock') ?: [] as $orphanLock) {
            @unlink($orphanLock);
        }
        $count = count($files);

        $cpBase = $this->dir . '/checkpoints';
        if (is_dir($cpBase)) {
            $cpPattern = $prefix === '*' ? $cpBase . '/*' : $cpBase . '/' . $prefix . '_*';
            foreach (glob($cpPattern, GLOB_ONLYDIR) ?: [] as $dir) {
                $count += self::removeTree($dir);
            }
        }

        return $count;
    }

    /**
     * Return metadata about every cached entry (after pruning old files).
     *
     * @return list<array{file: string, prefix: string, expires_at: int, expired: bool, size_kb: float}>
     */
    public function entries(): array
    {
        $this->pruneExpired();
        $files  = glob($this->dir . '/*.json') ?: [];
        $result = [];
        foreach ($files as $f) {
            $fh   = fopen($f, 'r');
            $head = $fh ? fread($fh, 64) : '';
            if ($fh) fclose($fh);
            preg_match('/"expires_at"\s*:\s*(\d+)/', $head, $m);
            $exp = isset($m[1]) ? (int) $m[1] : 0;
            preg_match('/\/([a-z_]+)_[0-9a-f]+\.json$/', $f, $pm);
            $result[] = [
                'file'       => basename($f),
                'prefix'     => $pm[1] ?? '?',
                'expires_at' => $exp,
                'expired'    => $exp < time(),
                'size_kb'    => round(filesize($f) / 1024, 1),
            ];
        }
        usort($result, fn($a, $b) => $b['expires_at'] <=> $a['expires_at']);
        return $result;
    }

    private function path(string $prefix, string $key): string
    {
        return $this->dir . '/' . $prefix . '_' . hash('sha256', $key) . '.json';
    }

    private function effectiveTtl(?int $ttl): int
    {
        return $ttl === null ? $this->ttl : max(1, min($this->ttl, $ttl));
    }

    private function readFresh(string $file, mixed &$data): bool
    {
        if (!file_exists($file)) {
            return false;
        }

        $raw     = file_get_contents($file);
        $wrapper = $raw ? json_decode($raw, true) : null;
        if (!is_array($wrapper) || !array_key_exists('data', $wrapper) || ($wrapper['expires_at'] ?? 0) <= time()) {
            return false;
        }

        $data = $wrapper['data'];
        return true;
    }

    private static function removeTree(string $path): int
    {
        if (!is_dir($path)) {
            return 0;
        }

        $count = 0;
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $child = $path . '/' . $entry;
            if (is_dir($child)) {
                $count += self::removeTree($child);
            } elseif (@unlink($child)) {
                $count++;
            }
        }

        @rmdir($path);
        return $count;
    }
}
