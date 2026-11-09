<?php

/*
 * NunezOrganizer setup: one script for local development (Docker or native) and
 * for a simple in-place production install. Needs only PHP 8.4 (no vendor/ yet).
 *
 *   php setup.php                       local development (default)
 *   php setup.php --env=production      in-place production install
 *   php setup.php --check               report problems, change nothing
 *   docker compose exec app php setup.php
 *
 * Run `php setup.php --help` for all options. Safe to re-run: every step checks
 * what already exists. For zero-downtime production releases use deploy/ instead
 * (docs/install.md explains both paths).
 *
 * This file deliberately sticks to syntax older PHP versions can parse, so a too-old
 * PHP prints a clear message instead of a parse error.
 */

const NUNEZ_MIN_PHP_ID = 80400;
const NUNEZ_MIN_NODE = '22.18.0';

exit(nunez_main(isset($argv) ? $argv : array()));

// ---------------------------------------------------------------------------
// Entry point
// ---------------------------------------------------------------------------

function nunez_main(array $argv)
{
    $opts = nunez_parse_args(array_slice($argv, 1));
    if (isset($opts['help'])) {
        nunez_usage();

        return 0;
    }

    $ctx = nunez_context($opts);
    chdir($ctx['root']);

    nunez_title('NunezOrganizer setup · ' . ($ctx['check'] ? 'check' : $ctx['env']) . ($ctx['dry_run'] ? ' (dry run)' : '') . ($ctx['in_docker'] ? ' · Docker' : ''));

    $problems = nunez_preflight($ctx);
    if ($ctx['check']) {
        return nunez_check($ctx, $problems);
    }
    if ($problems) {
        nunez_fail_list('Fix these first, then re-run setup:', $problems);

        return 1;
    }

    try {
        if ($ctx['env'] === 'production') {
            nunez_production($ctx);
        } else {
            nunez_local($ctx);
        }
    } catch (RuntimeException $e) {
        nunez_err($e->getMessage());

        return 1;
    }

    return 0;
}

function nunez_usage()
{
    echo <<<'TXT'
NunezOrganizer setup

Usage: php setup.php [options]

Modes
  (default)                 Local development: dev dependencies, .env from .env.example,
                            APP_KEY, migrations, demo users (alice/bob), assets.
  --env=production          In-place production install: --no-dev dependencies,
                            production .env (validated), migrations (with confirmation),
                            asset build, caches, then prints cron/queue/web server steps.
  --check                   Report what's missing or unsafe. Changes nothing.

Options
  -n, --no-interaction      Never prompt; use flags, environment variables and defaults.
  --force                   Production: run migrations without asking.
  --dry-run                 Print every step and command; change nothing.
  --skip-assets             Don't run npm (e.g. assets are built elsewhere).
  --skip-seed               Local: don't create the demo users.
  --skip-migrate            Don't run migrations.
  --allow-http              Production: accept an http:// APP_URL (e.g. behind a TLS proxy).
  --composer=PATH           Composer to use (default: `composer` on PATH or ./composer.phar).

Values for a new .env (otherwise prompted, or defaults with -n)
  --app-url=URL             e.g. https://organizer.example.com
  --db-host=HOST            default 127.0.0.1 (mariadb inside Docker)
  --db-port=PORT            default 3306
  --db-name=NAME            default nunezorganizer
  --db-user=USER            default nunezorganizer
  --mail-from=ADDRESS       production only, default no-reply@<app host>
  The DB password is read from the NUNEZ_DB_PASSWORD environment variable or prompted
  for (never passed on the command line, where other users could see it).

Docs: docs/install.md (all hosts), docs/development.md (Docker + Xdebug).

TXT;
}

function nunez_parse_args(array $args)
{
    $opts = array();
    foreach ($args as $arg) {
        if ($arg === '-n') {
            $opts['no-interaction'] = true;
        } elseif ($arg === '-h') {
            $opts['help'] = true;
        } elseif (strpos($arg, '--') === 0) {
            $parts = explode('=', substr($arg, 2), 2);
            $opts[$parts[0]] = count($parts) === 2 ? $parts[1] : true;
        } else {
            fwrite(STDERR, "Unknown argument: {$arg} (see --help)\n");
            exit(2);
        }
    }
    $known = array('help', 'env', 'check', 'no-interaction', 'force', 'dry-run', 'skip-assets', 'skip-seed',
        'skip-migrate', 'allow-http', 'composer', 'app-url', 'db-host', 'db-port', 'db-name', 'db-user', 'mail-from');
    foreach (array_keys($opts) as $key) {
        if (! in_array($key, $known, true)) {
            fwrite(STDERR, "Unknown option: --{$key} (see --help)\n");
            exit(2);
        }
    }

    return $opts;
}

function nunez_context(array $opts)
{
    $env = isset($opts['env']) ? $opts['env'] : 'local';
    if (! in_array($env, array('local', 'production'), true)) {
        fwrite(STDERR, "--env must be local or production\n");
        exit(2);
    }
    $interactive = ! isset($opts['no-interaction']) && function_exists('stream_isatty') && @stream_isatty(STDIN);

    return array(
        'root' => __DIR__,
        'env' => $env,
        'check' => isset($opts['check']),
        'dry_run' => isset($opts['dry-run']),
        'interactive' => $interactive,
        'force' => isset($opts['force']),
        'skip_assets' => isset($opts['skip-assets']),
        'skip_seed' => isset($opts['skip-seed']),
        'skip_migrate' => isset($opts['skip-migrate']),
        'allow_http' => isset($opts['allow-http']),
        'in_docker' => getenv('NUNEZ_IN_DOCKER') === '1',
        'windows' => DIRECTORY_SEPARATOR === '\\',
        'php' => PHP_BINARY,
        'composer_opt' => isset($opts['composer']) ? $opts['composer'] : null,
        'composer' => null,
        'values' => array(
            'app-url' => isset($opts['app-url']) ? $opts['app-url'] : null,
            'db-host' => isset($opts['db-host']) ? $opts['db-host'] : null,
            'db-port' => isset($opts['db-port']) ? $opts['db-port'] : null,
            'db-name' => isset($opts['db-name']) ? $opts['db-name'] : null,
            'db-user' => isset($opts['db-user']) ? $opts['db-user'] : null,
            'mail-from' => isset($opts['mail-from']) ? $opts['mail-from'] : null,
        ),
        // Test hooks (deploy/tests/setup.sh); not for normal use.
        'min_php_id' => getenv('NUNEZ_SETUP_MIN_PHP_ID') ? (int) getenv('NUNEZ_SETUP_MIN_PHP_ID') : NUNEZ_MIN_PHP_ID,
        'skip_db_check' => getenv('NUNEZ_SETUP_SKIP_DB_CHECK') === '1',
    );
}

// ---------------------------------------------------------------------------
// Preflight: PHP, extensions, Composer, Node, writable dirs
// ---------------------------------------------------------------------------

function nunez_preflight(array &$ctx)
{
    nunez_step('Checking requirements');
    $problems = array();

    if (PHP_VERSION_ID < $ctx['min_php_id']) {
        $problems[] = 'PHP ' . PHP_VERSION . ' is too old: NunezOrganizer needs PHP 8.4+ (' . PHP_BINARY . ').'
            . ' Use the Docker environment (docs/development.md) or install PHP 8.4.';

        return $problems; // nothing else is meaningful on an old PHP
    }
    nunez_ok('PHP ' . PHP_VERSION);

    $required = array('bcmath', 'ctype', 'curl', 'dom', 'fileinfo', 'filter', 'intl', 'mbstring', 'openssl',
        'pdo_mysql', 'session', 'tokenizer', 'xml');
    $missing = array();
    foreach ($required as $ext) {
        if (! extension_loaded($ext)) {
            $missing[] = $ext;
        }
    }
    if ($missing) {
        $problems[] = 'PHP extensions missing: ' . implode(', ', $missing) . ' (enable them in ' . (php_ini_loaded_file() ?: 'php.ini') . ')';
    } else {
        nunez_ok('PHP extensions');
    }
    if (! extension_loaded('pcntl') && ! $ctx['windows']) {
        $msg = 'pcntl is not loaded: queue workers cannot stop gracefully';
        if ($ctx['env'] === 'production') {
            $problems[] = $msg;
        } else {
            nunez_warn($msg);
        }
    }

    $ctx['composer'] = nunez_find_composer($ctx);
    if ($ctx['composer'] === null) {
        $problems[] = 'Composer 2 not found: install it (https://getcomposer.org) or pass --composer=/path/to/composer';
    } else {
        nunez_ok('Composer: ' . $ctx['composer']);
    }

    if (nunez_needs_npm($ctx)) {
        $node = nunez_capture('node --version');
        $version = $node === null ? null : ltrim(trim($node), 'v');
        if ($version === null || $version === '') {
            $problems[] = 'Node.js ' . NUNEZ_MIN_NODE . '+ not found (needed for the asset build; or pass --skip-assets)';
        } elseif (version_compare($version, NUNEZ_MIN_NODE, '<')) {
            $problems[] = "Node.js {$version} is too old: the asset build needs " . NUNEZ_MIN_NODE . '+';
        } else {
            nunez_ok("Node.js {$version}");
        }
    }

    $writable = true;
    foreach (array('storage', 'bootstrap/cache') as $dir) {
        if (! is_dir($dir) || ! is_writable($dir)) {
            $writable = false;
            $problems[] = "{$dir}/ is not writable by " . nunez_whoami() . ' (the web server and workers write here)';
        }
    }
    if ($writable) {
        nunez_ok('storage/ and bootstrap/cache/ writable');
    }

    return $problems;
}

function nunez_needs_npm(array $ctx)
{
    if ($ctx['skip_assets']) {
        return false;
    }
    if ($ctx['in_docker'] && $ctx['env'] === 'local') {
        return false; // the `node` service builds and serves assets
    }
    if ($ctx['check'] && (is_file('public/build/manifest.json') || is_file('public/hot'))) {
        return false; // assets already built; --check reports on them separately
    }

    return true;
}

function nunez_find_composer(array $ctx)
{
    if ($ctx['composer_opt'] !== null) {
        return is_file($ctx['composer_opt']) || nunez_capture(nunez_q($ctx['composer_opt']) . ' --version') !== null
            ? $ctx['composer_opt'] : null;
    }
    if (nunez_capture('composer --version') !== null) {
        return 'composer';
    }
    if (is_file('composer.phar')) {
        return nunez_q($ctx['php']) . ' composer.phar';
    }

    return null;
}

// ---------------------------------------------------------------------------
// Local development
// ---------------------------------------------------------------------------

function nunez_local(array $ctx)
{
    nunez_step('Installing PHP dependencies');
    nunez_run($ctx, nunez_composer_cmd($ctx) . ' install --no-interaction --prefer-dist --no-progress');

    nunez_step('Environment file');
    nunez_ensure_local_env($ctx);
    nunez_ensure_app_key($ctx);
    if (is_file('.env')) {
        nunez_validate_env($ctx, nunez_read_env('.env'), 'local');
    }
    nunez_artisan($ctx, 'config:clear --no-interaction');

    if (! $ctx['skip_migrate']) {
        nunez_step('Database');
        nunez_wait_for_db($ctx, 90);
        nunez_artisan($ctx, 'migrate --no-interaction');
        if (! $ctx['skip_seed']) {
            nunez_artisan($ctx, 'db:seed --no-interaction');
        }
        nunez_check_test_db($ctx);
    }

    nunez_step('Front-end assets');
    if ($ctx['skip_assets']) {
        nunez_ok('skipped (--skip-assets)');
    } elseif ($ctx['in_docker']) {
        nunez_ok('the `node` container builds and serves them (docker compose logs -f node)');
    } else {
        nunez_run($ctx, 'npm ci --no-audit --no-fund');
        nunez_run($ctx, 'npm run build');
    }

    $env = nunez_read_env('.env');
    nunez_title('Ready');
    $url = isset($env['APP_URL']) ? $env['APP_URL'] : 'http://localhost';
    echo "  App:        {$url}\n";
    if ($ctx['in_docker']) {
        echo '  Mailpit:    http://localhost:' . nunez_env_or($env, 'NUNEZ_MAILPIT_PORT', '8482') . "\n";
        echo '  phpMyAdmin: http://localhost:' . nunez_env_or($env, 'NUNEZ_PMA_PORT', '8481') . "\n";
    } else {
        echo "  Serve it:   php artisan serve   (or point a web server at public/)\n";
    }
    if (! $ctx['skip_seed'] && ! $ctx['skip_migrate']) {
        echo "  Log in:     alice@example.test / bob@example.test, password \"password\"\n";
    }
    $prefix = $ctx['in_docker'] ? 'docker compose exec app ' : '';
    echo "  Tests:      {$prefix}php artisan test\n";
    echo "  Invite:     {$prefix}php artisan nunez:invite you@example.com\n";
}

function nunez_ensure_local_env(array $ctx)
{
    $created = false;
    if (! is_file('.env')) {
        if (! is_file('.env.example')) {
            throw new RuntimeException('.env.example is missing');
        }
        nunez_write_file($ctx, '.env', file_get_contents('.env.example'));
        $created = true;
        nunez_ok('.env created from .env.example');
    } else {
        nunez_ok('.env exists; keeping it');
    }

    $set = array();
    if ($created && ! $ctx['in_docker']) {
        // .env.example targets Docker service names; a native install talks to localhost.
        $set = array('DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306', 'MAIL_MAILER' => 'log', 'APP_URL' => 'http://127.0.0.1:8000');
    }
    $set = array_merge($set, nunez_db_flag_values($ctx));
    if ($ctx['values']['app-url'] !== null) {
        $set['APP_URL'] = $ctx['values']['app-url'];
    }
    if ($set) {
        nunez_set_env_values($ctx, '.env', $set);
        nunez_ok('.env updated: ' . implode(', ', array_keys($set)));
    }
    if (getenv('NUNEZ_DB_PASSWORD') !== false && getenv('NUNEZ_DB_PASSWORD') !== '') {
        nunez_set_env_values($ctx, '.env', array('DB_PASSWORD' => getenv('NUNEZ_DB_PASSWORD')));
    }
}

function nunez_check_test_db(array $ctx)
{
    if ($ctx['dry_run'] || $ctx['skip_db_check']) {
        return;
    }
    $env = nunez_read_env('.env');
    $env['DB_DATABASE'] = 'nunezorganizer_test';
    if (nunez_db_connect($env) === true) {
        nunez_ok('test database nunezorganizer_test reachable');
    } else {
        nunez_warn("test database nunezorganizer_test not reachable: `php artisan test` needs it.\n"
            . "     Create it as the MariaDB root user:\n"
            . "       CREATE DATABASE nunezorganizer_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n"
            . "       GRANT ALL ON nunezorganizer_test.* TO '" . nunez_env_or($env, 'DB_USERNAME', 'nunezorganizer') . "'@'%';");
    }
}

// ---------------------------------------------------------------------------
// Production (in place)
// ---------------------------------------------------------------------------

function nunez_production(array $ctx)
{
    nunez_step('Installing PHP dependencies (no dev packages)');
    nunez_run($ctx, nunez_composer_cmd($ctx) . ' install --no-dev --optimize-autoloader --no-interaction --prefer-dist --no-progress');

    nunez_step('Environment file');
    nunez_ensure_production_env($ctx);
    nunez_ensure_app_key($ctx);
    $errors = is_file('.env') ? nunez_validate_env($ctx, nunez_read_env('.env'), 'production') : array();
    if ($errors && ! $ctx['dry_run']) {
        throw new RuntimeException('.env is not production-safe (see above). Fix it and re-run.');
    }

    if (! $ctx['skip_migrate']) {
        nunez_step('Database');
        nunez_wait_for_db($ctx, 15);
        if ($ctx['force'] || nunez_confirm($ctx, 'Run database migrations now? Back up the database first if it holds data.')) {
            nunez_artisan($ctx, 'migrate --force --no-interaction');
        } else {
            nunez_warn('migrations skipped: run `php artisan migrate --force` when ready (or re-run with --force)');
        }
    }

    nunez_step('Front-end assets');
    if ($ctx['skip_assets']) {
        nunez_ok('skipped (--skip-assets): public/build must come from somewhere else');
    } else {
        nunez_run($ctx, 'npm ci --no-audit --no-fund');
        nunez_run($ctx, 'npm run build');
    }

    nunez_step('Caches');
    nunez_artisan($ctx, 'optimize --no-interaction');

    $root = $ctx['root'];
    $php = PHP_BINARY;
    nunez_title('Installed. Finish these on the server (details: docs/install.md)');
    echo <<<TXT
  1. Web server: document root -> {$root}/public  (HTTPS only; .env must not be web-reachable)
  2. Scheduler, every minute (crontab of the user that owns these files):
       * * * * * cd {$root} && {$php} artisan schedule:run >> /dev/null 2>&1
  3. Queue worker, kept running by systemd or supervisor:
       {$php} {$root}/artisan queue:work database --queue=notifications,default --max-time=3600
  4. Permissions: storage/ and bootstrap/cache/ writable by the web server and worker user.
  5. First account: {$php} artisan nunez:invite you@example.com
  6. After editing .env: {$php} artisan optimize && {$php} artisan queue:restart

TXT;
}

function nunez_ensure_production_env(array $ctx)
{
    if (is_file('.env')) {
        nunez_ok('.env exists; keeping it (only flags you passed are applied)');
        $set = nunez_db_flag_values($ctx);
        if ($ctx['values']['app-url'] !== null) {
            $set['APP_URL'] = $ctx['values']['app-url'];
        }
        if ($set) {
            nunez_set_env_values($ctx, '.env', $set);
        }

        return;
    }

    $tpl = 'deploy/templates/env/production.env.tpl';
    if (! is_file($tpl)) {
        throw new RuntimeException("{$tpl} is missing");
    }

    $appUrl = nunez_value($ctx, 'app-url', 'Public URL of the app (https://...)', null);
    if ($appUrl === '' || $appUrl === null) {
        throw new RuntimeException('APP_URL is required: pass --app-url=https://your.domain');
    }
    $appUrl = rtrim($appUrl, '/');
    $host = parse_url($appUrl, PHP_URL_HOST) ?: 'localhost';
    $values = array(
        'APP_URL' => $appUrl,
        'APP_KEY_VALUE' => nunez_new_key(),
        'DB_HOST' => nunez_value($ctx, 'db-host', 'Database host', '127.0.0.1'),
        'DB_PORT' => nunez_value($ctx, 'db-port', 'Database port', '3306'),
        'DB_NAME' => nunez_value($ctx, 'db-name', 'Database name', 'nunezorganizer'),
        'DB_USER' => nunez_value($ctx, 'db-user', 'Database user', 'nunezorganizer'),
        'MAIL_FROM_ADDRESS' => nunez_value($ctx, 'mail-from', 'Sender address for app email', 'no-reply@' . preg_replace('/^www\./', '', $host)),
        'QUEUE_CONNECTION' => 'database',
        'APP_ROOT' => dirname($ctx['root']),
        'APP_USER' => nunez_whoami(),
    );
    $content = file_get_contents($tpl);
    foreach ($values as $k => $v) {
        $content = str_replace('{{' . $k . '}}', $v, $content);
    }
    if (preg_match('/\{\{[A-Z_]+\}\}/', $content, $m)) {
        throw new RuntimeException("unrendered placeholder {$m[0]} in {$tpl}");
    }
    // Comments in the template describe the deploy/ release layout; point them here instead.
    $content = str_replace($values['APP_ROOT'] . '/shared/.env', $ctx['root'] . '/.env', $content);

    $password = getenv('NUNEZ_DB_PASSWORD');
    if ($password === false || $password === '') {
        $password = nunez_prompt_secret($ctx, 'Database password');
    }
    $line = 'DB_PASSWORD=' . nunez_env_quote($password);
    $content = preg_replace_callback('/^DB_PASSWORD=.*$/m', function () use ($line) {
        return $line;
    }, $content);

    nunez_write_file($ctx, '.env', $content, 0600);
    nunez_ok('.env written (mode 600) with a new APP_KEY');
}

// ---------------------------------------------------------------------------
// --check
// ---------------------------------------------------------------------------

function nunez_check(array $ctx, array $problems)
{
    $env = is_file('.env') ? nunez_read_env('.env') : null;
    $mode = $env !== null && isset($env['APP_ENV']) && $env['APP_ENV'] === 'production' ? 'production' : $ctx['env'];

    nunez_step('Project');
    if (! is_file('vendor/autoload.php')) {
        $problems[] = 'vendor/ missing: run setup (composer install)';
    } else {
        nunez_ok('vendor/ installed');
    }
    if (! is_file('public/build/manifest.json') && ! is_file('public/hot')) {
        $problems[] = 'no front-end assets: run `npm run build` (or start the Vite dev server)';
    } else {
        nunez_ok('front-end assets present');
    }

    nunez_step("Environment ({$mode})");
    if ($env === null) {
        $problems[] = '.env missing: run setup';
    } else {
        $problems = array_merge($problems, nunez_validate_env($ctx, $env, $mode));
        if (! $ctx['skip_db_check'] && extension_loaded('pdo_mysql')) {
            $db = nunez_db_connect($env);
            if ($db === true) {
                nunez_ok('database reachable');
            } else {
                $problems[] = 'database not reachable: ' . $db;
            }
        }
    }

    if ($problems) {
        nunez_fail_list('Problems found:', $problems);

        return 1;
    }
    nunez_title('All checks passed');

    return 0;
}

// ---------------------------------------------------------------------------
// .env handling and validation
// ---------------------------------------------------------------------------

function nunez_ensure_app_key(array $ctx)
{
    $env = nunez_read_env('.env');
    if (! empty($env['APP_KEY'])) {
        nunez_ok('APP_KEY already set');

        return;
    }
    nunez_set_env_values($ctx, '.env', array('APP_KEY' => nunez_new_key()));
    nunez_ok('APP_KEY generated');
}

function nunez_new_key()
{
    return 'base64:' . base64_encode(random_bytes(32));
}

/** Returns a list of blocking problems and prints them; warnings are printed only. */
function nunez_validate_env(array $ctx, array $env, $mode)
{
    $get = function ($k) use ($env) {
        return isset($env[$k]) ? $env[$k] : '';
    };
    $lower = function ($k) use ($get) {
        return strtolower($get($k));
    };
    $errors = array();
    $warnings = array();

    if (! preg_match('/^base64:.{40,}$/', $get('APP_KEY'))) {
        $errors[] = 'APP_KEY missing or invalid';
    }
    if ($get('DB_HOST') === 'mariadb' && ! $ctx['in_docker']) {
        $warnings[] = 'DB_HOST=mariadb only resolves inside Docker; natively use 127.0.0.1';
    }

    if ($mode === 'production') {
        if ($get('APP_ENV') !== 'production') {
            $errors[] = 'APP_ENV must be production';
        }
        if (! in_array($lower('APP_DEBUG'), array('false', '0', ''), true)) {
            $errors[] = 'APP_DEBUG must be false';
        }
        $https = strpos($get('APP_URL'), 'https://') === 0;
        if (! $https && ! $ctx['allow_http']) {
            $errors[] = 'APP_URL must start with https:// (or pass --allow-http behind a TLS proxy)';
        }
        if ($https && $lower('SESSION_SECURE_COOKIE') !== 'true') {
            $errors[] = 'SESSION_SECURE_COOKIE must be true';
        }
        if ($get('DB_PASSWORD') === '' || in_array($get('DB_PASSWORD'), array('secret', 'password', 'root', 'changeme'), true)) {
            $errors[] = 'DB_PASSWORD is empty or a placeholder';
        }
        if ($get('DB_USERNAME') === 'root') {
            $errors[] = 'DB_USERNAME must not be root';
        }
        if ($lower('LOG_LEVEL') === 'debug') {
            $errors[] = 'LOG_LEVEL=debug in production (use warning)';
        }
        if ($lower('NUNEZ_REGISTRATION_MODE') === 'open') {
            $errors[] = 'NUNEZ_REGISTRATION_MODE=open: use invite (default) or approval';
        }
        if ($get('MAIL_HOST') === 'mailpit') {
            $errors[] = 'MAIL_HOST=mailpit is the dev value';
        }
        if (in_array($lower('MAIL_MAILER'), array('log', 'array', ''), true)) {
            $warnings[] = 'MAIL_MAILER is log/array: verification and password-reset emails will not be sent';
        }
        if (! $ctx['windows'] && is_file('.env') && (fileperms('.env') & 0077) !== 0) {
            $warnings[] = '.env is readable by other users: chmod 600 .env';
        }
    }

    foreach ($warnings as $w) {
        nunez_warn($w);
    }
    foreach ($errors as $e) {
        nunez_bad($e);
    }
    if (! $errors) {
        nunez_ok(".env looks right for {$mode}");
    }

    return $errors;
}

function nunez_read_env($path)
{
    $out = array();
    if (! is_file($path)) {
        return $out;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES) as $line) {
        if (! preg_match('/^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/', $line, $m)) {
            continue;
        }
        $v = trim($m[2]);
        if ($v !== '' && ($v[0] === '"' || $v[0] === "'")) {
            $q = $v[0];
            $end = strpos($v, $q, 1);
            $v = $end === false ? substr($v, 1) : substr($v, 1, $end - 1);
        } else {
            $v = preg_replace('/\s+#.*$/', '', $v);
        }
        $out[$m[1]] = $v;
    }

    return $out;
}

function nunez_env_quote($value)
{
    $value = (string) $value;
    if ($value === '' || preg_match('/^[A-Za-z0-9_.:\/@+=,-]+$/', $value)) {
        return $value;
    }

    return '"' . str_replace(array('\\', '"', '$'), array('\\\\', '\\"', '\\$'), $value) . '"';
}

function nunez_set_env_values(array $ctx, $path, array $values)
{
    $content = is_file($path) ? file_get_contents($path) : '';
    foreach ($values as $key => $value) {
        $line = $key . '=' . nunez_env_quote($value);
        $pattern = '/^' . preg_quote($key, '/') . '=.*$/m';
        if (preg_match($pattern, $content)) {
            $content = preg_replace_callback($pattern, function () use ($line) {
                return $line;
            }, $content, 1);
        } else {
            $content = rtrim($content, "\n") . "\n" . $line . "\n";
        }
    }
    nunez_write_file($ctx, $path, $content);
}

function nunez_db_flag_values(array $ctx)
{
    $map = array('db-host' => 'DB_HOST', 'db-port' => 'DB_PORT', 'db-name' => 'DB_DATABASE', 'db-user' => 'DB_USERNAME');
    $set = array();
    foreach ($map as $flag => $key) {
        if ($ctx['values'][$flag] !== null) {
            $set[$key] = $ctx['values'][$flag];
        }
    }

    return $set;
}

function nunez_env_or(array $env, $key, $default)
{
    return isset($env[$key]) && $env[$key] !== '' ? $env[$key] : $default;
}

// ---------------------------------------------------------------------------
// Database
// ---------------------------------------------------------------------------

/** @return true|string true when connected, otherwise the error message */
function nunez_db_connect(array $env)
{
    if (! extension_loaded('pdo_mysql')) {
        return 'pdo_mysql extension not loaded';
    }
    $host = nunez_env_or($env, 'DB_HOST', '127.0.0.1');
    $port = nunez_env_or($env, 'DB_PORT', '3306');
    $name = nunez_env_or($env, 'DB_DATABASE', 'nunezorganizer');
    $socket = nunez_env_or($env, 'DB_SOCKET', '');
    $dsn = $socket !== '' ? "mysql:unix_socket={$socket};dbname={$name}" : "mysql:host={$host};port={$port};dbname={$name}";
    try {
        new PDO($dsn, nunez_env_or($env, 'DB_USERNAME', ''), isset($env['DB_PASSWORD']) ? $env['DB_PASSWORD'] : '',
            array(PDO::ATTR_TIMEOUT => 3, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));

        return true;
    } catch (PDOException $e) {
        return $e->getMessage();
    }
}

function nunez_wait_for_db(array $ctx, $seconds)
{
    if ($ctx['dry_run'] || $ctx['skip_db_check']) {
        nunez_ok('database check skipped');

        return;
    }
    $env = nunez_read_env('.env');
    $deadline = time() + $seconds;
    $error = '';
    do {
        $result = nunez_db_connect($env);
        if ($result === true) {
            nunez_ok('connected to ' . nunez_env_or($env, 'DB_DATABASE', '?') . ' on ' . nunez_env_or($env, 'DB_HOST', '?'));

            return;
        }
        $error = $result;
        if (strpos($error, '[1049]') !== false || strpos($error, '1045') !== false) {
            break; // unknown database / access denied: waiting won't help
        }
        sleep(2);
    } while (time() < $deadline);

    $name = nunez_env_or($env, 'DB_DATABASE', 'nunezorganizer');
    $user = nunez_env_or($env, 'DB_USERNAME', 'nunezorganizer');
    throw new RuntimeException("Cannot connect to the database: {$error}\n"
        . "  Check DB_* in .env. To create the database and user, as the MariaDB root user run:\n"
        . "    CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n"
        . "    CREATE USER '{$user}'@'localhost' IDENTIFIED BY '<password>';\n"
        . "    GRANT ALL ON `{$name}`.* TO '{$user}'@'localhost';");
}

// ---------------------------------------------------------------------------
// Commands
// ---------------------------------------------------------------------------

function nunez_composer_cmd(array $ctx)
{
    return $ctx['composer'];
}

function nunez_artisan(array $ctx, $args)
{
    nunez_run($ctx, nunez_q($ctx['php']) . ' artisan ' . $args);
}

function nunez_run(array $ctx, $cmd)
{
    echo "  \$ {$cmd}\n";
    if ($ctx['dry_run']) {
        return;
    }
    passthru($cmd, $code);
    if ($code !== 0) {
        throw new RuntimeException("command failed (exit {$code}): {$cmd}");
    }
}

/** Runs a command quietly; returns its output, or null if it failed or doesn't exist. */
function nunez_capture($cmd)
{
    $out = array();
    $code = 1;
    @exec($cmd . ' 2>' . (DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null'), $out, $code);

    return $code === 0 ? implode("\n", $out) : null;
}

function nunez_q($arg)
{
    return preg_match('/^[A-Za-z0-9_\/.:\\\\-]+$/', $arg) ? $arg : escapeshellarg($arg);
}

function nunez_write_file(array $ctx, $path, $content, $mode = null)
{
    if ($ctx['dry_run']) {
        echo "  (dry run: would write {$path})\n";

        return;
    }
    if (file_put_contents($path, $content) === false) {
        throw new RuntimeException("cannot write {$path}");
    }
    if ($mode !== null && ! $ctx['windows']) {
        chmod($path, $mode);
    }
}

function nunez_whoami()
{
    if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
        $pw = posix_getpwuid(posix_geteuid());
        if ($pw && isset($pw['name'])) {
            return $pw['name'];
        }
    }

    return getenv('USER') ?: (getenv('USERNAME') ?: 'the current user');
}

// ---------------------------------------------------------------------------
// Prompts
// ---------------------------------------------------------------------------

function nunez_value(array $ctx, $flag, $question, $default)
{
    if ($ctx['values'][$flag] !== null) {
        return $ctx['values'][$flag];
    }
    if (! $ctx['interactive']) {
        return $default;
    }
    echo "  {$question}" . ($default !== null ? " [{$default}]" : '') . ': ';
    $answer = trim((string) fgets(STDIN));

    return $answer === '' ? $default : $answer;
}

function nunez_confirm(array $ctx, $question)
{
    if (! $ctx['interactive']) {
        return false;
    }
    echo "  {$question} [y/N]: ";

    return in_array(strtolower(trim((string) fgets(STDIN))), array('y', 'yes'), true);
}

function nunez_prompt_secret(array $ctx, $question)
{
    if (! $ctx['interactive']) {
        throw new RuntimeException('Database password required: set the NUNEZ_DB_PASSWORD environment variable');
    }
    echo "  {$question}: ";
    $hide = ! $ctx['windows'] && nunez_capture('stty -g') !== null;
    if ($hide) {
        shell_exec('stty -echo');
    }
    $answer = rtrim((string) fgets(STDIN), "\r\n");
    if ($hide) {
        shell_exec('stty echo');
        echo "\n";
    }

    return $answer;
}

// ---------------------------------------------------------------------------
// Output
// ---------------------------------------------------------------------------

function nunez_color($code, $text)
{
    static $on = null;
    if ($on === null) {
        $on = getenv('NO_COLOR') === false && function_exists('stream_isatty') && @stream_isatty(STDOUT);
    }

    return $on ? "\033[{$code}m{$text}\033[0m" : $text;
}

function nunez_title($text)
{
    echo "\n" . nunez_color('1', "== {$text}") . "\n";
}

function nunez_step($text)
{
    echo "\n" . nunez_color('1;34', "-> {$text}") . "\n";
}

function nunez_ok($text)
{
    echo '  ' . nunez_color('32', 'ok') . "   {$text}\n";
}

function nunez_warn($text)
{
    echo '  ' . nunez_color('33', 'warn') . " {$text}\n";
}

function nunez_bad($text)
{
    echo '  ' . nunez_color('31', 'FAIL') . " {$text}\n";
}

function nunez_err($text)
{
    fwrite(STDERR, "\n" . nunez_color('31', 'ERROR') . " {$text}\n");
}

function nunez_fail_list($heading, array $problems)
{
    echo "\n" . nunez_color('31', $heading) . "\n";
    foreach (array_unique($problems) as $p) {
        echo "  - {$p}\n";
    }
}
