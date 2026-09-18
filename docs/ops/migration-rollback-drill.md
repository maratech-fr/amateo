# Drill de retour arrière de migration (INF-05)

> Né de l'audit `AUDIT-2026-09-18-claude-fable-5-1.md` (finding INF-05) : 148 migrations portent
> toutes un `down()`, mais aucune n'avait jamais été rejouée en rollback hors développement — le
> seul chemin de secours réellement exercé était la restauration du dump pré-migration
> ([`backup-restore.md`](backup-restore.md)). Ce drill est le pendant du restore drill pour l'AUTRE
> moitié du problème : un `down()` FAUX ou oublié ne se découvrait qu'en catastrophe, le jour d'un
> vrai rollback de prod.

## Ce que fait le script

`backend/scripts/migration-rollback-drill.sh` répète, sur une base **jetable** créée puis détruite
(`amateo_migration_drill`, jamais `amateo_local` ni le bac à sable Behat), le cycle :

1. `doctrine:migrations:migrate` jusqu'à la cible (défaut : `latest`) → `pg_dump --schema-only`
   (dump A) ;
2. `migrate prev` (rollback d'un cran) ;
3. `migrate` re-jusqu'à la cible → `pg_dump --schema-only` (dump B) ;
4. `diff A B` = le **verdict** : identique ⇒ rollback propre, différent ⇒ le `down()` de la
   migration ciblée est incomplet.

Un `trap EXIT INT TERM` détruit la base jetable quoi qu'il arrive. Une migration dont le `down()`
lève `IrreversibleMigration` est un **choix assumé**, pas un échec : le script le détecte et sort
en succès (« drill non applicable »).

## Fail-closed — pourquoi il ne peut pas toucher une base partagée

Chaque commande console porte sa cible **explicite** (`DATABASE_URL`/`DATABASE_ADMIN_URL` pointant
la base jetable) — le script ne dépend jamais de `.env.local`. L'URL admin de référence vient de
`backend/.env` (jamais `.env.local`, qui en mode play vise `amateo_local`) et le script **refuse**
toute URL contenant « local », côté source comme côté cible dérivée. Exempté par nom de
`SandboxGuardCoverageTest` (qui n'autorise que `amateo_dev`/`*_test`, incompatible avec une base
jetable créée à la volée) — raison documentée dans le test lui-même.

## Pourquoi hors CI, hors `with-sandbox.sh`

- **Hors CI** : le script `DROP`/`CREATE` une base et pilote des migrations up/down — une
  vérification **manuelle**, pré-merge, jouée par un humain sur la stack de dev, pas un gate
  automatisé (la CI garde son propre `migrate` sur base neuve, qui ne rejoue jamais un `down()`).
- **Hors `with-sandbox.sh`** : ce wrapper bascule le mode play (`.env.local` + redémarrage de
  workers) pour viser le bac à sable Behat partagé — inutile et indésirable ici, puisque le drill
  ne touche déjà ni `amateo_local` ni le bac à sable.

## Usage

```bash
backend/scripts/migration-rollback-drill.sh                       # cible = latest
backend/scripts/migration-rollback-drill.sh --version 20260101120000
```

Sortie : `0` = rollback propre OU migration déclarée irréversible (cas légitime) ; `1` = schéma
divergent après up→down→up, ou toute autre erreur.

## Quand le jouer

Pas de rythme automatisé (l'axe est un **candidat**, pas encore un rituel arbitré). À dérouler :

- avant de merger une PR qui ajoute ou modifie une migration à `down()` non trivial ;
- une fois par édition d'audit `/audit`, en échantillon sur les migrations les plus à risque
  (`DROP TABLE`/`DROP COLUMN` en `up`) — recommandation de l'audit du 2026-09-18, pas encore
  une politique tranchée.

Voir aussi : [`backup-restore.md`](backup-restore.md) (le chemin de secours réel en prod —
restaurer le dump pré-migration, jamais un `down()` en production) · [`deploy.md`](deploy.md) §
« Règle d'écriture des migrations ».
