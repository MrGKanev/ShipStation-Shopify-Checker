<?php
/**
 * Simple file-based JSON cache.
 *
 * Each entry is stored as   cache/<prefix>_<hash>.json
 * with a wrapper: { "expires_at": <unix>, "data": [...] }
 */
class Cache
{
    private string $dir;
    private int    $ttl;

    /** Tracks which prefixes were served from a warm cache on this request. */
    private array $hits = [];

    public function __construct(string $dir, int $ttlSeconds = 14400)
    {
        $this->dir = $dir;
        $this->ttl = $ttlSeconds;

        if ($ttlSeconds > 0 && !is_dir($dir)) {
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
     * @return T
     */
    public function remember(string $prefix, string $key, callable $fetch): mixed
    {
        if ($this->ttl <= 0) {
            return $fetch();
        }

        $file = $this->path($prefix, $key);

        if (file_exists($file)) {
            $wrapper = json_decode(file_get_contents($file), true);
            if (is_array($wrapper) && ($wrapper['expires_at'] ?? 0) > time()) {
                $this->hits[$prefix] = true;
                return $wrapper['data'];
            }
        }

        $data    = $fetch();
        $wrapper = ['expires_at' => time() + $this->ttl, 'data' => $data];
        file_put_contents($file, json_encode($wrapper), LOCK_EX);

        return $data;
    }

    /**
     * Was the last remember() call for this prefix a cache hit?
     */
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
        $wrapper = json_decode(file_get_contents($file), true);
        return is_array($wrapper) && ($wrapper['expires_at'] ?? 0) > time();
    }

    /**
     * Delete all cache files, or only those matching a prefix.
     */
    public function flush(string $prefix = '*'): int
    {
        $pattern = $this->dir . '/' . $prefix . '_*.json';
        $files   = glob($pattern) ?: [];
        foreach ($files as $f) unlink($f);
        return count($files);
    }

    /**
     * Return metadata about every cached entry.
     *
     * @return list<array{file: string, prefix: string, expires_at: int, expired: bool, size_kb: float}>
     */
    public function entries(): array
    {
        $files  = glob($this->dir . '/*.json') ?: [];
        $result = [];
        foreach ($files as $f) {
            $wrapper = json_decode(file_get_contents($f), true);
            $exp     = $wrapper['expires_at'] ?? 0;
            preg_match('/\/([a-z]+)_[0-9a-f]+\.json$/', $f, $m);
            $result[] = [
                'file'       => basename($f),
                'prefix'     => $m[1] ?? '?',
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
}
