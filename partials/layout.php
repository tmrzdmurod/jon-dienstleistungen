<?php
declare(strict_types=1);

/**
 * Gemeinsamer Seitenrahmen (Kopf, Navigation, Fuß) für alle Kundenseiten.
 */

function siteContact(): array {
    return [
        'name'      => 'Jon Dienstleistungen',
        'phone'     => '+4915758256067',
        'phoneText' => '+49 1575 8256067',
        'email'     => 'suhrob.ch@gmx.de',
        'address'   => '91054 Erlangen',
        'whatsapp'  => 'https://wa.me/4915758256067',
        'instagram' => 'https://www.instagram.com/jon_dienstleistungen',
        'facebook'  => 'https://www.facebook.com/jondienstleistungen',
        'telegram'  => 'https://t.me/I20I2007',
        'since'     => '01.06.2024',
    ];
}

function siteBrandMark(): string {
    return '<svg class="brand__mark" viewBox="0 0 44 44" aria-hidden="true" focusable="false">'
        . '<rect width="44" height="44" rx="10" fill="#0d1321"/>'
        . '<circle cx="33" cy="11" r="5" fill="#ffb61b"/>'
        . '<path d="M9 33 15 17h20l-6 16z" fill="#1a62e6"/>'
        . '<path d="M12.2 25h20M18.5 17l-5.2 16M26 17l-5.6 16" stroke="#fff" stroke-width="1.4" stroke-linecap="round" opacity=".85"/>'
        . '</svg>';
}

/**
 * @param array{active?:string,isLoggedIn?:bool,showAdmin?:bool} $opts
 */
function renderSiteHeader(array $opts = []): void {
    $active = (string)($opts['active'] ?? '');
    $isLoggedIn = (bool)($opts['isLoggedIn'] ?? false);
    $showAdmin = (bool)($opts['showAdmin'] ?? false);
    $contact = siteContact();
    $home = $active === 'home' ? '' : 'index.php';

    $links = [
        ['home',     'index.php',            'Start'],
        ['',         $home . '#leistungen',  'Leistungen'],
        ['',         $home . '#ablauf',      'Ablauf'],
        ['',         $home . '#projekte',    'Projekte'],
        ['',         $home . '#kunden',      'Kundenstimmen'],
        ['calendar', 'calendar.php',         'Kalender'],
        ['orders',   'orders.php',           'Buchungen'],
        ['contact',  'dobro.php',            'Kontakte'],
    ];

    $account = $isLoggedIn
        ? [['profile', 'profile.php', 'fa-user', 'Profil'], ['', 'logout.php?scope=user', 'fa-sign-out-alt', 'Ausloggen']]
        : [['login', 'login.php', 'fa-sign-in-alt', 'Einloggen'], ['signup', 'signup.php', 'fa-user-plus', 'Registrieren']];
    if ($showAdmin) {
        $account[] = ['', 'admin.php', 'fa-user-shield', 'Admin'];
    }
    ?>
    <a class="skip-link" href="#inhalt">Zum Inhalt springen</a>

    <div class="topbar">
        <div class="wrap topbar__inner">
            <a class="brand" href="index.php" aria-label="<?= htmlspecialchars($contact['name']) ?> – Startseite">
                <?= siteBrandMark() ?>
                <span class="brand__text">
                    <strong>JON</strong>
                    <span>Dienstleistungen</span>
                </span>
            </a>

            <ul class="topbar__facts">
                <li><i class="fas fa-map-marker-alt" aria-hidden="true"></i> <?= htmlspecialchars($contact['address']) ?></li>
                <li><i class="fas fa-envelope" aria-hidden="true"></i> <a href="mailto:<?= htmlspecialchars($contact['email']) ?>"><?= htmlspecialchars($contact['email']) ?></a></li>
            </ul>

            <a class="topbar__phone" href="tel:<?= htmlspecialchars($contact['phone']) ?>">
                <i class="fas fa-phone" aria-hidden="true"></i>
                <span>
                    <b><?= htmlspecialchars($contact['phoneText']) ?></b>
                    <small>Anruf oder WhatsApp</small>
                </span>
            </a>

            <div class="topbar__account">
                <?php foreach ($account as [$key, $href, $icon, $label]): ?>
                    <a href="<?= htmlspecialchars($href) ?>"<?= $key !== '' && $key === $active ? ' class="is-active"' : '' ?>>
                        <i class="fas <?= $icon ?>" aria-hidden="true"></i> <?= htmlspecialchars($label) ?>
                    </a>
                <?php endforeach; ?>
            </div>

            <button class="menu-toggle" type="button" aria-label="Menü öffnen" aria-expanded="false" aria-controls="mobileMenu">
                <i class="fas fa-bars" aria-hidden="true"></i><i class="fas fa-times" aria-hidden="true"></i>
            </button>
        </div>
    </div>

    <nav class="mainnav" aria-label="Hauptnavigation">
        <div class="wrap mainnav__inner">
            <ul class="mainnav__links">
                <?php foreach ($links as [$key, $href, $label]): ?>
                    <li><a href="<?= htmlspecialchars($href) ?>"<?= $key !== '' && $key === $active ? ' class="is-active" aria-current="page"' : '' ?>><?= htmlspecialchars($label) ?></a></li>
                <?php endforeach; ?>
            </ul>
            <a class="btn btn--primary btn--sm" href="calendar.php"><i class="fas fa-calendar-check" aria-hidden="true"></i> Termin buchen</a>
        </div>
    </nav>

    <div class="mobile-menu" id="mobileMenu">
        <div class="mobile-menu__panel">
            <?php foreach ($links as [$key, $href, $label]): ?>
                <a href="<?= htmlspecialchars($href) ?>"<?= $key !== '' && $key === $active ? ' class="is-active"' : '' ?>><?= htmlspecialchars($label) ?></a>
            <?php endforeach; ?>
            <hr>
            <?php foreach ($account as [$key, $href, $icon, $label]): ?>
                <a href="<?= htmlspecialchars($href) ?>"><i class="fas <?= $icon ?>" aria-hidden="true"></i> <?= htmlspecialchars($label) ?></a>
            <?php endforeach; ?>
            <a class="btn btn--primary" href="calendar.php"><i class="fas fa-calendar-check" aria-hidden="true"></i> Termin buchen</a>
            <a class="btn btn--whatsapp" href="<?= htmlspecialchars($contact['whatsapp']) ?>"><i class="fab fa-whatsapp" aria-hidden="true"></i> WhatsApp</a>
        </div>
    </div>
    <?php
}

/**
 * Blaues Kopfband der Unterseiten (Titel + Brotkrumen).
 */
function renderPageHero(string $title, string $subtitle = ''): void {
    ?>
    <section class="page-hero">
        <div class="wrap">
            <h1><?= htmlspecialchars($title) ?></h1>
            <?php if ($subtitle !== ''): ?>
                <p class="page-hero__sub"><?= htmlspecialchars($subtitle) ?></p>
            <?php endif; ?>
            <ol class="crumbs" aria-label="Brotkrumen">
                <li><a href="index.php">Startseite</a></li>
                <li aria-current="page"><?= htmlspecialchars($title) ?></li>
            </ol>
        </div>
    </section>
    <?php
}

function renderSiteFooter(): void {
    $contact = siteContact();
    ?>
    <footer class="site-footer">
        <div class="wrap site-footer__grid">
            <div class="site-footer__brand">
                <a class="brand brand--light" href="index.php">
                    <?= siteBrandMark() ?>
                    <span class="brand__text">
                        <strong>JON</strong>
                        <span>Dienstleistungen</span>
                    </span>
                </a>
                <p>Montage von Solaranlagen und Dienstleistungen rund ums Haus in Erlangen und Umgebung. Seit <?= htmlspecialchars($contact['since']) ?>.</p>
                <div class="site-footer__social">
                    <a href="<?= htmlspecialchars($contact['instagram']) ?>" aria-label="Instagram"><i class="fab fa-instagram" aria-hidden="true"></i></a>
                    <a href="<?= htmlspecialchars($contact['whatsapp']) ?>" aria-label="WhatsApp"><i class="fab fa-whatsapp" aria-hidden="true"></i></a>
                    <a href="<?= htmlspecialchars($contact['facebook']) ?>" aria-label="Facebook"><i class="fab fa-facebook-f" aria-hidden="true"></i></a>
                    <a href="<?= htmlspecialchars($contact['telegram']) ?>" aria-label="Telegram"><i class="fab fa-telegram-plane" aria-hidden="true"></i></a>
                </div>
            </div>

            <div>
                <h4>Kontakt</h4>
                <ul class="site-footer__list">
                    <li><i class="fas fa-phone" aria-hidden="true"></i> <a href="tel:<?= htmlspecialchars($contact['phone']) ?>"><?= htmlspecialchars($contact['phoneText']) ?></a></li>
                    <li><i class="fas fa-envelope" aria-hidden="true"></i> <a href="mailto:<?= htmlspecialchars($contact['email']) ?>"><?= htmlspecialchars($contact['email']) ?></a></li>
                    <li><i class="fas fa-map-marker-alt" aria-hidden="true"></i> <?= htmlspecialchars($contact['address']) ?></li>
                </ul>
            </div>

            <div>
                <h4>Seiten</h4>
                <ul class="site-footer__list site-footer__list--plain">
                    <li><a href="index.php#leistungen">Leistungen &amp; Preise</a></li>
                    <li><a href="index.php#projekte">Projekte</a></li>
                    <li><a href="calendar.php">Buchungskalender</a></li>
                    <li><a href="orders.php">Meine Buchungen</a></li>
                    <li><a href="dobro.php">Kontakte</a></li>
                </ul>
            </div>

            <div class="site-footer__cta">
                <h4>Preis nach Vereinbarung</h4>
                <p>Der Meister nennt den Preis nach Umfang der Arbeit – nach einer Besichtigung vor Ort.</p>
                <a class="btn btn--sun" href="calendar.php"><i class="fas fa-calendar-check" aria-hidden="true"></i> Termin buchen</a>
            </div>
        </div>
        <div class="site-footer__bottom">
            <div class="wrap">
                <span>© <?= date('Y') ?> <?= htmlspecialchars($contact['name']) ?></span>
                <span>Website erstellt von <a href="https://t.me/tmrzd_murod" target="_blank" rel="noopener">Temurzoda M.R</a> © 2025</span>
            </div>
        </div>
    </footer>

    <a class="wa-float" href="<?= htmlspecialchars($contact['whatsapp']) ?>" aria-label="WhatsApp schreiben">
        <i class="fab fa-whatsapp" aria-hidden="true"></i>
    </a>
    <script src="js/site.js?v=20261013a"></script>
    <?php
}
