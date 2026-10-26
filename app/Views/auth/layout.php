<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title><?= esc($title ?? 'Acceso | Grupo Mar') ?></title>

    <link rel="stylesheet"
          href="<?= base_url('assets/vendor/bootstrap/css/bootstrap.min.css') ?>">

    <link rel="stylesheet"
          href="<?= base_url('assets/css/login.css') ?>">
</head>

<body>

<?= $this->renderSection('main') ?>

<script src="<?= base_url('assets/vendor/bootstrap/js/bootstrap.bundle.min.js') ?>"></script>

<?= $this->renderSection('scripts') ?>

</body>
</html>