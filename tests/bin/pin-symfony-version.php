#!/usr/bin/env php
<?php

declare(strict_types=1);

$newVersion = $argv[1] ?? '';

if ($newVersion === '') {
    throw new LogicException('Provide a Symfony version in Composer requirement format (e.g. "^7.0")');
}

$composerPath    = __DIR__ . '/../../composer.json';
$composerContent = file_get_contents($composerPath);

if ($composerContent === false) {
    throw new LogicException('Could not read composer.json file');
}

$updatedComposer = preg_replace('/"symfony\/(.*)": ".*"/', '"symfony/$1": "' . $newVersion . '"', $composerContent);

if ($updatedComposer === null) {
    throw new LogicException('Failed to update composer.json content');
}

echo $updatedComposer . PHP_EOL;

if (file_put_contents($composerPath, $updatedComposer) === false) {
    throw new LogicException('Could not write to composer.json file');
}
