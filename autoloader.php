<?php
spl_autoload_register(function($name){
	$name = ltrim($name, '\\');
	$paths = explode('\\', $name);
	$filename = __DIR__ . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $paths) . '.php';
	if (file_exists($filename)):
		require_once $filename;
	endif;
});

?>