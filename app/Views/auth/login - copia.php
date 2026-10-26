<?= $this->extend(config('Auth')->views['layout']) ?>

<?= $this->section('main') ?>

<div class="login-page">

    <div class="login-card">

        <div class="login-side">

            <div class="brand">

                <div class="brand-logo">
                    GM
                </div>

                <div>
                    <h1>Grupo Mar</h1>
                    <span>Operaciones</span>
                </div>

            </div>

            <div class="side-content">

                <span class="side-label">
                    CENTRO DE OPERACIÓN
                </span>

                <h2>
                    Control operativo<br>
                    y trazabilidad.
                </h2>

                <p>
                    Plataforma integral para la gestión de descarga,
                    pesaje, calidad, recepción e inventarios.
                </p>

            </div>

            <div class="side-footer">
                Grupo Mar · Manzanillo
            </div>

        </div>

        <div class="login-form-area">

            <div class="login-header">

                <span class="login-label">
                    ACCESO AL SISTEMA
                </span>

                <h2>Bienvenido</h2>

                <p>
                    Ingresa tus credenciales para continuar.
                </p>

            </div>

            <?php if (session('error') !== null): ?>

                <div class="alert alert-danger">
                    <?= esc(session('error')) ?>
                </div>

            <?php endif ?>

            <?php if (session('errors') !== null): ?>

                <div class="alert alert-danger">

                    <?php foreach (session('errors') as $error): ?>

                        <div>
                            <?= esc($error) ?>
                        </div>

                    <?php endforeach ?>

                </div>

            <?php endif ?>

            <?php if (session('message') !== null): ?>

                <div class="alert alert-success">
                    <?= esc(session('message')) ?>
                </div>

            <?php endif ?>

            <form action="<?= site_url('login') ?>" method="post">

                <?= csrf_field() ?>

                <div class="mb-4">

                    <label
                        for="email"
                        class="form-label"
                    >
                        Correo o usuario
                    </label>

                    <input
                        type="text"
                        class="form-control form-control-lg"
                        id="email"
                        name="email"
                        value="<?= esc(old('email')) ?>"
                        placeholder="Ingresa tu correo o usuario"
                        autocomplete="username"
                        autofocus
                        required
                    >

                </div>

                <div class="mb-4">

                    <label
                        for="password"
                        class="form-label"
                    >
                        Contraseña
                    </label>

                    <div class="password-container">

                        <input
                            type="password"
                            class="form-control form-control-lg"
                            id="password"
                            name="password"
                            placeholder="Ingresa tu contraseña"
                            autocomplete="current-password"
                            required
                        >

                        <button
                            type="button"
                            class="show-password"
                            id="showPassword"
                            title="Mostrar contraseña"
                            aria-label="Mostrar contraseña"
                            aria-controls="password"
                            aria-pressed="false"
                        >
                            <i
                                class="bi bi-eye"
                                id="eyeIcon"
                                aria-hidden="true"
                            ></i>
                        </button>

                    </div>

                </div>

                <button
                    type="submit"
                    class="btn btn-login"
                >
                    Iniciar sesión
                </button>

                <?php if (config('Auth')->allowMagicLinkLogins): ?>

                    <div class="magic-link">

                        <span>
                            ¿Problemas para ingresar?
                        </span>

                        <a href="<?= site_url('login/magic-link') ?>">
                            Acceder mediante enlace
                        </a>

                    </div>

                <?php endif ?>

            </form>

        </div>

    </div>

</div>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>

<script src="/assets/js/login.js"></script>

<?= $this->endSection() ?>