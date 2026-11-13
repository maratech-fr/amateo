# Modules produit — ce qu'Amateo vend, en langage club

> **Rôle de ce fichier.** `etat-des-lieux.md` §1 est la carte technique (entités, PR, pointeurs) —
> ce fichier est sa **couche en langage club** : ce que le produit fait, dit à qui le vit sur le
> terrain, pour nourrir la page de vente, la FAQ et les scripts de démo. Une entrée = un point
> que le club vit, pas une capacité technique. Additif : un nouveau module ou un nouveau point
> livré s'ajoute ici, jamais une ligne inventée avant d'être livrée.

> « L'intelligence du club ne dépend plus d'une personne : elle est entre vos mains. Si le
> gestionnaire part, on continue — sans dépendre des oublis de passation. »

Cette promesse rejoint une décision de fond du fondateur (2026-08-02) : **« On reste dans cette
démarche de rendre le cerveau et la connaissance du gestionnaire facilement transférables dans
notre outil. »** (`docs/archive/ffbb-appariement-source-de-verite.md` §1ter). Le vrai problème
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
- Export PDF / Excel, une page A4 paysage, tous les gymnases ou un seul — `etat-des-lieux.md`
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
- Avant d'enregistrer la fermeture d'un gymnase, un aperçu dit ce qu'elle touche : les équipes
  qui s'y entraînent, les matchs à déplacer (nommés : équipe, adversaire, jour, heure), et les
  verrous et réservations qui seront retirés des plannings de période concernés —
  `frontend/src/features/cockpit/ClosureImpactPanel.tsx:15-20`.
- Découpage automatique en segments début / milieu / fin pour une fermeture qui déborde une
  semaine entière — `etat-des-lieux.md` §1.2 (P2-41).
- L'adaptation peut naître comme une copie du planning de saison, **sans repasser par le
  solveur** — les séances qui ne passent plus sont nommées « à replacer » — `etat-des-lieux.md`
  §1.2 (ADR-0004).
- Comblement automatique des trous restants (le reste du planning est épinglé, seul le vide est
  reproposé au solveur) — `etat-des-lieux.md` §1.2.
- Les écarts avec le planning de saison sont nommés (séance déplacée, séance non replacée et
  pourquoi) et résumés équipe par équipe, rang par rang — `etat-des-lieux.md` §1.2.
- `/planning` dit « à régénérer » dès que la structure a bougé depuis, sur une version de
  TRAVAIL seulement — muet sur un planning validé — `etat-des-lieux.md` §1.2/§2 (P4-266).

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
- Rappels par e-mail à J-14 / J-7 / J-3 avant une période posée par le club, et à J-30 / J-14 /
  J-7 avant des vacances scolaires non préparées — `backend/src/Command/PeriodReminderCommand.php`.

**Où dans l'app :** cockpit `/`, dialogue du jour sur une période de vacances.

**Capture :** aucune encore.

### 4. Préparer la saison suivante dès le 1er mai

**Promesse.** Le planning de septembre est prêt en juin : vous n'attendez plus l'été pour vous y
mettre. Les familles, qui ont besoin de visibilité pour organiser les autres activités des
enfants, savent dès juin quand a lieu le basket ; et le bureau part en vacances l'esprit
tranquille.

**Livré :**
- À partir du 1er mai et jusqu'à la fin de la saison en cours, « Préparer la saison suivante »
  copie vos gymnases, vos équipes, vos coachs et vos règles permanentes dans une saison à part,
  librement modifiable — la saison en cours n'est pas touchée, et le planning généré n'est pas
  copié (il se génère dans la nouvelle saison) — `frontend/src/app/SeasonSelector.tsx:51-56,159,166`.
- Un rappel par e-mail aux gestionnaires tant que la saison suivante n'est pas préparée : vers le
  15 mai, vers le 15 juin et vers le 1er juillet (échéance : le 15 juillet) —
  `backend/src/Command/TransitionReminderCommand.php:30-34,48`.

**Où dans l'app :** sélecteur de saison de l'en-tête.

**Capture :** aucune encore.

### 5. Les statistiques d'occupation des gymnases

**Promesse.** Pour négocier vos créneaux avec la mairie, vous avez les chiffres : « le lundi, on a
8 heures, dont 3 de régional ».

**Livré :**
- Les heures de chaque gymnase, puis de chaque niveau, jour de la semaine par jour de la
  semaine, séparées entre réalisé et à venir, avec une ligne de total par jour ; la plage de dates
  se choisit (la saison entière par défaut) — `backend/src/Controller/VenueUsageStatsController.php:35-37`,
  `frontend/src/features/club/ClubPage.tsx:541,593-605`.
- Condition : le planning de saison doit être validé ; sinon le tableau est vide et l'écran dit
  pourquoi — `backend/src/Controller/VenueUsageStatsController.php:122-131`.

**Où dans l'app :** `/club`, section « Statistiques des gymnases ».

**Capture :** aucune encore.

### 6. Les contraintes, faciles à gérer

**Promesse.** Vous posez une règle une fois — un jour interdit, une salle indisponible, un coach
— le solveur ne l'oublie jamais ; certaines vont même de soi, sans que vous ayez à les écrire.

**Livré :**
- Règles par club, équipe, coach ou gymnase — `etat-des-lieux.md` §1.1.
- Règles implicites honorées sans aucune saisie (le coach principal est présent à ses séances, un
  repos suit un jour de match, les séances d'un même coach dans une même salle sont regroupées) —
  `etat-des-lieux.md` §1.1.
- Vœux, jours souhaités et indisponibilités des coachs collectés par une page à lien personnel,
  **sans compte** — dépôt borné au périmètre du lien, expire à la deadline — `etat-des-lieux.md` §1.6.
- Le lien du coach dépose un souhait, il **n'écrit jamais** une contrainte : c'est toujours le
  gestionnaire qui arbitre et tranche (les jours souhaités sont purement informatifs, zéro effet
  solveur) — `etat-des-lieux.md` §1.6.
- Pour une équipe à plusieurs coachs principaux, un seul coach répond pour tout le staff : le
  gestionnaire choisit lequel reçoit le lien — `backend/src/ApiResource/CoachWishCampaignResource.php:48`.
- Chaque matin à 7 h, les gestionnaires reçoivent par e-mail les nouvelles réponses (rien s'il n'y
  en a pas), et un récapitulatif final à l'échéance — `backend/src/Command/CoachWishDigestCommand.php:32`.
- « Relancer les silencieux » : une relance par jour, aux seuls coachs qui n'ont pas répondu —
  `backend/src/ApiResource/CoachWishCampaignResource.php:40`.
- Les souhaits cochés passent au planning d'un geste, en réglages de la semaine concernée — le
  gestionnaire relit avant de transférer — `backend/src/Controller/CoachWishTransferController.php`.
- Les règles de bien-être se règlent d'un clic (Obligatoire ou Objectif) : chaque coach
  garde un jour de repos par semaine ; un salarié est placé chaque jour où le club tourne
  (jamais bloquant, un jour sans salarié est signalé après coup) ; jamais trop de créneaux
  dos-à-dos pour une même personne, ni trop de jours d'affilée pour une équipe ; et les jeunes
  passent avant les grands sur un même gymnase et un même jour —
  `backend/src/Enum/ImplicitRuleKey.php:83-90`, `frontend/src/features/wizard/lib/implicitRules.ts:60-70`.
- Le récapitulatif dit ce qui bloquera AVANT de lancer la génération (« n blocages à corriger
  avant de générer »), et ce qu'il dit voué à l'échec est refusé avant même d'être envoyé au
  calcul, quel que soit le chemin pris — `frontend/src/features/wizard/steps/RecapStep.tsx:201-204,425`,
  `backend/src/Service/GenerationBlockersGuard.php`. Il avertit aussi sans bloquer, par exemple
  quand un coach seul sur une équipe a plus de séances que de jours disponibles —
  `backend/src/Service/PreSolvePreventionWarnings.php:329`.

**Où dans l'app :** wizard étape Contraintes et Récap, page publique `/doleances/:token` (coach),
cockpit (todo-list des doléances).

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
  demandée, réglé en interne, ou sans solution pour l'instant — `module-matchs-consultation.md` § Résolution des
  conflits.
- Un onglet Conflits dédié, pivoté par coach, équipe, gymnase ou journée — `etat-des-lieux.md`
  §1.5.
- D'un geste, chaque coach concerné reçoit par e-mail SA liste de conflits : le conflit se règle
  des semaines avant, pas la semaine du match. Un aperçu dit avant l'envoi, coach par coach, ce
  qui partira ; par défaut, seuls les coachs qui ont un conflit nouveau ou modifié depuis le
  dernier envoi sont écrits (les changements sont marqués dans le mail), un choix renvoie toutes
  les listes ; un coach qui n'a plus de conflit est prévenu qu'il n'en a plus —
  `backend/src/Controller/ConflictCoachMailController.php:43-50`.

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

### 5. La liste des matchs en PDF

**Promesse.** Quelqu'un vous demande « c'est quand, les matchs ? » : vous sortez la liste en un
clic, prête à transmettre — Amateo ne l'envoie à personne, c'est vous qui la donnez.

**Livré :**
- Un PDF des matchs de tout le club ou d'une seule équipe, entre deux dates, regroupé par équipe
  ou par jour, avec l'option « matchs à domicile seulement » —
  `backend/src/Controller/ExportMatchesPdfController.php:26-27,45-70`.
- Tout membre du bureau peut le télécharger, pas seulement les gestionnaires (comme l'export du
  planning) — `backend/src/Controller/ExportMatchesPdfController.php:26-27`.

**Où dans l'app :** `/matchs` (Calendrier), bouton « Exporter ».

**Capture :** aucune encore.

---

## Le club à plusieurs — le bureau et la passation

**Promesse.** Le bureau voit tout sans risquer de rien casser, et si le gestionnaire part, la
suite est prévue — sans dépendre d'un oubli de passation.

**Livré :**
- Deux rôles : Gestionnaire, qui modifie, et Membre, qui consulte tout et ne modifie rien ; le
  serveur refuse l'écriture à un Membre, l'écran ne lui offre même pas le geste —
  `frontend/src/shared/lib/roles.ts:15,31-38`, `backend/src/Service/ManagementAccessGuard.php`.
- Le gestionnaire invite un collègue du bureau par e-mail, en lui donnant le rôle de Gestionnaire
  ou de Membre — `backend/src/Controller/ClubInvitationController.php:64-113`.
- Avant de supprimer son compte, le seul gestionnaire du club nomme un successeur parmi les
  Membres, en un clic — `frontend/src/features/profile/SuccessorPicker.tsx:13-18`.
- Un Membre peut demander à devenir gestionnaire ; les gestionnaires acceptent ou refusent, et
  sans réponse au bout de 14 jours la demande est transmise une fois à l'adresse officielle du
  club. Si le club n'a plus aucun gestionnaire, la demande part directement à cette adresse —
  `backend/src/Service/ManagerRoleRequestService.php:26-29,43`.

**Où dans l'app :** `/club` (membres, invitations), `/profile` (zone danger).

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
- Import d'un planning existant par IA : équipes, gymnases, créneaux, séances partagées,
  réservations — jamais les coachs ni les contraintes —
  `backend/src/Service/ParcImport/ParcImportExecutor.php`.

**Où dans l'app :** `/register` (code FFBB), `/wizard` étape Équipes.

**Capture :** aucune encore.

---

## Ce qu'Amateo ne fait pas

Les limites du produit, dites franchement à qui les vit :

- Il n'écrit rien dans FBI : il dit quoi y saisir (écran « FBI — à faire », module 2 §2), le
  gestionnaire le tape lui-même. Côté fédération, l'accès est en lecture seule.
- Il ne gère ni paiements, ni licences, ni cotisations.
- Il ne fabrique pas le calendrier des championnats : celui-ci arrive de la fédération, il le range.
- Il ne remplace pas le jugement du gestionnaire : Amateo dégrossit le travail, à vous les
  finitions.
- Il n'envoie ni convocations ni messages aux joueurs ou aux familles. Le club
  exporte (le planning, la liste des matchs en PDF) et donne le fichier à qui il veut. Seuls les
  coachs reçoivent des e-mails d'Amateo, envoyés par le gestionnaire : le lien personnel où ils
  déposent leurs disponibilités (sans compte), ses relances et la liste de leurs conflits. Les
  autres e-mails vont au bureau : ceux du compte (vérification, invitation, mot de passe), les
  rappels de période et de saison suivante, le résumé des réponses des coachs.
- Il est fait pour le basket amateur affilié FFBB, et pour lui seul.

---

## Phrases

Formulations à réutiliser telles quelles pour la page de vente et les scripts de démo — recopiées,
jamais reformulées :

- « Le planning de votre club de basket, sans le casse-tête. » (h1 de la vitrine depuis P4-391 ;
  l'accroche des e-mails garde la forme sans « basket »)
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
