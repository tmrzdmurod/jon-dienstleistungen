// Gemeinsames Verhalten aller Kundenseiten: Mobilmenü, Hinweise, Meldungen.
(function () {
    'use strict';

    const menu = document.getElementById('mobileMenu');
    const toggle = document.querySelector('.menu-toggle');

    function setMenu(open) {
        if (!menu || !toggle) {
            return;
        }
        menu.classList.toggle('active', open);
        toggle.classList.toggle('open', open);
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        document.body.classList.toggle('menu-open', open);
    }

    if (menu && toggle) {
        toggle.addEventListener('click', () => setMenu(!menu.classList.contains('active')));
        // Klick auf den abgedunkelten Hintergrund oder einen Link schließt das Menü.
        menu.addEventListener('click', (e) => {
            if (e.target === menu || e.target.closest('a')) {
                setMenu(false);
            }
        });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                setMenu(false);
            }
        });
        window.matchMedia('(min-width: 981px)').addEventListener('change', (e) => {
            if (e.matches) {
                setMenu(false);
            }
        });
    }

    // Registrierungs-Hinweis für Gäste: erscheint verzögert, lässt sich schließen.
    const registerNote = document.getElementById('registerNotification');
    if (registerNote) {
        setTimeout(() => registerNote.classList.add('is-visible'), 5000);
        setTimeout(() => registerNote.classList.remove('is-visible'), 15000);
    }
    document.querySelectorAll('.notification__close').forEach((btn) => {
        btn.addEventListener('click', () => btn.closest('.notification').classList.remove('is-visible'));
    });

    // Erfolgs-/Fehlermeldungen nach 10 s ausblenden.
    document.querySelectorAll('.message').forEach((msg) => {
        setTimeout(() => {
            msg.style.transition = 'opacity 0.4s ease';
            msg.style.opacity = '0';
            setTimeout(() => msg.remove(), 450);
        }, 10000);
    });
})();
