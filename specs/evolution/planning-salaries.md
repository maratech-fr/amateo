# Planning des salariés (P4-613)

**Statut : besoin validé, décisions prises, EN ATTENTE du lot conception** ([`lot-conception.md`](lot-conception.md), P1-6) : le fondateur veut une **Personne avec rôles** (coach, salarié) avant de bâtir ce module. Plan non commencé ; `morpheus` n'est pas lancé. Citations `fichier:ligne` du cadrage `oracle` du 2026-11-19 : à relire à la source au plan.

## Le besoin

Le gestionnaire pilote les salariés du club et veut piloter leur planning dans Amateo, au lieu de son tableur. C'est un **planning par salarié, plus simple que celui des équipes**, pour **visualiser où est chaque salarié dans la semaine**. Purement informatif : il n'interfère avec rien (ni moteur, ni génération, ni radar). Des salariés qui ne sont pas coachs existent et doivent être matérialisés.

Exemple : lundi 9h-12h, réunion pour tous les salariés ; 14h-16h, planification des matchs pour un salarié ; 14h30-16h, mairie pour un autre ; 14h-18h, un troisième est en cours. S'y ajoutent, rapatriés, les créneaux d'entraînement des coachs salariés.

Vision produit : Amateo centralise la vie du club et remplace ce que le gestionnaire fait sur Excel. Module à part.

## Décisions du fondateur (2026-11-19)

1. **Module disponible seulement quand le socle est validé.**
2. **Semaine type seulement** : un jour, une heure de début, une durée, un nom, attribué à un salarié ou à « tous ». Pas de date précise (une mairie ponctuelle est hors périmètre).
3. **Séances rapatriées** : lues depuis le socle en vigueur, en lecture, sans copie. Pas les plans de période ni de vacances.
4. **Les coachs salariés apparaissent d'office** (case « salarié » du coach : `Coach.php:56-57`) ; un salarié non coach a sa propre fiche. La case « salarié » et la règle du solveur « un salarié présent chaque jour ouvré » (`engine/app/solver/constraints/wellness/salarie.py:39-75`) ne changent pas.
5. **Export PDF** de la semaine type des salariés. Un crédit Découverte, comme les autres exports (`CreditBudgetSubscriber.php:46-55`) : à confirmer au plan.
6. **Droits** : le gestionnaire construit le planning, les Membres (lecture seule) le consultent.
7. **Modèle** : une Personne qui porte des rôles ; « salarié » est un rôle avec ses propres caractéristiques (voir lot conception). Champs d'une fiche proposés : prénom, nom, poste facultatif, sans e-mail ni téléphone (à confirmer).

## Ce que le code fait aujourd'hui (cadrage `oracle`)

- Le coach est la personne : prénom, nom, e-mail, téléphone, genre, rattaché à la saison (`Coach.php:35-73`), recopié à « Préparer la saison suivante » (`SeasonTransitionService.php:286`). API : `CoachResource.php:24-31`. Saisie : Wizard, étape « Coachs » (`CoachesStep.tsx:172,450`).
- Un salarié non coach n'existe pas ; contournement : un faux coach, qui entrerait dans la règle du solveur et l'avertissement d'avant génération (`PreSolvePreventionWarnings.php:412-453`).
- L'indisponibilité d'un coach est une contrainte `COACH_AVAILABILITY` stricte, au nom généré (`ConstraintsStep.tsx:402-419`). La table a déjà une colonne `description` libre (`Entity/Constraint.php:46-47`, `ConstraintResource.php:48,101`) que le front ne connaît pas (`features/wizard/api.ts:756-765`).
- Vue « par coach » : séances seulement, aucune activité hors équipe (`planning/lib/grid.ts:195`).
- Modèle le plus proche, déjà rodé : les occupations de gymnase, information seulement (`Entity/VenueOccupancy.php`), copiées par plan de période et à la saison suivante (`SeasonTransitionService.php:237-246`).
- Exports : PDF (`PdfGenerator.php:141-158`) et Excel (`SpreadsheetGenerator.php:50`) ; aucun export par coach ni par salarié.
- RGPD : les entités club sont rangées automatiquement ; une entité nouvelle fait échouer `RgpdExportCompletenessTest` et `PurgeCompletenessTest` tant qu'elle n'est pas rangée (`docs/security/rgpd.md:51-74`).

## Points restant ouverts

- Où vit la fiche : dans la saison (recopiée) ou dans le club (hors saison) : réponse « saison » donnée par le fondateur à la question 1 du tableau, à reconfirmer avec la Personne (une personne traverse naturellement plusieurs saisons).
- Champs exacts de la fiche et du rôle salarié.
- Rapport avec les occupations de gymnase (une activité a-t-elle un lieu ?) et avec P4-614 (import du parc, qui n'a aujourd'hui aucune destination pour les cases école, réunions, formations).

## Signaux

- Axes §7.1 : aucun sur le moteur tant que les activités n'entrent jamais dans l'envoi au moteur ni dans l'empreinte de structure (patron `VenueOccupancy.php:13-15`).
- `/security-review` **obligatoire** (données de personnes : RGPD, isolation entre clubs). `smith` optionnel, voire obligatoire si la Personne touche génération et saison.
- Chemins d'abus : lecture par id d'une activité d'un autre club (404 identique) ; création d'une activité attribuée à un salarié d'un autre club (refus) ; export de la semaine d'un salarié d'un autre club (404, aucun crédit consommé) ; un Membre qui crée, modifie ou supprime (403) ; purge et export de portabilité complets.

## Lignes voisines

P4-614 (import du parc : dépend de ce module), P4-591 (occupations dans la grille du planning), P4-411 (textes de `CoachesStep` vers `texts.ts` : à embarquer si l'écran est touché), P4-532 (`GET /api/coaches` sert l'e-mail à un Membre : à trancher avant), P4-389 (audit des attributs backend sans écran), AUD-ALIGN-22 (cran « Obligatoire » de la règle des salariés : à fermer en vérifiant).
