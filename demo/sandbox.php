<?php

/*
 * Builds a throwaway HOME full of demo Composer projects and auth.json files:
 *
 *   php demo/sandbox.php /tmp/tokenbox-demo
 *
 * Then run tokenbox inside it with demo/bin/tokenbox (see demo/README.md).
 * All tokens here are fake and only mean something to demo/mock-http.php.
 */

$root = rtrim($argv[1] ?? sys_get_temp_dir().'/tokenbox-demo', '/');
$home = "$root/home";
if (is_dir($root)) {
    exec('rm -rf '.escapeshellarg($root));
}

$write = function (string $path, mixed $data, int $mode = 0o644) {
    @mkdir(dirname($path), 0o755, true);
    file_put_contents($path, is_string($data) ? $data : json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    chmod($path, $mode);
};

// Global auth.json. Left world-readable on purpose so tokenbox flags it.
$write("$home/.composer/auth.json", [
    'github-oauth' => [
        'github.com' => 'ghp_DEMOgithubEXPIRINGx0000000000007Qa2',
        'github.acme-corp.com' => 'ghp_DEMOenterpriseREVOKEDx0000000a1B2',
    ],
    'gitlab-token' => [
        'gitlab.com' => 'glpat-DEMOgitlabNOEXPIRYx00Hk9d',
        'gitlab.acme-corp.com' => 'glpat-DEMOselfhostedx0000Lm3p',
    ],
    'http-basic' => [
        'repo.packagist.com' => ['username' => 'token', 'password' => 'DEMOpackagistACME00000000009f2a'],
        'satis.acme-corp.com' => ['username' => 'deploy', 'password' => 'DEMOsatis0000000c4e1'],
    ],
], 0o644);
$write("$home/.composer/config.json", ['config' => [
    'github-domains' => ['github.com', 'github.acme-corp.com'],
    'gitlab-domains' => ['gitlab.com', 'gitlab.acme-corp.com'],
]]);

// A lock with $n packages downloaded from $host (GitHub dists come from api.github.com).
$lock = function (array $sources) {
    $packages = [];
    foreach ($sources as $host => [$vendor, $n]) {
        for ($i = 1; $i <= $n; $i++) {
            $name = "$vendor/package-$i";
            $url = $host === 'github.com'
                ? "https://api.github.com/repos/$name/zipball/".substr(sha1($name), 0, 12)
                : "https://$host/dists/$name/1.0.$i/".sha1($name).'.zip';
            $packages[] = ['name' => $name, 'version' => "1.0.$i", 'dist' => ['type' => 'zip', 'url' => $url]];
        }
    }

    return ['_readme' => ['demo lock'], 'content-hash' => md5(serialize($sources)), 'packages' => $packages, 'packages-dev' => []];
};

$projects = [
    'acme/storefront' => [
        'name' => 'acme/storefront',
        'repositories' => [
            ['type' => 'composer', 'url' => 'https://repo.packagist.com/acme/'],
            ['type' => 'vcs', 'url' => 'https://github.com/acme/checkout-sdk'],
            ['type' => 'vcs', 'url' => 'https://github.com/acme/feature-flags'],
        ],
        'lock' => ['repo.packagist.com' => ['acme', 9], 'github.com' => ['laravel', 64]],
    ],
    'acme/platform-api' => [
        'name' => 'acme/platform-api',
        'repositories' => [
            ['type' => 'vcs', 'url' => 'git@github.acme-corp.com:platform/auth-client.git'],
            ['type' => 'vcs', 'url' => 'https://github.acme-corp.com/platform/event-bus'],
            ['type' => 'composer', 'url' => 'https://repo.packagist.com/acme/'],
        ],
        'lock' => ['repo.packagist.com' => ['acme', 4], 'github.com' => ['symfony', 48]],
    ],
    'acme/billing' => [
        'name' => 'acme/billing',
        'repositories' => [
            ['type' => 'vcs', 'url' => 'https://gitlab.acme-corp.com/finance/ledger.git'],
            ['type' => 'vcs', 'url' => 'https://github.acme-corp.com/platform/auth-client'],
        ],
        'lock' => ['github.com' => ['symfony', 37]],
    ],
    'acme/admin-portal' => [
        'name' => 'acme/admin-portal',
        'repositories' => [
            ['type' => 'composer', 'url' => 'https://nova.laravel.com'],
            ['type' => 'composer', 'url' => 'https://repo.packagist.com/acme/'],
        ],
        'lock' => ['repo.packagist.com' => ['acme', 3], 'github.com' => ['laravel', 71]],
    ],
    'acme/intranet' => [
        'name' => 'acme/intranet',
        'repositories' => [['type' => 'composer', 'url' => 'https://satis.acme-corp.com']],
        'lock' => ['satis.acme-corp.com' => ['acme-legacy', 6], 'github.com' => ['symfony', 22]],
    ],
    'clients/harbour-hotels' => [
        'name' => 'harbour/hotels-wp',
        'repositories' => [
            ['type' => 'composer', 'url' => 'https://wpackagist.org'],
            ['type' => 'composer', 'url' => 'https://repo.packagist.com/harbour/'],
        ],
        'lock' => ['repo.packagist.com' => ['harbour', 5], 'wpackagist.org' => ['wpackagist-plugin', 14], 'github.com' => ['roots', 9]],
        'auth' => ['http-basic' => ['repo.packagist.com' => ['username' => 'token', 'password' => 'DEMOpackagistHARBOUR000000e71c']]],
    ],
    'clients/northwind' => [
        'name' => 'northwind/erp-bridge',
        'repositories' => [['type' => 'vcs', 'url' => 'https://gitlab.com/northwind/erp-sdk.git']],
        'lock' => ['gitlab.com' => ['northwind', 2], 'github.com' => ['guzzlehttp', 12]],
    ],
    'oss/laravel-playground' => ['name' => 'sam/laravel-playground', 'lock' => ['github.com' => ['laravel', 58]]],
    'oss/php-cs-config' => ['name' => 'sam/php-cs-config', 'lock' => ['github.com' => ['friendsofphp', 6]]],
    'oss/blog' => ['name' => 'sam/blog', 'lock' => ['github.com' => ['statamic', 33]]],
    'experiments/pest-plugin' => ['name' => 'sam/pest-plugin-snapshots', 'lock' => ['github.com' => ['pestphp', 19]]],
];

foreach ($projects as $dir => $p) {
    $path = "$home/Code/$dir";
    $composer = ['name' => $p['name'], 'type' => 'project', 'require' => ['php' => '^8.2']];
    if (!empty($p['repositories'])) {
        $composer['repositories'] = $p['repositories'];
    }
    $write("$path/composer.json", $composer);
    $write("$path/composer.lock", $lock($p['lock']));
    if (!empty($p['auth'])) {
        $write("$path/auth.json", $p['auth'], 0o600);
    }
}

// Something to ignore: vendor folders are never scanned.
$write("$home/Code/acme/storefront/vendor/acme/checkout-sdk/composer.json", ['name' => 'acme/checkout-sdk']);

// A second HOME for the Composer plugin demo: one project whose private
// repository (demo/repo-server.php) no longer accepts the saved password.
$pluginHome = "$root/plugin-home";
$write("$pluginHome/.composer/auth.json", [
    'http-basic' => ['localhost:8765' => ['username' => 'token', 'password' => 'DEMOrevokedINVOICEtoken00000041d9']],
], 0o600);
$write("$pluginHome/Code/invoicing/composer.json", [
    'name' => 'acme/invoicing',
    'type' => 'project',
    'repositories' => [['type' => 'composer', 'url' => 'http://localhost:8765']],
    'require' => ['acme/invoice-kit' => '^2.4'],
    'config' => ['secure-http' => false],
]);

echo "$root\n";
