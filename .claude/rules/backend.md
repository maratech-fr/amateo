---
paths:
  - "backend/**"
---

# Backend — conventions & pièges (chargé quand backend/ est touché)

- **PHPStan level 8** (extensions Doctrine + Symfony) · **CS-Fixer** `@Symfony` + `@PHP84Migration`
  + risky + Yoda + strict comparisons + `fully_qualified_strict_types` avec `import_symbols`.
- **Rector cible PHP 8.4** (aligné composer `>=8.4`) et son style **fait convention sur `src/` ET
  `tests/`** — notamment `!$x instanceof Foo` plutôt que `null === $x` pour un `?Foo` (P4-24).
  Aucune règle n'est `withSkip`. **Lancer `make -C backend rector` avant tout push backend**
  (dry-run : il montre, il ne fixe pas — le fix : `composer rector` dans le conteneur).
- **Stack Symfony sur la LTS 7.4** (bugs nov. 2028, sécurité nov. 2029) via
  `extra.symfony.require` — Flex filtre TOUS les splits Symfony, transitifs compris.
  ⚠ **Sa seule échappatoire est le LOCK** : un paquet déjà verrouillé en 8.0.x est exempté du
  filtre et n'en sort plus par mise à jour partielle (19 paquets ont vécu ainsi, P4-31).
  **Correctif d'une dérive : `composer update <les paquets>`** — surtout PAS un pin dans
  `composer.json`. Audit : `composer show "symfony/*"` (hors `*-contracts`, `flex`, `mercure`,
  `polyfill-*`) ; gardé par `SymfonyStackAlignmentTest` (lit l'INSTALLÉ, pas le lock).
- ⚠ **`make migration-diff` inopérant tant que `doctrine/dbal` reste en 4.4.4** (constaté
  2026-08-16) : le générateur de diff exige `doctrine/dbal` ≥ 4.5. Écrire la migration à la main
  en attendant — même correctif que ci-dessus, `composer update doctrine/dbal` (jamais un pin).
- **PHPUnit 11** via `vendor/bin/phpunit` — même binaire en CI, `Makefile` et `composer test`.
  Les conteneurs sont en `APP_ENV=dev` par défaut : les tests exigent `-e APP_ENV=test` explicite.
- Piège tests : browser-kit rejoue le cookie JWT d'une requête à l'autre →
  `App\Tests\StartsFreshBrowserSession` là où l'identité change.
- `JWT_COOKIE_SECURE` : `backend/.env` entre dans l'image de prod — le `false` de dev vit dans
  `.env.dev`/`.env.test`, `.env.prod` + `services.yaml` disent `true`
  (gardé par `JwtCookieSecureDefaultTest`).
- **Aucun identifiant interne** (`Pn-x`, `SEC-n`, `ENG-n`, `ADR-n`, `AUD-*`, `DOC-n`, `ALIGN-n`,
  `D-n`, `SAn`…) dans un texte LU par un humain — OpenAPI/`/api/docs` (docblocks de propriété
  SÉRIALISÉS compris), catalogue admin, descriptions/help CLI, messages d'erreur, emails,
  exports. Les COMMENTAIRES de code (`//`, blocs, docblocks NON sérialisés), oui. La substance
  reste, la référence part. Gardé par `PublicTextIsFreeOfInternalIdentifiersTest`.
- 🔴 **Jamais `new ValidationException('chaîne')`** — le 422 serait MUET (liste de violations VIDE, « An error occurred » à l'écran, le message meurt). L'idiome unique : `$this->refuse('…')` (`AbstractStateProcessor`), gardé par `Unit/ValidationExceptionCarriesViolationsTest` qui interdit le constructeur partout ailleurs dans `src/`. Détail et piège d'assertion (`\u0027`) : `backend/docs/error-copy.md` §rail 422.
