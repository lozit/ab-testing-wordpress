---
description: Run PHPUnit test suite
---

Lance la suite de tests PHPUnit.

Si `composer.json` expose un script `test` : `composer run test`.
Sinon : `./vendor/bin/phpunit`.

À l'issue :
- Si tests verts → résume brièvement la couverture des changements récents.
- Si tests rouges → diagnose la première erreur, propose un fix, ne mets PAS à jour les snapshots/expectations sans validation explicite (ça masquerait le bug).

Ne jamais marquer une tâche "complete" si la suite n'est pas verte.
