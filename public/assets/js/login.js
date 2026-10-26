(() => {
    'use strict';

    const button = document.getElementById('showPassword');
    const password = document.getElementById('password');
    const icon = document.getElementById('eyeIcon');

    if (!button || !password || !icon) {
        return;
    }

    button.addEventListener('click', () => {
        const showingPassword = password.type === 'text';

        password.type = showingPassword ? 'password' : 'text';

        icon.classList.toggle('bi-eye', showingPassword);
        icon.classList.toggle('bi-eye-slash', !showingPassword);

        button.setAttribute(
            'title',
            showingPassword ? 'Mostrar contraseña' : 'Ocultar contraseña'
        );

        button.setAttribute(
            'aria-label',
            showingPassword ? 'Mostrar contraseña' : 'Ocultar contraseña'
        );

        button.setAttribute(
            'aria-pressed',
            showingPassword ? 'false' : 'true'
        );
    });
})();