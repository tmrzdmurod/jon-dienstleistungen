<?php
declare(strict_types=1);

// Vorlage: als config.local.php kopieren und ausfüllen. config.local.php gehört nicht ins Git.

const DB_HOST = '127.0.0.1';
const DB_PORT = 3306;
const DB_USER = 'root';
const DB_PASS = '';
const DB_NAME = 'solar';

const PASSWORD_ENC_KEY = '';

// JWT-Signatur (HS256), mindestens 32 Zeichen. Erzeugen: php -r "echo bin2hex(random_bytes(32));"
// Ein neuer Wert meldet alle Benutzer ab. Leer = Anmeldung deaktiviert.
const JWT_SECRET = '';

// Admin-Zugang. Hash erzeugen: php -r "echo password_hash('PASSWORT', PASSWORD_DEFAULT);"
const ADMIN_USERNAME = '';
const ADMIN_PASSWORD_HASH = '';

// Adresse der Website, für Links in Benachrichtigungen (ohne / am Ende).
const SITE_URL = 'https://www.example.de';

// Wer über neue Buchungen informiert wird (E-Mail des Meisters/Inhabers).
const NOTIFY_EMAIL = '';

// E-Mail-Versand per SMTP. Beispiel GMX: mail.gmx.net, 587, tls, Login = E-Mail-Adresse.
// Lokal zum Testen: Mailpit (OSPanel) mit 127.0.0.1, 1025, Verschlüsselung 'none' und leerem Login.
const MAIL_HOST = '';
const MAIL_PORT = 587;
const MAIL_ENCRYPTION = 'tls';   // 'tls' (STARTTLS), 'ssl' (Port 465) oder 'none' (nur lokal)
const MAIL_USER = '';
const MAIL_PASS = '';
const MAIL_FROM = '';            // Absender, meist gleich MAIL_USER
const MAIL_FROM_NAME = 'Jon Dienstleistungen';

// WhatsApp an den Inhaber: '' (aus), 'callmebot', 'cloud' oder 'log' (nur protokollieren).
const WHATSAPP_PROVIDER = '';
// CallMeBot (kostenlos, nur an die eigene Nummer): Schlüssel unter https://www.callmebot.com holen.
const CALLMEBOT_PHONE = '';      // z. B. +4915700000000
const CALLMEBOT_APIKEY = '';
// Offizielle WhatsApp Cloud API (Meta): Token, Telefonnummer-ID, Empfänger und eine
// genehmigte Vorlage mit genau einem Textparameter {{1}}.
const WA_CLOUD_TOKEN = '';
const WA_CLOUD_PHONE_ID = '';
const WA_CLOUD_TO = '';
const WA_CLOUD_TEMPLATE = '';
const WA_CLOUD_LANG = 'de';
