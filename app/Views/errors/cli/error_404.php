<?php

$message = isset($message) && is_string($message) && $message !== ''
    ? $message
    : 'El recurso solicitado no fue encontrado.';

echo 'ERROR 404: ' . strip_tags($message) . PHP_EOL;
