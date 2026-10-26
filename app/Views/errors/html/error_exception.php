<?php

$title = isset($title) && is_string($title) ? $title : 'Error de aplicación';
$message = isset($message) && is_string($message) ? $message : 'Error no especificado.';
$file = isset($file) && is_string($file) ? $file : 'desconocido';
$line = isset($line) ? (string) $line : '0';
$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $escape($title) ?></title>
</head>
<body>
    <main>
        <h1><?= $escape($title) ?></h1>
        <p><?= $escape(strip_tags($message)) ?></p>
        <p><strong>Archivo:</strong> <?= $escape($file) ?>:<?= $escape($line) ?></p>
    </main>
</body>
</html>
