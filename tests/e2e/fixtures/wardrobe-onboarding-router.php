<?php

declare(strict_types=1);

$database = 'sqlite:///'.dirname(__DIR__, 3).'/var/e2e-wardrobe-onboarding.db';
putenv('APP_ENV=test');
putenv('APP_DEBUG=1');
putenv('DATABASE_URL='.$database);
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'test';
$_ENV['APP_DEBUG'] = $_SERVER['APP_DEBUG'] = '1';
$_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = $database;

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = dirname(__DIR__, 3).'/public_html'.$path;

if ($path !== '/' && is_file($file)) {
    $mime = match (pathinfo($file, PATHINFO_EXTENSION)) {
        'js' => 'application/javascript',
        'json', 'webmanifest' => 'application/json',
        default => mime_content_type($file),
    };
    if (is_string($mime)) {
        header('Content-Type: '.$mime);
    }
    readfile($file);

    return true;
}

return require dirname(__DIR__, 3).'/public_html/index.php';
