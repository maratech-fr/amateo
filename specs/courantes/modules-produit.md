# Modules produit — ce qu'Amateo vend, en langage club

Last verified @ 2026-09-25 (`documentation-update`, passe finale P5-26 — confronté à
`landing/index.html` §`#matchs`/§`final`/FAQ hébergement, `docs/ops/backup-restore.md` §1-2
(cadence `pg_dump` nocturne, région Scaleway `fr-par`), `.claude/rules/landing.md`, décision
`etat-des-lieux.md` §2 recalée le même jour, `backend/src/Service/Basketball/
FfbbClubPopulator.php`, `backend/src/Service/Basketball/FfbbTeamImporter.php`).

> **Rôle de ce fichier.** `etat-des-lieux.md` §1 est la carte technique (entités, PR, pointeurs) —
> ce fichier est sa **couche en langage club** : ce que le produit fait, dit à qui le vit sur le
> terrain, pour nourrir la page de vente, la FAQ et les scripts de démo. Une entrée = un point
> que le club vit, pas une capacité technique. Additif : un nouveau module ou un nouveau point
> livré s'ajoute ici, jamais une ligne inventée avant d'être livrée.

> « L'intelligence du club ne dépend plus d'une personne : elle est entre vos mains. Si le
> gestionnaire part, on continue — sans dépendre des oublis de passation. »

Cette promesse rejoint une décision de fond du fondateur (2026-08-02) : **« On reste dans cette
démarche de rendre le cerveau et la connaissance du gestionnaire facilement transférables dans
notre outil. »** (`specs/evolution/ffbb-appariement-source-de-verite.md` §1ter). Le vrai problème
n'est pas le temps passé, c'est la **charge mentale** : aujourd'hui le gestionnaire doit penser à
tout sans rien oublier. Le temps gagné et l'anticipation avant le week-end sont des moyens vers
cette fin, pas la promesse elle-même.

---

## Module 1 — Le planning (d'entraînements)

### 1. Planning de saison

**Promesse.** Vous décrivez une fois vos équipes, vos gymnases, vos coachs et vos contraintes —
le planning de la saison sort seul, et vous gardez la main pour l'affiner.

**Livré :**
- Wizard en 6 étapes (équipes / gymnases / coachs / contraintes / récap / génération), une
  persistance par entité saisie (aucun brouillon perdu) — `etat-des-lieux.md` §1.7.
- Génération par un solveur sous contraintes (passe unique, budget adaptatif, diagnostics nommés
  si une combinaison est impossible plutôt qu'un plan dégradé en douce) — `etat-des-lieux.md` §1.1,
  `docs/architecture/adr-0001-single-pass-solve.md`.
- Retouche à la main sur la grille, verrous manuels — un verrou est souverain mais son effet sur
  le reste du planning reste diagnostiqué, jamais silencieux — `etat-des-lieux.md` §1.1.
- Mutualisation d'un entraînement entre plusieurs équipes (déclarer, générer, poser, déplacer,
  retirer) — `etat-des-lieux.md` §1.2.
- Versions de travail et cycle de vie du planning validé (une seule porte pour le modifier : le
  rouvrir) — `etat-des-lieux.md` §1.3, `docs/architecture/adr-index.md` (ADR-0002).
- Export PDF / PNG / Excel, une page A4 paysage, tous les gymnases ou un seul — `etat-des-lieux.md`
  §1.9.

**Où dans l'app :** `/wizard` (mise en place), `/planning` (grille et génération), `/` (cockpit —
lecture pour le bureau).

**Capture :** `landing/assets/planning.png`.

### 2. Les imprévus

**Promesse.** Un gymnase ferme, une période s'ajuste : vous adaptez cette fenêtre précise sans
reconstruire le planning de la saison.

**Livré :**
- Une période d'exception (fermeture) posée depuis le cockpit ; sa grille de gymnases est une
  **copie** prise à sa naissance, jamais une réécriture de la saison — `etat-des-lieux.md` §1.2.
- Découpage automatique en segments début / milieu / fin pour une fermeture qui déborde une
  semaine entière — `etat-des-lieux.md` §1.2 (P2-41).
- L'adaptation peut naître comme une copie du planning de saison, **sans repasser par le
  solveur** — les séances qui ne passent plus sont nommées « à replacer » — `etat-des-lieux.md`
  §1.2 (ADR-0004).
- Comblement automatique des trous restants (le reste du planning est épinglé, seul le vide est
  reproposé au solveur) — `etat-des-lieux.md` §1.2.
- Les écarts avec le planning de saison sont nommés (séance déplacée, séance non replacée et
  pourquoi) — `etat-des-lieux.md` §1.2.
- Le plan dit lui-même « à régénérer » dès que la saison a bougé depuis — `etat-des-lieux.md` §1.2
  (P4-173).

**Où dans l'app :** cockpit `/`, dialogue du jour, écran du plan de période.

**Capture :** aucune encore.

### 3. Les vacances et le planning de vacances

**Promesse.** Le calendrier scolaire arrive tout seul, la semaine de reprise a son propre
planning progressif, et vous êtes prévenus avant l'échéance, pas après.

**Livré :**
- Vacances scolaires (zones A/B/C + 13 codes DOM/TOM) et jours fériés importés depuis les API
  publiques, affichés au cockpit — `vacances-scolaires-jours-feries.md`.
- Un « planning de reprise » dédié aux vacances : reprise progressive semaine par semaine, chacune
  son propre plan, effectif par défaut réduit (fanion + équipes importantes) — `types-de-planning.md`
  §3.
- Rappels avant l'échéance (J-14 / J-7 / J-3) par e-mail, plus un radar in-app — `etat-des-lieux.md`
  §1.2.

**Où dans l'app :** cockpit `/`, dialogue du jour sur une période de vacances.

**Capture :** aucune encore.

### 4. Les contraintes, faciles à gérer

**Promesse.** Vous posez une règle une fois — un jour interdit, une salle indisponible, un coach
— le solveur ne l'oublie jamais ; certaines vont même de soi, sans que vous ayez à les écrire.

**Livré :**
- Règles par club, équipe, coach ou gymnase — `etat-des-lieux.md` §1.1.
- Règles implicites honorées sans aucune saisie (le coach principal est présent à ses séances, un
  repos suit un jour de match, les séances d'un même coach dans une même salle sont regroupées) —
  `etat-des-lieux.md` §1.1.
- Vœux et indisponibilités des coachs collectés par une page à lien personnel, **sans compte** —
  dépôt borné au périmètre du lien, expire à la deadline — `etat-des-lieux.md` §1.6.
- Le lien du coach dépose un souhait, il **n'écrit jamais** une contrainte : c'est toujours le
  gestionnaire qui arbitre et tranche — `etat-des-lieux.md` §1.6.

**Où dans l'app :** wizard étape Contraintes, page publique `/doleances/:token` (coach), cockpit
(todo-list des doléances).

**Capture :** aucune encore.

---

## Module 2 — Les matchs

### 1. L'aide au placement

**Promesse.** Vous déposez l'export de la fédération, l'appli range vos matchs par équipe et pose
vos domiciles sur les créneaux où vos gymnases sont disponibles — vous n'avez plus qu'à ajuster.

**Livré :**
- Import du fichier FBI global du club (une seule passe), correspondance division ↔ équipe
  validée par le gestionnaire avant tout import — `etat-des-lieux.md` §1.5.
- Un re-dépôt du même fichier est **réconcilié par écart** (date, heure, salle) — jamais un
  écrasement silencieux — `etat-des-lieux.md` §1.5 (RMM-4).
- Placement automatique des domiciles sur les fenêtres d'accès des gymnases, par un second solveur
  dédié — `etat-des-lieux.md` §1.5 (ADR-0003).
- Résultat ajustable et verrouillable à la main (déplacer, échanger, verrouiller, rendre au
  solveur) — `etat-des-lieux.md` §1.5.

**Où dans l'app :** `/matchs` (Calendrier), `/matchs/importer`.

**Capture :** `landing/assets/matchs.jpg`.

### 2. La détection des erreurs FBI

**Promesse.** Un écart entre ce que vous avez saisi et ce que dit la fédération ne passe plus
inaperçu — vous savez exactement quoi corriger, et où le corriger.

**Livré :**
- Détection des écarts à l'import (date, heure, salle) entre votre planning et le fichier déposé
  — `etat-des-lieux.md` §1.5.
- Un registre « à corriger dans FBI » et un écran unique « FBI — à faire » qui dit ce qu'il faut
  taper dans FBI en face de ce que FBI affiche encore — `etat-des-lieux.md` §1.5.
- Une échéance de saisie par compétition (ligue ou comité), affichée à côté de chaque rencontre,
  qui alerte sans jamais bloquer — `etat-des-lieux.md` §1.5 (RMM-6).

**Où dans l'app :** `/matchs` (deep-link « FBI — à faire »), `/matchs/importer`.

**Capture :** `landing/assets/matchs-importer.jpg`.

### 3. Les conflits de coachs vus en amont

**Promesse.** Un coach sur deux rencontres, un coach en match et à l'entraînement au même moment,
un gymnase pris deux fois : vous le voyez avant le week-end, pas après.

**Livré :**
- Un radar détecte à la volée les conflits de coach (deux rencontres, ou un match et un
  entraînement simultanés), les collisions de gymnase et un calendrier incomplet —
  `etat-des-lieux.md` §1.5.
- Chaque conflit se **traite**, il ne se masque jamais : à traiter (par défaut), dérogation
  demandée, réglé en interne, ou sans solution pour l'instant — `module-matchs.md` § Résolution des
  conflits.
- Un onglet Conflits dédié, pivoté par coach, équipe, gymnase ou journée — `etat-des-lieux.md`
  §1.5.

**Où dans l'app :** `/matchs/conflits`.

**Capture :** `landing/assets/matchs-conflits.jpg` — la vue Conflits liste des coachs, admise
depuis le 2026-09-25 sous nom de démo fictif (`.claude/rules/landing.md`, décision recalée
`etat-des-lieux.md` §2).

### 4. Les déplacements extérieurs

**Promesse.** Un match à l'extérieur mobilise votre coach et votre équipe plus longtemps qu'un
simple entraînement, trajet compris — et vous savez où joue chaque adversaire sans le chercher.

**Livré :**
- Une empreinte-temps par match (durée + échauffement, par catégorie) qui intègre, pour un
  déplacement, le trajet routier réel entre le siège du club et le gymnase adverse —
  `etat-des-lieux.md` §1.5.
- Les trajets sont calculés une fois puis mis en cache par club, en tâche de fond, avec un suivi
  en direct de l'avancement — `etat-des-lieux.md` §1.5.
- Un annuaire des adversaires auto-localisé depuis le libellé du fichier FBI, sans saisie —
  `etat-des-lieux.md` §1.5.

**Où dans l'app :** `/matchs/adversaires`.

**Capture :** aucune encore.

---

## L'argument d'entrée — « On ne repart pas de zéro »

**Promesse.** Vous vous inscrivez avec le code de votre club, et l'appli arrive déjà à moitié
remplie : son nom, ses couleurs, son logo, et même les équipes qu'il a engagées cette saison.

**Livré :**
- Inscription par code FFBB : nom, adresse/coordonnées (si aucune n'a encore été saisie à la
  main), couleur d'accent et logo du club récupérés depuis l'API publique de la fédération —
  `backend/src/Service/Basketball/FfbbClubPopulator.php`.
- Sur un club neuf (sans aucune équipe), les équipes engagées à la fédération sont créées
  **automatiquement** — niveau, genre et catégorie décodés depuis l'engagement — plutôt que
  saisies une par une — `backend/src/Service/Basketball/FfbbTeamImporter.php`.
- L'export FBI des équipes peut aussi être importé à l'étape Équipes du wizard, en cochant les
  lignes à reprendre (doublons détectés et décochés par défaut) — `etat-des-lieux.md` §1.7.

**Où dans l'app :** `/register` (code FFBB), `/wizard` étape Équipes.

**Capture :** aucune encore.

---

## Phrases

Formulations à réutiliser telles quelles pour la page de vente et les scripts de démo — recopiées,
jamais reformulées :

- « Le planning de votre club, sans le casse-tête. »
- « Toutes tes contraintes en sécurité. Ton planning en minutes. »
- « Fini le planning refait 10 fois sur Excel. »
- « Aujourd'hui il doit penser à tout sans rien oublier. »
- « L'intelligence du club ne dépend plus d'une personne, elle est entre vos mains. »
- « Le calendrier des matchs arrive de la fédération. L'app le range. »
- « Un import FBI : du temps gagné, des soucis vus avant le week-end. »
- « Les matchs, un dossier de moins à garder en tête. »
- « Le vrai problème n'est pas le temps passé. C'est la charge mentale. »
- « Chaque année, dans chaque club de basket FFBB, quelqu'un passe ses soirées à répartir les
  créneaux de gymnase entre les équipes, en espérant n'oublier aucune contrainte. Amateo capture
  toutes les contraintes du club, génère un planning optimisé en quelques minutes, et le réajuste
  quand la réalité change. »
- « La saison n'a pas encore débuté que le planning est déjà prêt. »
