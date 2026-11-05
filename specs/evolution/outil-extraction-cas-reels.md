# Outil d'extraction de cas métier réels — gardé pour plus tard

> **Statut : ouvert, non planifié** (décision fondateur 2026-11-05 : « on le garde pour plus tard »). Ce fichier
> garde le cadrage et le plan déjà écrits pour ne pas repartir de zéro. Ligne de roadmap : P4-502.

## Le besoin

Transformer un cas métier réel en test automatique anonyme, chaque cas portant le **geste** qui l'a provoqué
(écran → bouton → appel API, ou import, job asynchrone, API externe, horloge). Chaque défaut réel devient un
cas permanent. But : les cadrages cessent de se tromper sur « quel geste permet vraiment ce cas ? », et les
tests éprouvent les limites sur des données réelles plutôt qu'inventées.

## Décisions déjà prises (fondateur, 2026-11-05)

1. **Portée : hybride.** Le backend fournit le snapshot brut, l'anonymisation est **unique, dans le moteur**,
   adossée au schéma d'entrée (`engine/app/schemas/input_schema.py`). Les cas « geste backend » (ex. import
   puis « Régénérer ») sont décrits dans la fiche du cas et rejoués par un test backend.
2. **Source d'un signalement : le JSON copié à la main** depuis la console superadmin ; l'outil n'accepte que
   le snapshot, tout le reste du contexte du signalement est refusé.
3. **Lisible : les catégories d'équipe** (U15F, SM…) ; gymnases, coachs (« Coach NN »), identifiants, codes
   FFBB et coordonnées deviennent génériques.
4. **Garde-fou : bac à sable seulement** — l'extraction refuse une base où des identités réelles ont été
   chargées.
5. Forme : une **commande déterministe** + un **skill** de procédure ; pas d'IA dans l'anonymisation, pas de
   nouvel agent.

## Ce qui existe déjà et servira de base

- `engine/tests/fixtures/derive_fully_reserved_grid.py` : dérive une fixture d'un snapshot par **liste
  autorisée** (conservé / écarté / toléré vide ; un champ inconnu arrête tout en nommant le champ, jamais la
  valeur), avec son test d'hygiène `engine/tests/semantic/test_fully_reserved_grid_two_employees.py`.
- Snapshot figé d'une génération : `Schedule::engineInput()` ; copie dans un signalement :
  `FeedbackContextEnricher`.

## Plan v1 (à reprendre, pas à exécuter tel quel)

- Commande backend `app:case:snapshot <scheduleId>` (dev/test seulement) qui imprime `engineInput()` ;
  refus si l'environnement n'est pas dev/test, si un fichier d'identités réelles est présent, ou si un coach
  du club n'a pas un libellé neutre.
- Outil moteur `derive_case.py` : politique **par champ** du schéma (remappé, générique, vidé, conservé),
  auto-contrôle « aucune valeur source dans la sortie », sortie déterministe.
- Un cas = un dossier `engine/tests/cases/<slug>/` : `payload.json` (rejouable par `/generate`), `CASE.md`
  (déclencheur, entrée, observé — jamais asserté —, attendu en propriétés, statut proposé → validé →
  bloquant), test.
- Premier cas candidat : régénération sur un résultat réimporté (réservation épinglée deux fois au moteur).

## Corrections de la contre-revue à intégrer avant toute reprise

- Parcours d'extraction sur la **stack d'épreuve d'un worktree** (seed sans identités), jamais sur la copie
  principale.
- Garde d'hygiène sur le payload commité : **chaque chaîne** doit avoir une forme d'une liste fermée (libellé
  générique, id remappé, énumération, heure, date, `*`, catégorie), y compris `config`, `metadata` et `value`.
- Clés contrôlées **calculées** depuis la politique ; dictionnaires ouverts (`config`, `metadata`,
  `pendingConstraintSuggestion`) classés depuis ce que le builder émet vraiment ; couverture du schéma
  vérifiée dans les deux sens ; ids de contraintes composés traités.
- Un seul registre d'identifiants si des tests de transformation de payload voient le jour.
- Pas de test backend doublon du NR existant sur les réservations épinglées.
- Sortie d'une commande par argument (jamais `>` qui écraserait un payload valide sur refus) ; `make
  --no-print-directory`, `docker compose exec -T`.

## Questions encore ouvertes

1. Les équipes au nom hors catégorie (« Loisir 1 », « Baby 2 ») : « Équipe N », ou liste fermée de mots
   génériques gardés ?
2. Re-dériver le gros fichier de test du club pilote par l'outil (il porte vrais gymnases et identifiants) :
   dans le premier lot, ou plus tard ?
