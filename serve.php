<?php

$rawFile = $_GET['f'] ?? '';

if (!is_string($rawFile) || $rawFile === '' || !preg_match('/^[A-Za-z0-9._-]+\.pdf$/', $rawFile)) {
    http_response_code(404);
    exit('Not found');
}

$file = basename($rawFile);
$path = __DIR__ . '/' . $file;
$realBase = realpath(__DIR__);
$realPath = realpath($path);

if ($realBase === false
    || $realPath === false
    || strpos($realPath, $realBase . DIRECTORY_SEPARATOR) !== 0
    || !is_file($realPath)
    || pathinfo($realPath, PATHINFO_EXTENSION) !== 'pdf'
) {
    http_response_code(404);
    exit('Not found');
}

$size = filesize($realPath);
if ($size === false) {
    http_response_code(404);
    exit('Not found');
}

header('Content-Type: application/pdf');
header('Content-Length: ' . (int) $size);
readfile($realPath);
