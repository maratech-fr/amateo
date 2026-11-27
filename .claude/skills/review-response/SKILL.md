---
name: review-response
description: Protocole de réponse à une revue de code (findings d'un /code-review, /security-review ou revue humaine) — corriger la RÈGLE pas le cas, suivre les consommateurs, cadence des rounds avec GO fondateur. À charger AVANT de toucher au premier finding.
---

# Répondre à une revue — protocole

Pourquoi ce protocole : un round 2 hérite des erreurs du round 1 quand un finding est traité comme
**un cas** et non comme **l'exemple d'une règle**, et quand le correctif change ce qu'un écran dit
sans qu'on regarde qui d'autre en dépend — sur une PR mesurée, la moitié des défauts du round 2
étaient nés des correctifs du round 1.

1. **Corriger la règle, pas le cas.** Avant d'éditer, écrire la règle que le finding instancie,
   puis chercher TOUS ses sites (grep). Un correctif qui ne vaut qu'à la ligne citée en fabrique
   un autre ailleurs.
2. **Suivre les consommateurs.** Changer ce qu'un écran montre oblige à revérifier ce qui en
   dépend : le **verdict/gate** (`useStepValidation` & co — un écran qui compte autrement que sa
   porte, ce sont deux vérités), l'**export** (rendu serveur : il ne connaît aucun filtre client),
   l'**état vide**, les **libellés**. Les pires défauts d'une revue ne sont pas dans le diff :
   ils sont dans ce que le diff rend faux ailleurs.
3. **Masquer n'est légitime que pour un CHOIX.** Un sélecteur n'offre que l'actif ; un **libellé**
   (et la valeur courante d'un formulaire d'édition) se lit toujours sur la liste complète ; un
   **geste correctif** reste atteignable — mais fermé au geste fautif. Et jamais masquer ce qu'un
   export contient : on l'annonce.
4. **Charger ≠ échouer.** `readState` a trois états (`shared/lib/readState.ts`). Replier `loading`
   sur `failed` fait crier « n'a pas pu être lu » en régime normal — et un bandeau d'alerte qui se
   déclenche à chaque ouverture n'alerte plus de rien.
5. **Chaque règle neutralisée doit faire rougir son test.** Commiter AVANT la falsification
   (`git checkout --` efface le non-commité en silence). Un test d'écran qui mocke le hook porteur
   ne garde que le câblage : extraire la règle en fonction pure, ou monter le hook sur un vrai
   `QueryClient` en ne mockant que la couche API (module VOISIN — le mock ESM n'intercepte pas les
   appels intra-module).
6. **Cadence** : round 1 = `/code-review` lancé par le fondateur seul, `/security-review`
   systématique sur la liste fermée (CLAUDE.md §7 étape 7) ; **tout round suivant exige le GO du fondateur** ; plafond
   4 rounds/PR. Un défaut réel hors périmètre ne se corrige pas dans la PR : on PROPOSE au fondateur
   une ligne de dette (problème, preuve `fichier:ligne`, seau visé) — l'ouverture d'une ligne de
   roadmap demande son GO (`CLAUDE.md` §7).

Cadrage : une revue porte sur **le périmètre de la PR**.
