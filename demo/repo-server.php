<?php

/*
 * A tiny private Composer repository for the plugin demo:
 *
 *   php -S localhost:8765 demo/repo-server.php
 *
 * It serves one package, acme/invoice-kit, and only to username "token" with
 * the demo password below. Anything else gets a 401, like a revoked token.
 */

const USER = 'token';
const PASSWORD = 'DEMOfreshINVOICEtoken0000000b7e3';

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (($_SERVER['PHP_AUTH_USER'] ?? null) !== USER || ($_SERVER['PHP_AUTH_PW'] ?? null) !== PASSWORD) {
    header('WWW-Authenticate: Basic realm="acme"');
    http_response_code(401);
    exit;
}

$base = 'http://'.$_SERVER['HTTP_HOST'];
$versions = ['2.3.1', '2.4.0'];

if ($path === '/packages.json') {
    header('Content-Type: application/json');
    $packages = [];
    foreach ($versions as $v) {
        $packages['acme/invoice-kit'][$v] = [
            'name' => 'acme/invoice-kit',
            'version' => $v,
            'description' => 'Invoices, credit notes and VAT for Acme apps',
            'type' => 'library',
            'autoload' => ['psr-4' => ['Acme\\InvoiceKit\\' => 'src/']],
            'dist' => ['type' => 'zip', 'url' => "$base/dists/invoice-kit-$v.zip"],
        ];
    }
    echo json_encode(['packages' => $packages]);
    exit;
}

if (preg_match('{^/dists/invoice-kit-([\d.]+)\.zip$}', $path, $m) && in_array($m[1], $versions, true)) {
    $file = tempnam(sys_get_temp_dir(), 'kit');
    $zip = new ZipArchive();
    $zip->open($file, ZipArchive::OVERWRITE);
    $zip->addFromString('composer.json', json_encode(['name' => 'acme/invoice-kit', 'autoload' => ['psr-4' => ['Acme\\InvoiceKit\\' => 'src/']]]));
    $zip->addFromString('src/Invoice.php', "<?php\n\nnamespace Acme\\InvoiceKit;\n\nfinal class Invoice\n{\n}\n");
    $zip->close();
    header('Content-Type: application/zip');
    readfile($file);
    unlink($file);
    exit;
}

http_response_code(404);
