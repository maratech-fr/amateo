# Module matchs (FFBB) — état courant

> **Règle de forme** : ce fichier décrit **l'état courant, par écran** — jamais une section datée
> d'une PR. Le JOURNAL (qui a livré quoi, quand, sous quel id) vit dans
> [`etat-des-lieux.md`](etat-des-lieux.md) §1.5 (carte) et §3 (traces datées) ; les **décisions
> fermées** (tranchées contre une option évidente) vivent dans son §2 — ce fichier n'en reproduit
> que le résultat, jamais le débat. But de taille : décrire l'état courant sans historiser — si une
> phrase commence par une date ou un id de PR, elle appartient à l'état des lieux, pas ici.

Le module vit dans `frontend/src/features/matches/` (nav `MatchesLayout`, sous `/matchs` :
`index` = **route d'atterrissage conditionnelle** (`MatchesLanding` → Calendrier, ou renvoi sur
Conflits s'il y a des conflits à traiter — § Écran Calendrier), `conflits`, `importer`,
`configuration`, `contraintes` (§ Écran Contraintes — sections Ligue/Club/Équipes/Coachs toutes
éditables), `adversaires` (§ Écran Adversaires, onglet propre — plus un deep-link de
Configuration), `semaine-type`, `consulter` en redirection permanente vers l'index, `reconciliation` accessible
seulement depuis le canal API, `frontend/src/app/routes.tsx:141-186`) et dans les services backend `Match*`/`Fixture*`/
`Opponent*`/`Ffbb*` (`backend/src/Service/`, `backend/src/Entity/`). **Bouton d'aide (i)** (`HelpButton`,
primitive partagée, `frontend/docs/frontend-components.md`) à gauche de la barre d'onglets — un
seul, dont le contenu suit l'onglet actif (`MATCHES_TAB_HELP`, `features/matches/lib/screenHelp.tsx`).

## Sommaire

Ce fichier n'est plus qu'un INDEX — le détail est découpé par thème (plafond `DocSizeCeilingTest`,
déplacement mécanique, contenu non réédité). Chaque sous-fichier garde la numérotation de section
d'origine : un renvoi existant (« `module-matchs.md` §N ») se résout en cherchant §N ci-dessous,
puis en suivant son pointeur.

- **§0-§1 — Modèle & données transverses** (entités season-scoped, tables fédérales GLOBALES,
  `OpponentVenueLink`, cache de trajets, alias de gymnase, écarts de salle, registre « à corriger
  dans FBI ») → [`module-matchs-modele-donnees.md`](module-matchs-modele-donnees.md)
- **§2-§4 — Détecteur de conflits, solveur de placement, gardien à l'ouverture** (delta de visite,
  échéances de saisie) → [`module-matchs-radar-et-placement.md`](module-matchs-radar-et-placement.md)
- **§5-§6 — Écran Calendrier, Écran Conflits** → [`module-matchs-consultation.md`](module-matchs-consultation.md)
- **§7 — Écran Importer** (dépôt FBI, canal API FFBB, terrain neutre, « validé ligue », workflow
  de traitement) → [`module-matchs-import-fbi.md`](module-matchs-import-fbi.md)
- **§8, §8bis, §10 — Écrans Configuration, Contraintes, Semaine type** → [`module-matchs-reglages.md`](module-matchs-reglages.md)
- **§9 — Écran Adversaires** → [`module-matchs-adversaires.md`](module-matchs-adversaires.md)
- **§11 — Le périmètre engagé** (`TeamEngagementGuard`) → [`module-matchs-perimetre-engage.md`](module-matchs-perimetre-engage.md)
- **§12 — Tests & gardes** (pointeurs) → [`module-matchs-tests.md`](module-matchs-tests.md)
