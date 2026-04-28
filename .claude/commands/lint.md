---
description: Run PHPCS (WordPress Coding Standards) on the plugin
---

Lance le linter PHP avec le ruleset WordPress.

Si `composer.json` existe et expose un script `lint` : utilise `composer run lint`.
Sinon : `./vendor/bin/phpcs --standard=WordPress includes/ ab-testing-wordpress.php`.

Si des warnings sont remontés, propose `composer run lint:fix` (ou `phpcbf`) pour les auto-corrigeables, puis liste manuellement ceux à régler à la main.

Ne pas bypass les erreurs — elles indiquent souvent un risque de sécurité réel (escaping manquant, capability oubliée).
