<?php

declare(strict_types=1);

$root = dirname(__DIR__).'/resources/views';
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
$unsafe = [];

foreach ($files as $file) {
    if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
        continue;
    }

    $source = file_get_contents($file->getPathname());
    if ($source !== false && str_contains($source, '{!!')) {
        $unsafe[] = substr($file->getPathname(), strlen(dirname(__DIR__)) + 1);
    }
}

if ($unsafe !== []) {
    fwrite(STDERR, "Raw Blade output needs security review:\n".implode("\n", $unsafe)."\n");
    exit(1);
}

echo "Blade escaping check passed.\n";
