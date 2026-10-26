<?= $this->extend(config('Auth')->views['layout']) ?>

<?= $this->section('main') ?>

<div class="gm-login-page">

    <main class="gm-login-shell" aria-label="Acceso a GRUPOMAR Operaciones">

        <div class="gm-login-overlay" aria-hidden="true"></div>

        <section class="gm-brand-block" aria-label="GRUPOMAR Operaciones">

            <div class="gm-brand-top">
                <span class="gm-brand-line" aria-hidden="true"></span>

                <h1>GRUPOMAR</h1>

                <p>Operaciones y trazabilidad</p>
            </div>

            <div class="gm-brand-features" aria-label="Capacidades de la plataforma">

                <div class="gm-brand-feature">
                    <span class="gm-brand-feature__icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" focusable="false">
                            <path d="M3 12c3-4 6-6 10-6 3 0 6 2 8 6-2 4-5 6-8 6-4 0-7-2-10-6Z"></path>
                            <path d="M3 12 1 9m2 3-2 3"></path>
                            <path d="M16.5 11h.01"></path>
                        </svg>
                    </span>

                    <span>
                        PESCA<br>
                        RESPONSABLE
                    </span>
                </div>

                <div class="gm-brand-feature">
                    <span class="gm-brand-feature__icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" focusable="false">
                            <path d="M12 2v20M4 6l16 12M20 6 4 18"></path>
                            <path d="m7 4 5 3 5-3M7 20l5-3 5 3"></path>
                        </svg>
                    </span>

                    <span>
                        CADENA<br>
                        DE FRÍO
                    </span>
                </div>

                <div class="gm-brand-feature">
                    <span class="gm-brand-feature__icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" focusable="false">
                            <path d="M3 6h11v10H3z"></path>
                            <path d="M14 10h4l3 3v3h-7z"></path>
                            <circle cx="7" cy="18" r="2"></circle>
                            <circle cx="17" cy="18" r="2"></circle>
                        </svg>
                    </span>

                    <span>
                        LOGÍSTICA<br>
                        EFICIENTE
                    </span>
                </div>

            </div>

            <div class="gm-brand-footer">
                <span class="gm-brand-footer__line" aria-hidden="true"></span>
                <span>DEL MAR AL MUNDO</span>
            </div>

        </section>

        <section class="gm-form-block">

            <div class="gm-form-card">

                <header class="gm-form-header">
                    <h2>Acceso al sistema</h2>
                    <h3>Bienvenido</h3>
                    <p>Ingresa tus credenciales para continuar.</p>
                </header>

                <?php if (session('error') !== null): ?>
                    <div class="alert alert-danger gm-login-alert" role="alert">
                        <?= esc(session('error')) ?>
                    </div>
                <?php endif ?>

                <?php if (session('errors') !== null): ?>
                    <div class="alert alert-danger gm-login-alert" role="alert">
                        <?php foreach (session('errors') as $error): ?>
                            <div><?= esc($error) ?></div>
                        <?php endforeach ?>
                    </div>
                <?php endif ?>

                <?php if (session('message') !== null): ?>
                    <div class="alert alert-success gm-login-alert" role="status">
                        <?= esc(session('message')) ?>
                    </div>
                <?php endif ?>

                <form action="<?= site_url('login') ?>" method="post" class="gm-login-form">

                    <?= csrf_field() ?>

                    <div class="gm-field">
                        <label for="email" class="form-label gm-field__label">
                            Correo o usuario
                        </label>

                        <div class="gm-input-wrap">
                            <span class="gm-input-wrap__icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24" focusable="false">
                                    <rect x="3" y="5" width="18" height="14" rx="2"></rect>
                                    <path d="m4 7 8 6 8-6"></path>
                                </svg>
                            </span>

                            <input
                                type="text"
                                class="form-control gm-input"
                                id="email"
                                name="email"
                                value="<?= esc(old('email')) ?>"
                                placeholder="nombre@grupomar.com"
                                autocomplete="username"
                                autofocus
                                required
                            >
                        </div>
                    </div>

                    <div class="gm-field">
                        <label for="password" class="form-label gm-field__label">
                            Contraseña
                        </label>

                        <div class="gm-input-wrap gm-password-wrap">
                            <span class="gm-input-wrap__icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24" focusable="false">
                                    <rect x="5" y="10" width="14" height="10" rx="2"></rect>
                                    <path d="M8 10V7a4 4 0 0 1 8 0v3"></path>
                                </svg>
                            </span>

                            <input
                                type="password"
                                class="form-control gm-input gm-input--password"
                                id="password"
                                name="password"
                                placeholder="••••••••"
                                autocomplete="current-password"
                                required
                            >

                            <button
                                type="button"
                                class="show-password gm-password-toggle"
                                id="showPassword"
                                title="Mostrar contraseña"
                                aria-label="Mostrar contraseña"
                                aria-controls="password"
                                aria-pressed="false"
                            >
                                <svg class="gm-password-toggle__svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                    <path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"></path>
                                    <circle cx="12" cy="12" r="2.6"></circle>
                                </svg>

                                <i class="bi bi-eye visually-hidden" id="eyeIcon" aria-hidden="true"></i>
                            </button>
                        </div>
                    </div>

                    <button type="submit" class="btn gm-login-button">
                        <span>Iniciar sesión</span>

                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                            <path d="M5 12h13"></path>
                            <path d="m14 7 5 5-5 5"></path>
                        </svg>
                    </button>

                    <?php if (config('Auth')->allowMagicLinkLogins): ?>
                        <div class="gm-magic-link">
                            <span>¿Problemas para ingresar?</span>
                            <a href="<?= site_url('login/magic-link') ?>">
                                Acceder mediante enlace
                            </a>
                        </div>
                    <?php endif ?>

                </form>

                <div class="gm-login-footer">Plataforma interna</div>

            </div>

        </section>

    </main>

</div>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="/assets/js/login.js"></script>
<?= $this->endSection() ?>
