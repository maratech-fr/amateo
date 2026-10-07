# Gestion des matchs (FFBB) — reste ouvert (paliers A/B livrés)

> 🗄 **ARCHIVÉ le 2026-10-07** (déplacé de `specs/evolution/`) : paliers A/B livrés (`specs/courantes/module-matchs.md`) ; le seul reste, le palier C (effet réseau cross-club), est une ligne en attente de la roadmap. Référence figée, plus maintenue.

> **Ce fichier ne garde plus que l'OUVERT** (graduation DOC-56, 2026-10-06). Les paliers A (placement + radar solo, P1-4) et B (trajet + annuaire, RMM-8/P2-53 · RMM-9/P2-54) sont **livrés** : leur comportement vit dans [`module-matchs.md`](../../specs/courantes/module-matchs.md), les décisions tranchées (placement manuel et non solveur ; annuaire adverse table globale public-only ; dérogation = tracker, jamais un connecteur FFBB) dans [`etat-des-lieux.md`](../../specs/courantes/etat-des-lieux.md) §2, et le cadrage d'origine (problème, reframe, moteur de conflits, contraintes, catalogue-ligue, modèle de données, positionnement) dans `git log -p --follow` ce fichier. **Reste devant** : le **palier C** (effet réseau cross-club), dont la roadmap tient la ligne.

### 5bis. L'annuaire adverse = table GLOBALE, enrichie par tous les clubs

> ⚠ **AMENDÉ le 2026-08-28 (décision fondateur + revue sécurité, P2-54 PR-2 livrée).** Deux points de
> l'énoncé d'origine ci-dessous sont RENVERSÉS ; ils sont laissés pour lire le chemin. (1) **L'approximation
> VILLE est PRÉFÉRÉE au gymnase précis** quand ce dernier n'est pas gratuit : « je préfère une approximation
> de lieu par la ville que remplir les gymnases adverses 1 à 1 » — le gestionnaire ne rattache RIEN à la main.
> (2) **L'enrichissement PAR L'USAGE inter-clubs (tendances/heures adverses) est HORS scope RMM-9** : c'est
> exactement le vecteur A21/BCK-18 (empoisonnement d'une table partagée). L'annuaire livré est alimenté par
> les **seuls index fédéraux publics**, jamais par une contribution d'un autre club. Échelle réelle livrée :
> **(1) salle exacte du hit rencontre API** (coords fédérales autoritatives → précision `VENUE`) · **(2) repli
> VILLE** géocodée (précision `CITY`) pour le canal xlsx et le rattrapage. **La précision `VENUE` est
> RÉSERVÉE au canal API** — un libellé de salle issu d'un xlsx (fourni par le club) ne peut PAS établir un
> `VENUE` dans la table partagée (il épinglerait un adversaire à un faux gymnase lu par tous, empoisonnement
> permanent) ; gardé par `OpponentLocationResolverTest` (bloquant). L'ancien étage « appariement franc par
> nom de salle → VENUE » a été retiré pour cette raison. Le point 3 ci-dessous (heures cross-club) reste au
> **palier C**. Trace : `../courantes/etat-des-lieux.md` §3 ; comportement : `../courantes/module-matchs.md`.

« Plus on rencontre d'adversaires, plus on connaît leur position. » Le club de Meyzieu a saisi qu'il reçoit
au gymnase du Clar → **l'app connaît la position du Clar** → quand un autre club joue contre le Clar, elle la
donne **sans travail**. Trois enrichissements, **un seul annuaire** :

1. **Localisation** adverse (pour le trajet) — ~~on stocke directement le gymnase précis~~ **[amendé : la
   VILLE suffit, cf. bandeau ci-dessus]**. Le trajet reste tolérant (« < 15 min entre gymnase A et B, on
   s'en fiche »).
2. **Tendances** horaires adverses (« le Clar joue le samedi soir »). **[palier C, hors RMM-9]**
3. **Heures extérieures précises** (si l'adversaire est client et a saisi sa rencontre). **[palier C]**

> **Architecture : annuaire adverse = table GLOBALE (hors tenant), enrichie par l'usage**, exactement comme
> `school_holidays` / `public_holidays` déjà en place (données de référence globales, seedées + enrichies).

> ⚠ **Garde-fou sécurité (critique vu l'isolation tenant du projet, cf. `docs/security/rls.md`)** : cet
> annuaire **traverse délibérément le tenant** → il ne doit contenir que du **public** (adresse/ville d'un
> gymnase FFBB, déjà publiée ; heure d'un match publiée). **Jamais** de donnée privée club (dispos internes,
> créneaux d'entraînement, contraintes). Précédent propre : les tables fériés/vacances sont déjà globales et
> ne portent aucune donnée club. On calque ce patron. **Un test d'isolation dédié devra le garder.**

---

## 11. Paliers de valeur (ordre, pas un plan)

- **Palier A — le placement + le radar solo.** Import FFBB de la liste des rencontres (+ ajout manuel).
  Modèle Competition/Phase/Match. **Catalogue-ligue seedé (AURA)** → envelope HARD pré-remplie, éditable par
  le club. Grille datée par gymnase (réemploi du primitif grille). Détection de conflits **personne**
  (domicile-vs-domicile, hors-envelope HARD, match ↔ entraînement projeté) sur données **du club**. Heures
  extérieures **estimées** par tendance. **→ Déjà énorme : le gestionnaire voit les soucis.**
- **Palier B — la dérogation + le trajet.** Workflow dérogation (brouillon + suivi + deadline + radar).
  Matrice trajet siège↔ville → conflits **spatiaux** (temps + trajet). Annuaire adverse global amorcé.
- **Palier C — l'effet réseau.** Auto-remplissage des heures/positions extérieures par le cross-club
  (annuaire enrichi par l'usage). (Option V2 : auto-placement CP-SAT d'un week-end.)
