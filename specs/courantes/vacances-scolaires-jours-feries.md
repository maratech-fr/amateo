# Vacances scolaires & jours fériés — référentiels calendaires

Last verified @ 2026-08-23 (rotation de fraîcheur — re-vérifié contre le code, **tout juste** : table `school_holiday_period` + entité `SchoolHolidayPeriod` + contrainte unique `(zone, holiday_type, school_year)` (`SchoolHolidayPeriod.php:23`) ✓ · `Club.schoolZone` (`Club.php:66`) ✓ · « display-only, jamais consommé par le solveur » toujours vrai — les vacances n'entrent au moteur que via la période que le gestionnaire pose, et l'exclusion d'OFFRE du picker reste côté front (décision P2-40, réaffirmée le 2026-08-22) ✓ · les deux commandes seed/import existent ✓. Rien de faux ce passage) — *(historique des passes : `git log -p --follow specs/courantes/vacances-scolaires-jours-feries.md`)*

Feed d'affichage du cockpit (accueil temporel) : vacances scolaires de la zone du club + jours fériés applicables. **Display-only — jamais consommé par le solveur** : si un férié ou une vacance gêne un entraînement, le gestionnaire pose une période (`CalendarEntry` `closure`/`holiday`), il n'y a aucune règle implicite.

## Modèle

Deux tables **globales** (référentiel national partagé, pas de `club_id`, hors RLS) :

| Table | Entité | Clé naturelle (upsert) | Contenu |
|-------|--------|------------------------|---------|
| `school_holiday_period` | `SchoolHolidayPeriod` | `(zone, holiday_type, school_year)` | une période de vacances par zone et année scolaire |
| `public_holiday` | `PublicHoliday` | `(zone, holiday_date)` | un férié daté ; `zone='NATIONAL'` (métropole, tous clubs) ou code territoire pour les extras |

### Zones scolaires — 13 codes

`SchoolZoneResolver::ZONES` énumère les 13 codes : `A`, `B`, `C`, `CORSE` + 9 DOM/TOM (`GUADELOUPE`, `GUYANE`, `MARTINIQUE`, `MAYOTTE`, `NOUVELLE_CALEDONIE`, `POLYNESIE`, `REUNION`, `SAINT_PIERRE_MIQUELON`, `WALLIS_FUTUNA`). `FrenchSchoolCalendarMapper` ne réutilise **pas** cette constante en runtime — il a sa propre table `ZONE_LABEL_TO_CODE` (libellé de zone API → code) ; `FrenchSchoolCalendarMapperTest` garde la cohérence des deux listes (`assertContains(..., SchoolZoneResolver::ZONES)`). Ajouter une 14ᵉ zone impose donc de toucher **les deux** constantes.

La zone du club (`Club.schoolZone`) est **dérivée du code FFBB** au register + backfill : 3 lettres de ligue + 4 chiffres zéro-paddés = département (`GES0067060` → 67 → B ; `GUY0973021` → 973 → GUYANE ; Corse `2A`/`2B`/`20` → CORSE). Extraction **best-effort** (format FFBB non officiellement vérifié) : illisible → `null`, saisie manuelle (PATCH club), jamais écrasée si déjà renseignée. La migration `Version20260706120000` a élargi les colonnes de zone à la taxonomie 13 codes et **re-taggé les clubs corses `B → CORSE`** (down : `CORSE → B`).

## Alimentation (commandes console, idempotentes)

| Commande | Source | Fenêtre | Notes |
|----------|--------|---------|-------|
| `app:school-holidays:import` | API officielle Éducation nationale (ODS `data.education.gouv.fr/api/explore/v2.1`, dataset `fr-en-calendrier-scolaire`) | année scolaire courante + N+1 | pagination ODS ; filtre `population="-"` (vacances de trimestre) **or** `"Élèves"` (été côté élèves — l'été « Enseignants » est écarté) |
| `app:school-holidays:seed` | JSON versionné dans le repo | — | **fallback hors-ligne**, même upsert |
| `app:public-holidays:seed` | JSON versionné dans le repo (`data/public-holidays.fr-national.json`) | — | **fallback hors-ligne** national, même upsert — la parité avec le seed vacances, jadis annoncée « non faite », est faite |
| `app:public-holidays:import` | API etalab `calendrier.api.gouv.fr/jours-feries/{zone}.json` (metropole + 9 territoires) | année civile courante + N+1 | métropole → `NATIONAL` ; extras territoriaux = **diff territoire − métropole** tagués du code territoire ; territoire non publié par etalab → warn + skip (saisie manuelle en attendant) |

Règles de mapping vacances (`FrenchSchoolCalendarMapper`) :
- une période est une **vacance** ssi elle couvre **strictement plus de 3 jours ouvrés** (lun–ven) **et** son libellé ne commence pas par `Pont` — écarte ponts (l'Ascension = 4-5 jours calendaires mais 3 ouvrés), semaines courtes, marqueurs 1 jour ;
- `holidayType` = **slug ouvert** dérivé du libellé (`toussaint`, `noel`… mais aussi `carnaval`, `ete_austral`, `apres_1ere_periode` pour les DOM/TOM) — pas d'enum fermé, les calendriers territoriaux ont leurs propres régimes.

Les deux imports restent appelables manuellement et sont aussi planifiés par SA3-C le
1er janvier, avril, juillet et octobre (`Europe/Paris`) : vacances à 04:00, jours fériés
à 04:30. Le tick minute rattrape le dernier créneau manqué après un arrêt et déduplique
chaque créneau. **Les deux imports sont déclenchables à la main depuis la console superadmin**
(`POST /api/admin/jobs/{key}/run` — `AdminJobCatalog` les déclare `manualTriggerAllowed: true`,
et la route refuse tout job qui ne le porte pas).

## Lecture (API)

Deux routes custom `GET`, documentées dans l'OpenAPI via `CustomRoutesOpenApiFactory` :

| Route | Comportement |
|-------|--------------|
| `GET /api/school-holidays` | vacances de la **zone du club** dans la fenêtre `from`/`to` (défaut : saison active). Zone `null` → **court-circuit `200 {zone:null, items:[]}` avant toute validation de fenêtre** (le frontend affiche un CTA « renseigner la zone ») |
| `GET /api/public-holidays` | fériés `NATIONAL` **∪** extras du territoire du club, même fenêtre. Zone `null` → NATIONAL quand même (les fériés nationaux s'appliquent à tout club) ; pas de court-circuit. Flag `national` par item |

Validation de fenêtre (quand elle s'exécute — c.-à-d. toujours pour `public-holidays`, et pour `school-holidays` seulement si la zone est renseignée) : `from`/`to` fournis mais invalides → 400 (pas de fallback silencieux sur la saison) ; pas de fenêtre du tout (ni params ni saison active) → 400. Pas de club en contexte → 400.

## Hors périmètre assumé

- **Alsace-Moselle** (Vendredi Saint / 26 décembre) : départements 57/67/68 en zone B sans zone fériés dédiée.
- **Saint-Barthélemy / Saint-Martin** (977/978) : hors `SchoolZoneResolver::ZONES`.

## Rendu cockpit (frontend)

Les fériés sont affichés dans le cockpit (`usePublicHolidays`, fenêtre saison par défaut) : **pastille** rouge sur le jour exact du calendrier mensuel (`title` = « Férié — {label} ») et **rappel radar** sans CTA pour les fériés à ≤ 30 jours. Display-only, cohérent avec le principe ci-dessus : aucune action automatique, le pont reste une décision gestionnaire (`periodType=closure`).

## Gardes

`SchoolZoneResolverTest` · `FrenchSchoolCalendarMapperTest` · `PublicHolidayMapperTest` (unit) · `SchoolHolidaysApiTest` · `PublicHolidaysApiTest` · `ImportSchoolHolidaysCommandTest` · `ImportPublicHolidaysCommandTest` · `SeedSchoolHolidaysCommandTest` (integration).
