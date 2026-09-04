<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class HttpAuthEndpointTest extends TestCase
{
    /** @var resource|null */
    private static $process = null;
    private static string $baseUrl = '';
    private static string $runtimeDir = '';

    public static function setUpBeforeClass(): void
    {
        if (!function_exists('proc_open') || !filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
            self::markTestSkipped('The HTTP integration test requires proc_open and allow_url_fopen.');
        }

        self::$runtimeDir = sys_get_temp_dir() . '/auth_http_' . bin2hex(random_bytes(5));
        mkdir(self::$runtimeDir, 0700, true);

        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if ($socket === false) self::fail("Unable to reserve an HTTP test port: {$error} ({$errno})");
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $port = (int) substr(strrchr((string) $address, ':'), 1);
        self::$baseUrl = "http://127.0.0.1:{$port}";

        $root = dirname(__DIR__, 2);
        $env = getenv();
        $env['GOOGLE_CLIENT_ID'] = 'integration.apps.googleusercontent.com';
        $env['GOOGLE_CLIENT_SECRET'] = 'integration-secret';
        $env['GOOGLE_REDIRECT_URI'] = self::$baseUrl . '/?auth=google_callback';
        $env['GOOGLE_ALLOWED_DOMAINS'] = 'example.com';
        $env['GOOGLE_LOGIN_ONLY'] = '1';
        $env['STATE_STORAGE'] = 'sqlite';

        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', self::$runtimeDir . '/server.log', 'a'],
            2 => ['file', self::$runtimeDir . '/server.log', 'a'],
        ];
        self::$process = proc_open([
            PHP_BINARY,
            '-d', 'session.save_path=' . self::$runtimeDir,
            '-S', "127.0.0.1:{$port}",
            '-t', $root,
        ], $descriptors, $pipes, $root, $env);
        if (!is_resource(self::$process)) self::fail('Unable to start the PHP HTTP test server.');

        $ready = false;
        for ($attempt = 0; $attempt < 60; $attempt++) {
            $probe = @fsockopen('127.0.0.1', $port, $probeError, $probeMessage, 0.1);
            if (is_resource($probe)) {
                fclose($probe);
                $ready = true;
                break;
            }
            usleep(50_000);
        }
        if (!$ready) self::fail('The PHP HTTP test server did not become ready.');
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$process)) {
            proc_terminate(self::$process);
            proc_close(self::$process);
        }
        foreach (glob(self::$runtimeDir . '/*') ?: [] as $file) @unlink($file);
        if (self::$runtimeDir !== '') @rmdir(self::$runtimeDir);
    }

    public function testGoogleLoginFlowOverRealHttpBoundary(): void
    {
        $login = self::request('/');
        $this->assertSame(200, $login['status']);
        $this->assertStringContainsString('Continue with Google', $login['body']);
        $this->assertStringNotContainsString('name="password"', $login['body']);
        $this->assertStringContainsString('Content-Security-Policy:', implode("\n", $login['headers']));

        $start = self::request('/?auth=google', $login['cookie']);
        $this->assertSame(302, $start['status']);
        $location = self::headerValue($start['headers'], 'Location');
        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $location);
        $this->assertStringContainsString('code_challenge=', $location);
        $this->assertNotSame('', $start['cookie']);

        $callback = self::request('/?auth=google_callback&state=wrong&code=fake', $start['cookie']);
        $this->assertSame(302, $callback['status']);
        $this->assertSame('/', self::headerValue($callback['headers'], 'Location'));

        $flash = self::request('/', $callback['cookie']);
        $this->assertSame(200, $flash['status']);
        $this->assertStringContainsString('Google sign-in session expired', $flash['body']);

        $denied = self::request('/?auth=access_denied', $flash['cookie']);
        $this->assertSame(200, $denied['status']);
        $this->assertStringContainsString('not part of the team', strtolower($denied['body']));
    }

    /** @return array{status: int, headers: list<string>, body: string, cookie: string} */
    private static function request(string $path, string $cookie = ''): array
    {
        $headers = $cookie === '' ? [] : ['Cookie: ' . $cookie];
        $context = stream_context_create(['http' => [
            'method' => 'GET',
            'header' => implode("\r\n", $headers),
            'ignore_errors' => true,
            'follow_location' => 0,
            'timeout' => 5,
        ]]);
        $body = file_get_contents(self::$baseUrl . $path, false, $context);
        $responseHeaders = $http_response_header ?? [];
        preg_match('/\s(\d{3})\s/', $responseHeaders[0] ?? '', $statusMatch);

        $responseCookie = $cookie;
        foreach ($responseHeaders as $header) {
            if (stripos($header, 'Set-Cookie:') !== 0) continue;
            $responseCookie = trim(explode(';', substr($header, strlen('Set-Cookie:')), 2)[0]);
        }
        return [
            'status' => (int) ($statusMatch[1] ?? 0),
            'headers' => $responseHeaders,
            'body' => $body === false ? '' : $body,
            'cookie' => $responseCookie,
        ];
    }

    /** @param list<string> $headers */
    private static function headerValue(array $headers, string $name): string
    {
        foreach ($headers as $header) {
            if (stripos($header, $name . ':') === 0) return trim(substr($header, strlen($name) + 1));
        }
        return '';
    }
}
