<?php
// Checks a release zip's signature the way the plugins do (WaaSUpdater\Updater::signature_valid).
//
//   php scripts/release/verify.php <slug> <version> <zip> <signature> [<public key>...]
//
// Without public keys on the command line it uses Updater::PUBLIC_KEYS, the keys the plugins
// trust, so a release signed with any other key fails here, before it is uploaded.

define('ABSPATH', __DIR__ . '/');
require dirname(__DIR__, 2) . '/packages/waas-updater/src/Updater.php';

use WaaSUpdater\Updater;

if ($argc < 5) {
    fwrite(STDERR, "usage: php verify.php <slug> <version> <zip> <signature> [<public key>...]\n");
    exit(2);
}
[, $slug, $version, $zip, $signature] = $argv;
$keys = array_slice($argv, 5);
if (!$keys && getenv('WAAS_RELEASE_TEST_PUBLIC_KEYS')) {
    // Only scripts/release/test.sh sets this; the workflows never do.
    $keys = preg_split('/\s+/', trim(getenv('WAAS_RELEASE_TEST_PUBLIC_KEYS')));
}
if (!$keys) {
    $keys = Updater::PUBLIC_KEYS;
    if (!$keys) {
        fwrite(STDERR, "Updater::PUBLIC_KEYS is empty: add the release public key to packages/waas-updater/src/Updater.php first.\n");
        exit(1);
    }
}
$sha256 = hash_file('sha256', $zip);
if (!Updater::signature_valid($slug, $version, $sha256, $signature, $keys)) {
    fwrite(STDERR, "The signature of $slug $version ($sha256) does not verify against " . count($keys) . " key(s).\n");
    exit(1);
}
echo "$slug $version $sha256: signature verified\n";
