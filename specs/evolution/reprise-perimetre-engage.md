# Le planning de saison — la mémoire produit derrière P2-7 / P2-9bis

> **Ce que ce fichier est, depuis le 2026-07-31** : ce que la roadmap ne peut pas porter — le
> **verbatim du fondateur**, le **pourquoi métier**, les **décisions à ne pas re-poser** et les
> **leçons de méthode**. Ce n'est plus un cadrage : P2-7 et P2-9bis sont **livrés** (roadmap
> §Backlog).
>
> **Le comportement livré ne vit PAS ici** — il vit dans
> [`planning-lifecycle-validated.md`](../courantes/planning-lifecycle-validated.md) (cycle de vie,
> gardes, codes HTTP), [`adr-0002-pattern-plan.md`](../../docs/architecture/adr-0002-pattern-plan.md)
> (invariants) et [`module-matchs.md`](../courantes/module-matchs.md) (périmètre engagé). **Les
> dettes ouvertes ne vivent pas ici non plus** : elles sont en [`roadmap.md`](roadmap.md),
> et les recopier ferait exactement ce que la section « Méthode » ci-dessous interdit.

## La réalité du terrain (fondateur — à lire avant tout)

> « Le planning de saison est nécessaire à faire au plus tôt : la saison débute en
> septembre et les matchs avant octobre. Une fois que les matchs sont envoyés à la fédé,
> il n'est plus question de changer les équipes en compétition. **Ça n'arrive jamais**,
> ça remet tout le fonctionnement du club en question. Dans le workflow d'un club, le
> planning de la saison **ne change quasiment JAMAIS** — il s'ajuste dans de rares cas. »

**Conséquence de cadrage** : ce lot ne servait PAS à fluidifier un flux courant. Il servait à ce
que **le cas rare ne casse rien**. La confirmation forte n'est pas une friction à minimiser —
**c'est la fonctionnalité**.

**Le cas réel qui arrive** : le BCCL a un gymnase en construction. Quand il le récupère, le
planning de saison changera. Ce n'est pas hypothétique.

## Décisions à ne pas re-poser

| Question | Décision |
|---|---|
| Le niveau (`Team.level`) d'une équipe engagée | **Figé sans exception** dès l'engagement, y compris `null → REGIONAL`. Il se saisit AVANT de générer (tag NIVEAU → contraintes → photo de structure) ; le laisser bouger ferait diverger la photo et la base. Seule tolérance : un PUT qui ré-écho le **même** niveau |
| Le rang / tier | **Libre** — perception interne du club, ça bouge |
| `isActive` | **Libre** — « la désactivation concerne les overlays et les plannings de vacances » |
| Rouvrir supprime-t-il les matchs ? | **Non — abandonné le 2026-07-30.** Le cadrage prévoyait de supprimer les `UNPLACED` ; inutile, le module matchs est déjà inaccessible sans version pointée (`SocleGuard`). Les supprimer aurait imposé de refaire l'import FBI pour un résultat déjà acquis. La partie matchs fera l'objet d'une évolution dédiée |
| Valider une AUTRE version pendant que le socle est en vigueur | **Question sans objet depuis le 2026-07-30.** La création concurrente étant fermée, une seconde version de saison ne peut plus coexister avec la version pointée : le cas est inatteignable par construction, il n'a pas besoin de règle propre |
| Import FFBB qui change un niveau | Non traité, **volontairement** — « si je veux changer le niveau par import FFBB pour les matchs, je gérerai ce cas à ce moment-là » |

## Méthode — ce que ces lots ont coûté

La bascule ADR-0002 a demandé **4 rounds de revue et 40 défauts confirmés**. **Un seul motif** :
une garde, un compteur ou un sélecteur resté sur l'ancienne vérité. Deux endroits qui répondent à
la même question finissent **toujours** par répondre autre chose.

À garder pour la suite :

1. **Un test qui ne peut pas échouer ne prouve rien.** Désarmer la garde et vérifier que le test
   tombe. Trois défauts de la bascule ont survécu à des tests verts. *(Corollaire découvert le
   2026-07-30 : un test peut aussi passer pour la MAUVAISE raison — rejeté par une garde
   antérieure. Construire l'état pour que ce soit bien la garde visée qui morde, et asserter sur
   son message.)*
2. **Ne jamais écrire « vérifié » sur un balayage incomplet.** Le contournement de `wipeStructure`
   a été raté exactement comme ça.
3. **Ne pas assouplir une règle du fondateur sans demander.** Une entorse prise seul a fabriqué un
   bug, présenté ensuite comme une découverte.
4. **`cache:clear` avant `api:openapi:export`.** Un export sur cache périmé a produit un snapshot
   faux, committé après vérification du diff.
5. **Committer avant tout `git checkout`.** Trois tests non committés ont été perdus ainsi.
6. **Un test déclaré bloquant n'est pas un test bloquant** *(2026-07-30)*. Le job CI
   `blocking-tests` énumère **un step par fichier** : un test annoncé dans la liste canonique
   (`docs/testing/blocking-tests.md`, ex-`CLAUDE.md` §4) mais
   absent de `ci.yml` ne garde rien. Ajouter les deux **dans le même commit**.
7. **Ne pas patcher sous pression de revue, correctif par correctif** *(2026-07-30)*. Six
   correctifs posés isolément en ont produit trois nouveaux, dont un **pire que le défaut
   d'origine** — fermer une porte sans regarder ses voisines a transformé « Charger cette version »
   en cul-de-sac destructif. Quand une revue révèle un défaut de **conception**, s'arrêter et
   re-cadrer vaut mieux que corriger vite.
8. **Une relecture de soi-même ne vaut pas une revue extérieure** *(2026-07-30)*. Une revue inline
   a conclu « aucun finding rouge » sur une PR qui en contenait trois, dont un test bloquant qui
   ne bloquait rien. Sur un axe structurant, passer par `/code-review`.
