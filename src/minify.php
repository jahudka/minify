<?php


if (!isSet($config)) {
	$config = [
		'sourceDir' => __DIR__,
		'outputDir' => __DIR__,
		'tempDir' => '/tmp',
		'debug' => false,
        'minify' => null,
	];
}

require_once __DIR__ . '/../vendor/autoload.php';

$app = new Minify\Application($config);

if (PHP_SAPI === 'cli') {
	$dir = rtrim(getcwd(), '/');

	for ($i = 1; $i < count($_SERVER['argv']); $i++) {
		$path = $_SERVER['argv'][$i];

		if ($path[0] !== '/') {
			$path = $dir . '/' . $path;

		}

		$app->compileSource($path);

	}
} else {
	$app->handleRequest();

}
