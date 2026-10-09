<?php

declare(strict_types=1);

/**
 * One-shot installer for cPanel + FTP-only deploys.
 *
 * Lives at <docroot>/deploy.php and is gated behind ?key=<DEPLOY_KEY>.
 * On success it runs migrations, warms the prod cache, seeds the baseline
 * roles and (optionally) an initial backoffice admin user, then deletes
 * itself so it cannot be replayed.
 *
 * Why this script does NOT use the Symfony runtime (vendor/autoload_runtime.php):
 * the runtime's closure-wrapping and HttpKernelRunner fallback have proven
 * fragile on shared hosting (output gets eaten when the closure returns a
 * Kernel; the runtime then runs the kernel, producing an empty response
 * because the request path is the installer itself, not a Symfony route).
 * Going through Composer's plain autoloader keeps this script a top-level
 * PHP program: every echo, exit, and header() call is exactly what the
 * browser sees.
 *
 * How to invoke:
 *
 *     https://yourdomain.tld/deploy.php?key=<DEPLOY_KEY>
 *     https://yourdomain.tld/deploy.php?key=<DEPLOY_KEY>&seed=no
 *     https://yourdomain.tld/deploy.php?check=deploy-key       (diagnostic)
 *     https://yourdomain.tld/deploy.php?action=warmup&key=...  (internal: hit
 *                                          by the main flow via HTTP loopback
 *                                          to dodge the stale-autoloader 500
 *                                          after cache:clear; can also be hit
 *                                          manually to retry a stuck warmup)
 *
 * The optional `seed=no` query parameter skips the admin-user seeding step
 * (useful when an operator wants to wire up data manually later).
 *
 * Path: this file is shipped at the doc root, not under bin/. WNPower-class
 * cPanel hosts (and many other shared-hosting providers) use nginx with a
 * default WAF rule that 403s any /bin/* path with their branded error page.
 * Moving the installer to /deploy.php bypasses that rule and works on
 * every shared host regardless of WAF config.
 */

// We must read DEPLOY_KEY from .env.local BEFORE loading Composer's
// autoloader, so that a missing/empty key produces a clean refusal
// page instead of a Symfony boot error.
//
// Find .env.local by walking up from __DIR__. The file lives at the
// project root; __DIR__ is the doc root in this layout.
$projectDir = __DIR__;
$envLocalPath = $projectDir . '/.env.local';

// 1. .env.local must exist.
if (!is_file($envLocalPath)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo "ERROR: .env.local is missing at {$envLocalPath}.\n";
    echo "Copy .env.local.template to .env.local, fill in real values, and retry.\n";
    exit(1);
}

/**
 * Parse DEPLOY_KEY= from .env.local without booting the Symfony runtime.
 * Deliberately tiny — handles KEY=value, KEY="value", KEY='value',
 * ignoring comments and blanks. Strips inline comments after the value.
 * Anything fancier would just invite bugs.
 */
function parseDeployKey(string $envLocalPath): ?string
{
    foreach (file($envLocalPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (!preg_match('/^DEPLOY_KEY\s*=\s*(.*)$/', $line, $m)) {
            continue;
        }
        $value = trim($m[1]);
        // Strip surrounding quotes if any.
        if (strlen($value) >= 2) {
            $first = $value[0];
            $last  = $value[strlen($value) - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
            }
        }
        // Strip an inline comment ("# ..." or " # ...") that the user
        // may have appended after the value. dotenv itself doesn't run
        // here — we're hand-parsing .env.local — and the previous
        // version silently included the comment in the key, which
        // then never matched what the operator typed in the URL.
        $firstCh = $value[0] ?? '';
        if (!in_array($firstCh, ['"', "'"], true)) {
            $hashAt = strpos($value, '#');
            if ($hashAt !== false) {
                $value = rtrim(substr($value, 0, $hashAt));
            }
        }
        return $value;
    }
    return null;
}

$expectedKey = parseDeployKey($envLocalPath);
if ($expectedKey === null || $expectedKey === '') {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo "ERROR: DEPLOY_KEY is missing or empty in .env.local.\n";
    echo "Set it to a long random string (e.g. 32 hex chars from `openssl rand -hex 16`).\n";
    exit(1);
}

// 2. Diagnostic mode: ?check=deploy-key reports the DEPLOY_KEY
//    length and a short fingerprint (sha256 prefix) so the operator
//    can compare against the value they typed in the URL. No secret
//    material is exposed. Activated by an explicit query parameter, so
//    a casual drive-by can't see it.
if (($_GET['check'] ?? '') === 'deploy-key') {
    http_response_code(200);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    $report = sprintf(
        "DEPLOY_KEY in .env.local: %d chars, sha256:%s\n",
        strlen($expectedKey),
        substr(hash('sha256', $expectedKey), 0, 12),
    );
    if (isset($_GET['key'])) {
        $provided = (string) $_GET['key'];
        $sameLen  = strlen($provided) === strlen($expectedKey);
        $report .= sprintf(
            "Provided ?key=: %d chars, sha256:%s, lengths_match=%s\n",
            strlen($provided),
            substr(hash('sha256', $provided), 0, 12),
            $sameLen ? 'yes' : 'NO',
        );
        if ($sameLen) {
            $report .= sprintf(
                "hash_equals() result: %s\n",
                hash_equals($expectedKey, $provided) ? 'TRUE (mismatch above is a UI bug — please report)' : 'FALSE (lengths match but bytes differ — check for hidden chars like trailing space / CRLF / unicode lookalike)',
            );
        }
    } else {
        $report .= "Tip: append &key=<your-typed-value> to compare against the URL you're hitting.\n";
    }
    echo $report;
    exit(0);
}

// 3. ?key=<DEPLOY_KEY> must match.
$providedKey = $_GET['key'] ?? '';
if (!is_string($providedKey) || $providedKey === '' || !hash_equals($expectedKey, $providedKey)) {
    $providedLen = strlen($providedKey);
    $expectedLen = strlen($expectedKey);
    $hint = ($providedKey === '')
        ? 'No ?key= was provided in the URL.'
        : (($providedLen !== $expectedLen)
            ? sprintf('Lengths differ: you sent %d chars, expected %d.', $providedLen, $expectedLen)
            : 'Lengths match but bytes differ — likely a hidden character (trailing space, CRLF, smart-quote lookalike) in either the URL or .env.local.');
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo "Forbidden.\n\n{$hint}\n\nRun ?check=deploy-key for a non-secret fingerprint comparison, or copy the DEPLOY_KEY value byte-for-byte from .env.local.\n";
    exit(1);
}

// 4. Bootstrap. From here on we need Composer's autoloader so the
//    App\Kernel class can be resolved. We do NOT use the Symfony
//    runtime here — see the file-level docblock.
require $projectDir . '/vendor/autoload.php';

// 4a. Load .env + .env.local via the Dotenv component. The runtime
//     used to do this for us; since we're bypassing the runtime, we
//     must do it ourselves, otherwise commands like cache:clear will
//     fail with "Environment variable not found" when they evaluate
//     %env(...)% placeholders in the container.
//
//     We use a one-shot Dotenv (no factory) and call bootEnv which
//     loads .env, then .env.local (overriding earlier values) for
//     non-test envs.
$dotenv = new Symfony\Component\Dotenv\Dotenv();
$dotenv->bootEnv($projectDir . '/.env');
// At this point $_SERVER/$_ENV have APP_ENV, DATABASE_URL, MAILER_DSN,
// etc. from .env. We force prod so the operator can't accidentally
// run the installer in dev mode by leaving a stray .env.local value.
$_SERVER['APP_ENV']   = $_ENV['APP_ENV']   = 'prod';
$_SERVER['APP_DEBUG'] = $_ENV['APP_DEBUG'] = '0';
putenv('APP_ENV=prod');
putenv('APP_DEBUG=0');

// Stream output progressively so the operator sees progress on long
// commands (cache:warmup, etc.) instead of getting a blank page
// for 30 seconds.
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
@ini_set('output_buffering', '0');
@ini_set('implicit_flush', '1');
while (ob_get_level() > 0) {
    ob_end_flush();
}
@ob_implicit_flush(true);

echo "=== ktn_gestion deploy installer ===\n";
echo "Project dir: {$projectDir}\n";
echo "PHP: " . PHP_VERSION . "\n";
echo "Time: " . date('c') . "\n";
echo "\n";

/** @var \App\Kernel $kernel */
$kernel = new App\Kernel('prod', false);
$kernel->boot();

$application = new Symfony\Bundle\FrameworkBundle\Console\Application($kernel);
$application->setAutoExit(false);
$application->setCatchExceptions(false);

// Sub-mode: ?action=warmup runs cache:warmup in THIS PHP worker
// (fresh autoloader class map — no stale entries from the OLD
// container that cache:clear deleted) and exits. The main flow
// calls this via HTTP loopback to dodge the stale-autoloader bug
// that the closure-with-`use(&$application)` pattern couldn't get
// past, and that proc_open is disabled on cPanel so we can't spawn
// a subprocess. The loopback goes through nginx/php-fpm, which
// hands the request to a fresh PHP-FPM worker with no Composer's
// class map entries from the older container.
if (($_GET['action'] ?? '') === 'warmup') {
    $input = new Symfony\Component\Console\Input\ArrayInput(['command' => 'cache:warmup', '--env' => 'prod', '--no-debug' => true]);
    $output = new Symfony\Component\Console\Output\BufferedOutput();
    $exit = $application->run($input, $output);
    echo $output->fetch();
    echo "OK\n";
    exit($exit === 0 ? 0 : 1);
}

$failed = false;
$statusCode = 200;

$runCommand = static function (array $args, string $label) use (&$application): bool {
    echo "\n>>> {$label}\n";
    @ob_flush();
    @flush();
    $input = new Symfony\Component\Console\Input\ArrayInput($args);
    $output = new Symfony\Component\Console\Output\BufferedOutput();
    try {
        $exit = $application->run($input, $output);
    } catch (Throwable $e) {
        echo "ERROR: \"{$label}\" threw: " . $e->getMessage() . "\n";
        return false;
    }
    echo $output->fetch();
    @ob_flush();
    @flush();
    if ($exit !== 0) {
        echo "ERROR: \"{$label}\" exited with code {$exit}.\n";
        return false;
    }
    return true;
};

try {
    if (!$runCommand(['command' => 'doctrine:migrations:migrate', '--no-interaction' => true], 'doctrine:migrations:migrate')) {
        throw new RuntimeException('Migrations failed.');
    }

    if (!$runCommand(['command' => 'cache:clear', '--env' => 'prod', '--no-debug' => true], 'cache:clear (prod)')) {
        throw new RuntimeException('Cache clear failed.');
    }

    // cache:clear just deleted the compiled container from disk, but
    // the OLD kernel's autoloader class map still has entries pointing
    // at the now-deleted files. The closure-with-`use(&$application)`
    // pattern keeps the OLD application alive long enough that the
    // NEW kernel's cache:warmup picks up the stale autoloader entries
    // and tries to require() the deleted files — "Failed opening
    // required '.../ContainerGeJduCQ/getConsole_ErrorListenerService.php'".
    //
    // Fix: hit this same deploy.php with ?action=warmup via an HTTP
    // loopback. Apache/nginx hands the request to a fresh PHP-FPM
    // worker whose Composer's class map is empty, so the stale
    // entries cannot fire. proc_open is disabled on cPanel so we
    // can't spawn a subprocess; the stream layer's HTTP loopback
    // doesn't need it.
    echo "\n>>> cache:warmup (prod) [fresh PHP-FPM worker via HTTP loopback]\n";
    @ob_flush();
    @flush();
    $isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $proto = $isHttps ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
    $loopbackUrl = $proto . '://' . $host . '/deploy.php?action=warmup&key=' . urlencode($providedKey);
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 120,
            'ignore_errors' => true,
            'header' => 'Connection: close',
        ],
    ]);
    $response = @file_get_contents($loopbackUrl, false, $ctx);
    if ($response === false || strpos((string) $response, 'OK') === false) {
        throw new RuntimeException(sprintf(
            'Cache warmup failed (loopback returned %s). Try re-running.',
            $response === false ? 'no response' : 'unexpected body'
        ));
    }
    @ob_flush();
    @flush();

    // After the subprocess, also drop the OLD kernel from the current
    // process so the assets:install and seed-prod commands below use a
    // clean container. They could use the same subprocess trick, but
    // they've never shown the stale-autoloader issue in practice (they
    // don't trigger cached-class loading the way cache:warmup does).
    unset($application);
    gc_collect_cycles();
    /** @var \App\Kernel $kernel */
    $kernel = new App\Kernel('prod', false);
    $kernel->boot();
    $application = new Symfony\Bundle\FrameworkBundle\Console\Application($kernel);
    $application->setAutoExit(false);
    $application->setCatchExceptions(false);

    // Symlink installs of /public/bundles/* — harmless for our
    // Vite-only bundle (Vite emits directly into build/), but
    // Symfony's recipe auto-scripts always ran this in dev. Keep
    // parity so an `composer install --scripts` later is a no-op.
    $runCommand(['command' => 'assets:install', 'target' => '.', '--symlink' => false, '--relative' => false], 'assets:install');

    // Optional seeding. Default ON — without an initial admin the
    // operator would have to manually create one via the DB. Pass
    // ?seed=no to skip.
    $skipSeed = (($_GET['seed'] ?? '') === 'no');
    if (!$skipSeed) {
        if (!$runCommand(['command' => 'app:deploy:seed-prod'], 'app:deploy:seed-prod')) {
            throw new RuntimeException('Seed-prod failed.');
        }
    }
} catch (Throwable $e) {
    $failed = true;
    $statusCode = 500;
    echo "\n!!! Deployment FAILED: " . $e->getMessage() . "\n";
    echo "    deploy.php has NOT been removed so you can re-run after fixing the issue.\n";
    echo "    Last error logged to var/log/prod.log (if log dir is writable).\n";
    @ob_flush();
    @flush();
}

if (!$failed) {
    echo "\n=== Deployment complete ===\n";
    echo "• Migrations applied\n";
    echo "• Cache rebuilt\n";
    echo "• Baseline roles and backoffice admin user (if configured) seeded\n";
    echo "\nNEXT STEPS:\n";
    echo "  1. Confirm the seeded admin has set their password via the emailed confirmation link.\n";
    echo "  2. Delete this file from the server (deploy.php) — it is no longer reachable.\n";
    echo "  3. Delete this entire installer bundle if you do not plan to reuse it: deploy.php\n";

    if (@unlink(__FILE__)) {
        echo "\n✓ deploy.php has self-deleted.\n";
    } else {
        echo "\nWARNING: could not self-delete deploy.php — please remove it manually.\n";
    }
    @ob_flush();
    @flush();
}

// We don't http_response_code() at the end because all output has
// already been sent by this point, and any status-code change would
// silently no-op (PHP can't change the status line once headers are
// flushed). The success path leaves 200 in place; the failure path
// would ideally be 500 but the operator reads the body and sees
// "!!! Deployment FAILED" — the HTTP code is a polish detail, not a
// signal they read.
exit($failed ? 1 : 0);