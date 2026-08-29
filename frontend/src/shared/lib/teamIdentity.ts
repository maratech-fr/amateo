/**
 * Les deux axes d'IDENTITÉ FFBB d'une équipe — son GENRE (`Gender`) et son NIVEAU
 * de compétition (`TeamLevel`) — vivent dans `shared/`, sous les features qui les
 * observent (wizard ET matches). Descendus de `features/wizard/` le 2026-08-29
 * (P4-148, résorption de la dérive `matches/Team.level`/`.gender`) : la copie de
 * `Team` du module matchs les lisait déjà en `string | null`, ce qui échappait au
 * filet d'enums ; les remonter en une maison unique partagée évite l'arête
 * feature→feature (matches n'importe rien de wizard) que la règle ESLint
 * `no-restricted-imports` proscrit — « ce qui est partagé descend dans shared/ »,
 * même geste que `ScheduleStatus` sous P4-123.
 *
 * Ils tiennent dans UN fichier parce qu'ils décrivent la même chose : la
 * classification FFBB d'une équipe (genre + niveau), un seul vocabulaire.
 *
 * ⚠ Les deux unions sont les JUMELLES des enums PHP `App\Enum\Gender` et
 * `App\Enum\TeamLevel` — gardées par `TsUnionsMatchPhpEnumsTest` (CrossStack),
 * dont la carte `MIRRORED` pointe ce fichier : un cas ajouté côté serveur et
 * oublié ici rendrait un test rouge. `TeamLevel` relève de l'axe « périmètre
 * engagé » (§7.1).
 */
export type Gender = "M" | "F" | "MIXTE";

/** FFBB competition level (backend App\Enum\TeamLevel). LOISIR_* = non-competitive. */
export type TeamLevel =
  | "ELITE"
  | "NATIONAL"
  | "REGIONAL"
  | "PRE_REGION"
  | "DEPARTEMENTAL"
  | "HONNEUR"
  | "PROMOTION"
  | "LOISIR_ADULTE"
  | "LOISIR_JEUNE";
