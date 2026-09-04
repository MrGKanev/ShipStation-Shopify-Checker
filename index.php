<?php
declare(strict_types=1);

// ── Bootstrap ─────────────────────────────────────────────────────────────────

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/src/Logger.php';
require_once __DIR__ . '/src/Auth.php';
require_once __DIR__ . '/src/GoogleAuth.php';
require_once __DIR__ . '/src/GoogleAuthFlow.php';
require_once __DIR__ . '/src/Stores.php';
require_once __DIR__ . '/src/IgnoreList.php';
require_once __DIR__ . '/src/PushLog.php';
require_once __DIR__ . '/src/RunLog.php';
require_once __DIR__ . '/src/AuditSnapshot.php';
require_once __DIR__ . '/src/ReportRegistry.php';
require_once __DIR__ . '/src/UserActionLog.php';
require_once __DIR__ . '/src/SlackRules.php';
require_once __DIR__ . '/src/EmailRules.php';
require_once __DIR__ . '/src/SidebarSettings.php';
require_once __DIR__ . '/src/JobQueue.php';
require_once __DIR__ . '/src/Cache.php';
require_once __DIR__ . '/src/ShipStation.php';
require_once __DIR__ . '/src/Shopify.php';
require_once __DIR__ . '/src/ApiHealth.php';
require_once __DIR__ . '/src/ConfigValidator.php';
require_once __DIR__ . '/src/Comparator.php';
require_once __DIR__ . '/src/DateRange.php';
require_once __DIR__ . '/src/Reporter.php';
require_once __DIR__ . '/src/SlackNotifier.php';
require_once __DIR__ . '/src/EmailNotifier.php';
require_once __DIR__ . '/src/DiscordNotifier.php';
require_once __DIR__ . '/src/ScanRunner.php';
require_once __DIR__ . '/src/ViewHelpers.php';
require_once __DIR__ . '/src/Actions.php';
require_once __DIR__ . '/src/ToolRegistry.php';
require_once __DIR__ . '/src/ManageSettingsPageLoader.php';
require_once __DIR__ . '/src/SearchLookupPageLoader.php';
require_once __DIR__ . '/src/PackingSlipPageLoader.php';
require_once __DIR__ . '/src/SimpleScanPageLoader.php';
require_once __DIR__ . '/src/FulfillmentIssuePageLoader.php';
require_once __DIR__ . '/src/ProductInventoryPageLoader.php';
require_once __DIR__ . '/src/OrderAnomalyPageLoader.php';
require_once __DIR__ . '/src/OrderPolicyPageLoader.php';
require_once __DIR__ . '/src/OrderInsightPageLoader.php';
require_once __DIR__ . '/src/CustomerLTVPageLoader.php';
require_once __DIR__ . '/src/ItemizedFulfillmentReport.php';
require_once __DIR__ . '/src/PageLoader.php';

Dotenv\Dotenv::createUnsafeImmutable(__DIR__)->safeLoad();
Stores::init(__DIR__);

$log = Logger::getInstance(__DIR__ . '/logs');

set_exception_handler(function (\Throwable $e) use ($log): void {
    $log->error('Uncaught {class}: {message}', [
        'class'     => get_class($e),
        'message'   => $e->getMessage(),
        'exception' => $e->getFile() . ':' . $e->getLine(),
    ]);
    http_response_code(500);
    echo '<h1>Something went wrong.</h1>';
    exit(1);
});

set_error_handler(function (int $severity, string $message, string $file, int $line) use ($log): bool {
    if (!($severity & error_reporting())) return false;
    $log->warning('PHP error [{severity}]: {message}', [
        'severity' => $severity,
        'message'  => $message,
        'exception' => $file . ':' . $line,
    ]);
    return false;
});

// ── Session / auth ────────────────────────────────────────────────────────────

if (session_status() !== PHP_SESSION_ACTIVE) {
    $secureCookie = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $secureCookie,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}
session_start();

$action      = $_POST['action'] ?? '';
$error       = (string) ($_SESSION['_login_error'] ?? '');
unset($_SESSION['_login_error']);
$isLocalhost = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1']);
$csrfToken   = Auth::csrfToken();
$loginPath   = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$authRoute   = (string) ($_GET['auth'] ?? '');
$googleAuth  = GoogleAuth::fromEnvironment();
$googleAuthFlow = new GoogleAuthFlow($googleAuth);
$googleEnabled = $googleAuth->isConfigured();
$googleLoginOnly = filter_var(getenv('GOOGLE_LOGIN_ONLY') ?: '', FILTER_VALIDATE_BOOLEAN);
$passwordLoginEnabled = !$googleLoginOnly;

$redirectToLogin = static function (string $message) use ($loginPath): never {
    $_SESSION['_login_error'] = $message;
    header('Location: ' . $loginPath);
    exit;
};

if ($authRoute === 'google') {
    if (!$googleEnabled) {
        $redirectToLogin('Google sign-in is not configured.');
    }
    header('Location: ' . $googleAuthFlow->begin($_SESSION));
    exit;
}

if ($authRoute === 'google_callback') {
    try {
        $googleResult = $googleAuthFlow->complete($_GET, $_SESSION);
    } catch (Throwable $e) {
        $log->warning('Google sign-in failed: {message}', ['message' => $e->getMessage()]);
        $redirectToLogin('Google sign-in could not be completed. Please try again.');
    }

    if ($googleResult['status'] === GoogleAuthFlow::CANCELLED) {
        $redirectToLogin('Google sign-in was cancelled.');
    }
    if ($googleResult['status'] === GoogleAuthFlow::EXPIRED) {
        $redirectToLogin('Google sign-in session expired. Please try again.');
    }
    if ($googleResult['status'] === GoogleAuthFlow::ERROR) {
        $redirectToLogin('Google sign-in could not be completed.');
    }
    if ($googleResult['status'] === GoogleAuthFlow::DENIED) {
        unset($_SESSION['authed'], $_SESSION['user_role'], $_SESSION['user_email'], $_SESSION['user_name'], $_SESSION['auth_provider']);
        session_regenerate_id(true);
        header('Location: ' . $loginPath . '?auth=access_denied');
        exit;
    }

    $identity = $googleResult['identity'];
    session_regenerate_id(true);
    $_SESSION['authed'] = true;
    $_SESSION['user_role'] = $googleResult['role'];
    $_SESSION['user_email'] = (string) $identity['email'];
    $_SESSION['user_name'] = (string) ($identity['name'] ?? $identity['email']);
    $_SESSION['auth_provider'] = 'google';
    $_SESSION['google_subject'] = (string) $identity['sub'];
    $csrfToken = Auth::rotateCsrfToken();
    header('Location: ' . $loginPath);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !Auth::validateCsrf($_POST['_csrf'] ?? '')) {
    http_response_code(419);
    echo '<h1>Session expired.</h1>';
    exit(1);
}

if ($action === 'login' && $passwordLoginEnabled) {
    $ip        = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $usersFile = __DIR__ . '/data/users.json';
    if (file_exists($usersFile)) {
        // Multi-user mode: users.json takes precedence over ENV credentials
        $role = Auth::attemptMultiUser($_POST['username'] ?? '', $_POST['password'] ?? '', $ip);
        if ($role !== '') {
            session_regenerate_id(true);
            $_SESSION['authed']    = true;
            $_SESSION['user_role'] = $role;
            $csrfToken = Auth::rotateCsrfToken();
            header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
            exit;
        }
        $error = 'Incorrect username or password.';
    } else {
        // Legacy single-user mode via ENV vars
        $legacyUser = getenv('WEB_USERNAME') ?: 'admin';
        $legacyPass = (string) (getenv('WEB_PASSWORD') ?: '');
        if (!$isLocalhost && Auth::isUnsafeLegacyPassword($legacyPass)) {
            $error = 'Dashboard password is not configured. Set WEB_PASSWORD or create data/users.json.';
        } else {
            $error = Auth::attempt($_POST['username'] ?? '', $_POST['password'] ?? '', $legacyUser, $legacyPass ?: 'changeme', $ip);
            if ($error === '') {
                session_regenerate_id(true);
                $_SESSION['authed']    = true;
                $_SESSION['user_role'] = 'admin';
                $csrfToken = Auth::rotateCsrfToken();
                header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
                exit;
            }
        }
    }
}

if ($action === 'dev_login' && $isLocalhost) {
    session_regenerate_id(true);
    $_SESSION['authed']    = true;
    $_SESSION['user_role'] = 'admin';
    $csrfToken = Auth::rotateCsrfToken();
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

if ($action === 'logout') {
    Auth::logout();
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

$authed   = !empty($_SESSION['authed']);
$userRole = $_SESSION['user_role'] ?? 'admin'; // legacy sessions default to admin
$flash    = [];
if ($authed) {
    $flash = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
}

// ── Store config (multi-store or single .env) ─────────────────────────────────

if (Stores::isMultiStore()) {
    $activeStore    = Stores::getActive();
    $shopifyStore   = $activeStore['shopify_store']  ?? 'N/A';
    $shopifyToken   = $activeStore['shopify_token']  ?? '';
    $ssKey          = $activeStore['ss_key']         ?? '';
    $ssSecret       = $activeStore['ss_secret']      ?? '';
    $appStoreNumber = $activeStore['store_number']   ?? '';
    $storeId        = $activeStore['id']             ?? 'default';
    $storeLabel     = $activeStore['label']          ?? $shopifyStore;
    $allStores      = Stores::all();
} else {
    $shopifyStore   = getenv('SHOPIFY_STORE')        ?: 'N/A';
    $shopifyToken   = getenv('SHOPIFY_ACCESS_TOKEN') ?: '';
    $ssKey          = getenv('SS_API_KEY')           ?: '';
    $ssSecret       = getenv('SS_API_SECRET')        ?: '';
    $appStoreNumber = getenv('APP_STORE_NUMBER')     ?: '';
    $storeId        = '';
    $storeLabel     = '';
    $allStores      = [];
}

$webUsername   = getenv('WEB_USERNAME') ?: 'admin';
$webPassword   = getenv('WEB_PASSWORD') ?: '';
$_appTitleEnv  = getenv('APP_TITLE') ?: '';
$appTitle      = $_appTitleEnv ? "{$_appTitleEnv} - Shopify OPS" : 'Shopify OPS';
$appBrand      = $_appTitleEnv ?: 'Shopify OPS';
$appLogo       = getenv('APP_LOGO') ?: '';
$loginBgImage  = getenv('LOGIN_BG_IMAGE') ?: '';
$composerMeta  = json_decode((string) file_get_contents(__DIR__ . '/composer.json'), true) ?: [];
$appVersion    = getenv('APP_VERSION') ?: ($composerMeta['extra']['app-version'] ?? $composerMeta['version'] ?? 'dev');
$cacheTtl       = (int) (getenv('CACHE_TTL')           ?: 82800);   // data validity, default 23 h
$cacheRetention = (int) (getenv('CACHE_RETENTION')    ?: 1209600); // keep on disk after expiry, default 2 weeks

$reportDir = __DIR__ . '/reports' . ($storeId ? "/{$storeId}" : '');
$cacheDir  = __DIR__ . '/cache'   . ($storeId ? "/{$storeId}" : '');
$dataDir   = __DIR__ . '/data'    . ($storeId ? "/{$storeId}" : '');

// ── Shared setup ──────────────────────────────────────────────────────────────

$cacheObj      = null;
$ignoredOrders = [];

if ($authed) {
    $cacheObj = new Cache($cacheDir, $cacheTtl, $cacheRetention);
    if ($storeId) {
        IgnoreList::setDataDir($dataDir);
        PushLog::setDataDir($dataDir);
        RunLog::setDataDir($dataDir);
        UserActionLog::setDataDir($dataDir);
        SlackRules::setDataDir($dataDir);
        JobQueue::setDataDir($dataDir);
    }
    $ignoredOrders = IgnoreList::load();
}

// ── Dispatch early-exit actions ───────────────────────────────────────────────

$ctx = compact('authed', 'action', 'ssKey', 'ssSecret', 'shopifyToken', 'shopifyStore',
               'cacheObj', 'cacheTtl', 'reportDir', 'ignoredOrders', 'appVersion',
               'storeId', 'storeLabel', 'userRole', 'flash');

// Role-based access control for POST actions. Unknown actions are denied so
// new handlers must be explicitly classified in Auth's action permission map.
if ($authed && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $action !== '') {
    if (!Auth::canPerformAction($action)) {
        http_response_code(403);
        echo '<h1>403 Forbidden</h1><p>Your role does not have permission to perform this action.</p>';
        exit;
    }
}

Actions::dispatch($action, $ctx);

// ── Load page data ────────────────────────────────────────────────────────────

$page = $_GET['page'] ?? 'dashboard';

extract(PageLoader::load($page, $action, $ctx), EXTR_SKIP);

// ── Render ────────────────────────────────────────────────────────────────────

ob_start();
if (!$authed && $authRoute === 'access_denied') {
    require __DIR__ . '/views/access-denied.php';
} elseif (!$authed) {
    require __DIR__ . '/views/login.php';
} else {
    require __DIR__ . '/views/layout.php';
}
$html = ob_get_clean();

echo injectCsrfTokens($html, $csrfToken);
