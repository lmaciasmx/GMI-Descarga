<?php
$selectedScopeId = old('scope_id') ?? '';
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= esc($title ?? 'Centro de operación · Grupomar') ?></title>

    <link rel="stylesheet" href="<?= base_url('vendor/bootstrap/css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/css/app.css') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/fonts/manrope/manrope.css') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/css/scope-select.css') ?>">
</head>
<body class="gm-scope-page">
<main class="gm-scope-shell" aria-label="Selección de centro de operación">

    <div class="gm-scope-overlay" aria-hidden="true"></div>

    <section class="gm-scope-card">
        <header class="gm-scope-header">
            <div class="gm-scope-brand">
                <div class="gm-scope-brand__mark" aria-hidden="true">
                    <span>GM</span>
                </div>

                <div class="gm-scope-brand__copy">
                    <div class="gm-scope-brand__name">Grupomar</div>
                    <div class="gm-scope-brand__tag">Operaciones y trazabilidad</div>
                </div>
            </div>

            <div class="gm-scope-header__compact">
                <span class="gm-scope-header__line" aria-hidden="true"></span>
                <p>Selecciona tu contexto de trabajo</p>
            </div>
        </header>

        <?php if ($message = session()->getFlashdata('error')): ?>
            <div class="alert alert-danger gm-scope-alert" role="alert">
                <?= esc($message) ?>
            </div>
        <?php endif ?>

        <?php if ($message = session()->getFlashdata('warning')): ?>
            <div class="alert alert-warning gm-scope-alert" role="alert">
                <?= esc($message) ?>
            </div>
        <?php endif ?>

        <?php if ($scopes === []): ?>
            <div class="alert alert-warning gm-scope-empty" role="alert">
                <strong>Sin alcance asignado.</strong><br>
                Un administrador debe asociar tu usuario a una empresa, instalación y almacén antes de operar.
            </div>
        <?php else: ?>
            <form method="post" action="<?= site_url('scope/select') ?>" class="gm-scope-form">
                <?= csrf_field() ?>

                <label class="form-label gm-scope-label" for="scope_id">
                    Empresa · instalación · almacén
                </label>

                <div class="gm-scope-select-wrap">
                    <select class="form-select gm-scope-select" id="scope_id" name="scope_id" required autofocus>
                        <option value="">Selecciona...</option>
                        <?php foreach ($scopes as $scope): ?>
                            <?php
                            $optionValue = (int) $scope['id'];
                            $optionLabel = $scope['company_name'] . ' · ' . ($scope['site_name'] ?? 'Todas') . ' · ' . ($scope['warehouse_name'] ?? 'Todos los almacenes');
                            ?>
                            <option value="<?= $optionValue ?>" <?= (string) $selectedScopeId === (string) $optionValue ? 'selected' : '' ?>>
                                <?= esc($optionLabel) ?>
                            </option>
                        <?php endforeach ?>
                    </select>
                </div>

                <button class="gm-scope-submit" type="submit">
                    <span>Entrar al centro de operación</span>
                    <span class="gm-scope-submit__arrow" aria-hidden="true">→</span>
                </button>
            </form>
        <?php endif ?>

        <footer class="gm-scope-footer" aria-label="Mensaje institucional">
            <span class="gm-scope-footer__line" aria-hidden="true"></span>
            <span class="gm-scope-footer__icon" aria-hidden="true">✳</span>
            <span class="gm-scope-footer__line" aria-hidden="true"></span>
            <p>Frigorífico · almacenamiento · trazabilidad</p>
        </footer>
    </section>
</main>
</body>
</html>
