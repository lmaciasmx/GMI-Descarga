<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>
<div class="text-center mt-5">
    <h1>404</h1>
    <p>La pagina solicitada no existe.</p>
    <a href="<?= site_url('/') ?>" class="btn btn-primary">Volver al Dashboard</a>
</div>
<?= $this->endSection() ?>
