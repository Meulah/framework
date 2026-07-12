<?php

declare(strict_types=1);

$source = dirname(__DIR__) . '/skeleton';
$destination = $argv[1] ?? '';

if ($destination === '') {
    throw new RuntimeException('Usage: php tests/create-starter.php <destination>');
}

if (file_exists($destination)) {
    throw new RuntimeException("Destination already exists: {$destination}");
}

if (!mkdir($destination, 0775, true) && !is_dir($destination)) {
    throw new RuntimeException("Unable to create destination: {$destination}");
}

$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST,
);

foreach ($files as $file) {
    $relative = substr($file->getPathname(), strlen($source) + 1);
    $target = $destination . DIRECTORY_SEPARATOR . $relative;

    if ($file->isDir()) {
        if (!is_dir($target) && !mkdir($target, 0775, true) && !is_dir($target)) {
            throw new RuntimeException("Unable to create directory: {$target}");
        }

        continue;
    }

    if (!copy($file->getPathname(), $target)) {
        throw new RuntimeException("Unable to copy file: {$relative}");
    }
}

$composerFile = $destination . '/composer.json';
$composer = json_decode((string) file_get_contents($composerFile), true, 512, JSON_THROW_ON_ERROR);
$composer['repositories'] ??= [];

array_unshift($composer['repositories'], [
    'type' => 'path',
    'url' => realpath(dirname(__DIR__)),
    'options' => [
        'symlink' => false,
        'versions' => ['meulah/framework' => '0.1.0'],
    ],
]);

file_put_contents(
    $composerFile,
    json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL,
);

fwrite(STDOUT, "Created starter consumer: {$destination}" . PHP_EOL);
