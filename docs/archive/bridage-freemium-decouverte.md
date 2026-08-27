# Bridage plan Découverte (freemium) + offres par statut — besoin spécifié

> **Statut** : ✅ **LIVRÉ le 2026-08-10** (P1-3 complet — PR #487 socle · #488 enforcement · #490 crédit XLSX ·
> PR C UX de conversion ; NR `PlanEntitlementsTest` au gate CI). Ce fichier reste la référence du MODÈLE
> (et de l'historique de cadrage §3) ; la carte du livré vit dans
> [`etat-des-lieux.md`](../../specs/courantes/etat-des-lieux.md) §1.11. Garde-fou encore ouvert : §7.2 (question aux bêta).
> **Nature** : fixe le modèle du plan gratuit ET le socle d'offres par statut, business-critique.
> **Rattachement roadmap** : **P1-3**.
> ⚑ **Modèle HYBRIDE acté le 2026-08-09 (soir)** — troisième et dernière itération d'une même journée
> de cadrage, chaque renversement tracé en §3 : générations seules (2026-08-04) → cap 12 équipes
> (2026-08-09 matin, après l'étude [`etude-tailles-clubs-ffbb.md`](../../specs/evolution/etude-tailles-clubs-ffbb.md) et une
> passe `business-challenger`) → **hybride** (2026-08-09 soir : périmètre complet gratuit + générations
> limitées + features off, cap d'équipes réservé aux paliers PAYANTS).
> **Réutilise l'existant** : `SubscriptionPlan` (modèle livré, aucune offre seedée) · `Club.planId`
> (⚠ `?int` face à un id guid — lien à réparer) · `Club.paidSeasonYear` + gate de bascule saison
> (P1-5, livré — la « transition off en gratuit » est DÉJÀ effective) · quotas de solve anti-abus
> par club (P4-45, indépendants du business) · patron de garde `ClubQuotaSubscriber` pour le compteur.
> **Zéro changement engine.**

---

## 1. Le but

Laisser un gestionnaire **saisir TOUT son club** (import FFBB compris) et **voir le solveur résoudre son
vrai problème** — le coût de bascule (saisir les contraintes orales) est payé AVANT tout mur, et le
planning obtenu est l'argument que le bénévole porte à son bureau. La conversion vient de ce que la
**saison vivante est payante** : plans de vacances, matchs, exports, saison suivante.

## 2. Le modèle : Découverte complète mais bornée, payants par taille

### Découverte (gratuit — l'entrée par défaut de tout compte)

- **Périmètre ILLIMITÉ en saisie et en configuration** : toutes ses équipes (l'import FFBB d'onboarding
  importe tout), gymnases, coachs, contraintes, matchs saisis, périodes créées. **Aucun cap d'équipes,
  aucune feature masquée** : le gestionnaire « prépare ses datas » pendant que son bureau valide l'achat.
- **UN pool de 10 CRÉDITS, propriété du CLUB** (valeur de config), **partagé entre ses gestionnaires**
  (gestionnaire 1 en consomme 3, gestionnaire 2 en consomme 5 → il en reste 2 pour tous), **non
  rechargeable** — pas « par saison », pas de limite horaire (l'anti-abus de débit reste P4-45).
  **Chaque ACTION DE SORTIE consomme 1 crédit** :
  - générer / régénérer un planning (les 3 routes de solve — plans de période compris) ;
  - placer les matchs (`POST /api/fixtures/place`) ;
  - exporter un livrable — **PDF ou XLSX** (revue sécu PR B, 2026-08-10 : sans le tableur dans le
    pool, le mur PDF se contournait par le second bouton d'export).
  Consulter et **ajuster à la main** ne consomment jamais rien. Reset **superadmin seulement**.
  À l'épuisement : plus aucune sortie — la configuration, la consultation et l'ajustement manuel
  restent ouverts. Pas de read-only, pas de lockout : l'envie de régénérer convertit.
- **Transition de saison** : le seul interrupteur fermé en Découverte (déjà effectif via
  `paidSeasonYear`, livré).

### Offres payantes — « quand tu payes, c'est juste le cap par équipe »

- **100 % des fonctionnalités**, générations **illimitées** (P4-45 seul frein). La SEULE différence
  entre paliers = le **cap d'équipes** :

| Offre | Cap équipes (app) | Note |
|---|---|---|
| Essentiel | ≤ 20 | |
| Club | 21-30 | |
| Grand club | 31-50 | |
| Sans limite | illimité | les > 50 (≈ BCCL et au-delà) |

  Frontières = **valeurs en base** (`maxTeams`), ajustables sans redéploiement ; calées sur l'étude
  (§4bis) et le read fondateur. Aucun montant nulle part — « sur demande ».
- **Enforcement du cap payant** : refus de créer une équipe AU-DELÀ du cap de SON offre (création
  unitaire, imports), message nommant le palier supérieur. Un club prend l'offre qui correspond à la
  taille qu'il a déjà saisie (« j'ai 15 équipes → Essentiel »).
- **Attribution superadmin seul** (virement → offre + `paidSeasonYear` posés en console). Valable
  **une saison** ; à l'expiration, l'offre effective retombe sur **Découverte** (features se ferment,
  compteur de générations tel quel) — le renouvellement rouvre tout. Pas de mécanisme de read-only.

### Bêta

- **Une offre** comme les autres : tout illimité, attribuable **UNIQUEMENT par le superadmin**,
  valable **une saison** ; à l'expiration → Découverte, le club choisit.

## 3. Historique des renversements (pourquoi CE modèle)

| Modèle | Sort |
|---|---|
| **~4 générations, club complet** (2026-08-04) | Renversé le 2026-08-09 matin : un club discipliné capte la valeur annuelle en 4 générations + ajustements gratuits ; mur en cours de saison (hors fenêtre d'achat) ; punit l'itération |
| **Cap 12 équipes, générations illimitées** (2026-08-09 matin) | Renversé le 2026-08-09 soir : son risque n°1 (nommé par le `business-challenger`, jamais couvert) — un club de 25 équipes **n'investit pas la saisie** face à un mur à 12, et l'essai sur sous-ensemble n'est pas le vrai club. Le test wow-12 (concluant) reste valable : il prouvait qu'un problème à 12 équipes n'est pas trivial — a fortiori le club entier |
| **Hybride** (2026-08-09 soir, ACTÉ — précisé le soir même en **pool de crédits unique**) | Cumule les forces : saisie complète gratuite (coût de bascule payé avant le mur, sunk cost pro-conversion), wow sur le vrai club, mur = la saison vivante. Première formulation : features OFF (matchs/vacances/PDF) + 10 générations. **Précision fondateur finale : pas de features OFF — UN pool club de 10 crédits partagé paye CHAQUE sortie** (solve planning/période, placement matchs, export PDF) ; tout est visible et configurable, seules les sorties se payent. Encore plus simple (un seul mécanisme) et le trou de 2026-08-04 reste bouché : 10 sorties ne couvrent pas une saison (socle ≈ 3-5 solves, 3-4 périodes, matchs, exports…) et la saison suivante est verrouillée |
| Limite horaire (« 1-2/h ») | Écartée : punit la soirée de travail active ; P4-45 borne déjà le débit |
| Bombe temps · cap par saison · lockout/read-only | Écartés (inchangé 2026-08-04) — le read-only devient de surcroît INUTILE : l'expiration retombe sur Découverte et la transition payante verrouille la suite |

## 4. Enforcement — petit, sur des patrons existants

1. **Pool de crédits** : garde UNIQUE sur les points de sortie — les 3 routes de solve (patron
   `ClubQuotaSubscriber` P4-45), le placement de matchs (`/api/fixtures/place`), l'export PDF —
   active si l'offre EFFECTIVE est Découverte : refus à 0 crédit avec message de conversion, sinon
   décompte. Champ compteur **total au niveau CLUB** (l'existant `generation_count_season` se remet à
   zéro par saison — inutilisable tel quel). Reset superadmin.
2. **Cap payant aux portes de création d'équipes** : ne s'applique QU'AUX offres payantes (Découverte
   et Bêta sans cap). Trois portes réelles : création unitaire, import Excel, import FFBB. ⚑ Constat
   PR B (2026-08-10) : `engagements/confirm` n'en est PAS une — il apparie des équipes EXISTANTES
   sans en créer, un cap y serait inerte ; écarté, pas de code spéculatif.
3. **Offre effective calculée à la lecture** (service de droits) : `planId` null → Découverte ;
   offre payante/bêta avec `paidSeasonYear` périmé → Découverte. Pas de cron, pas d'état stocké.
   Club démo (`isDemo`) : droits pleins, toujours. ⚠ **L'attribution est en DEUX gestes** (choix PR A) :
   `set-plan` pose l'offre, la saison réglée (`mark-next-season-paid`) la rend effective — **Bêta comprise**
   (une bêta sans saison réglée naît expirée ; le piège a mordu le seeder dev, corrigé en PR B).

## 4bis. UX de la conversion (fondateur, 2026-08-10 — implémentation en PR C)

Découverte SEULEMENT (payant/bêta/démo ne voient rien de tout ça), toujours piloté par les compteurs
SERVEUR (`entitlements` de `/api/me` — le front ne calcule rien, règle P2-8) :

1. **Compteur permanent** dans le shell : badge « Crédits : 8/10 », tooltip « une génération, un
   placement de matchs ou un export PDF consomme 1 crédit — ajuster et consulter sont gratuits » ;
   ambre à ≤ 5.
2. **Le coût au point d'action** : chaque bouton de sortie affiche le solde — « Générer (8) »,
   « Placer les matchs (8) », « Exporter en PDF (8) ».
3. **Bandeau d'urgence à ≤ 3 crédits** : rouge, fermable, CTA « Voir les offres ». Il ne se ré-affiche
   PAS à chaque navigation (un bandeau qui crie tout le temps n'alerte plus — règle du protocole `review-response`) : il revient
   quand le solde BAISSE encore, ou à la session suivante.
4. **À 0 crédit** : bandeau permanent non fermable au ton juste — « Vos crédits gratuits sont épuisés.
   Consultez et ajustez librement — passez à une offre pour générer à nouveau. » Boutons de sortie
   « (0) » désactivés avec le message de conversion.

## 5. Décisions tranchées (2026-08-09 soir — remplacent toutes les précédentes)

1. **Découverte** = défaut de tout compte : périmètre et configuration illimités, **pool CLUB de
   10 crédits** partagé entre gestionnaires, non rechargeable (config) — 1 crédit par SORTIE (solve
   planning/période, placement matchs, export PDF) ; consulter/ajuster gratuits ; seule la transition
   de saison est fermée ; pas de lockout à l'épuisement.
2. **Payants** = 100 % des features, générations illimitées, **seul le cap d'équipes varie** :
   Essentiel ≤ 20 · Club ≤ 30 · Grand club ≤ 50 · Sans limite. Aucun montant dans l'app.
3. **Bêta = une offre** superadmin-only, une saison, illimitée.
4. **Attribution d'offre = superadmin seul** (virement) ; expiration → Découverte effective.
5. **PSP = Stripe, différé** (décision du matin, inchangée) ; prérequis SIRET avant premier encaissement.
6. Le nom du gratuit reste **« Découverte »** (conforme à toute la doc).
7. Affiliation → parking roadmap.
8. `monthlyPrice`/`annualPrice` : à retirer du modèle (aucun montant nulle part) ; `maxGenerations`/
   `maxVenues` : convention **0 = illimité**.

## 6. Dépendances & hors scope

- **Contournement « 10 crédits puis on vit à la main »** : assumé — une saison réelle consomme bien
  plus de 10 sorties (socle 3-5 solves, 3-4 périodes de vacances, placements de matchs, exports), et
  la saison suivante est verrouillée : le planning gratuit devient un souvenir, pas un outil de saison.
- **Multi-comptes** : sans objet (le périmètre gratuit est déjà complet).
- **Guidage des contraintes** : chantier séparé (l'enjeu d'une génération gâchée monte avec un compteur).
- **Compte démo vendeur** : complémentaire, exempt de tout gate (`isDemo`).
- Montants, Van Westendorp, Stripe/checkout, notification d'expiration : hors scope de ce doc.

## 7. Garde-fous (mis à jour)

1. ✅ **Test wow-12 (2026-08-09)** — gardé pour mémoire : un problème à 12 équipes n'est pas trivial
   (17/17 séances, 0 violation, infaisabilité réelle détectée en 3 ms). Le modèle hybride résout mieux
   encore (club entier).
2. **Question aux clubs bêta** (reformulée pour l'hybride) : « tout est ouvert, mais le club a
   10 crédits gratuits — une génération, un placement de matchs ou un PDF en consomme un. Tu aurais
   payé à quel moment ? » — Rillieux en premier.
3. Le chiffre **10** est une valeur de config ajustable, pas une promesse produit.

## 8. Axes structurants (§7.1) & vérification

- **generation pipeline** : le pool gate les sorties → NR (Découverte à 10/10 → solve, placement de
  matchs et PDF refusés avec message ; le pool est PARTAGÉ entre les gestionnaires du même club et
  isolé entre clubs — même exigence que P4-45 ; payant/bêta → jamais de refus business ; reset
  superadmin rouvre ; `ClubQuotaTest` P4-45 ne doit pas rougir).
- **auth & memberships / périmètre** : caps payants aux portes de création → NR (Découverte crée sa
  25ᵉ équipe SANS refus et configure matchs/périodes librement ; Essentiel refuse la 21ᵉ avec message ;
  expiration → Découverte effective).
- **Vérification** : smoke-solveur inchangé (`backend/scripts/smoke-solver.sh`, COMPLETED) — le club
  de smoke doit garder du quota ou une offre non bridée.
