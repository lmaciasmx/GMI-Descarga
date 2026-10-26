<?php

$type = isset($type) && is_string($type) ? $type : 'Error';
$message = isset($message) && is_string($message) ? $message : 'Error no especificado.';
$file = isset($file) && is_string($file) ? $file : 'desconocido';
$line = isset($line) ? (string) $line : '0';

echo $type . ': ' . strip_tags($message) . PHP_EOL;
echo 'Archivo: ' . $file . ':' . $line . PHP_EOL;
