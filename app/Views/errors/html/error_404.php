<?php

$message = isset($message) && is_string($message) && $message !== ''
    ? $message
    : 'La página solicitada no existe.';
$safeMessage = htmlspecialchars(strip_tags($message), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Página no encontrada</title>
</head>
<body>
    <main>
        <h1>Página no encontrada</h1>
        <p><?= $safeMessage ?></p>
    </main>
</body>
</html>
