# La FFBB comme source, le gestionnaire comme juge — reste ouvert

> **Ce fichier ne garde plus que l'OUVERT** (graduation DOC-56, 2026-10-06). Le besoin est **tranché et livré** : l'appariement engagements↔équipes et la résolution des gymnases nourrissent les paliers B/C (P3-7, P4-35 livrés/soldés). Le comportement vit dans [`module-matchs.md`](../courantes/module-matchs.md) ; les décisions fermées (« on accompagne, on ne décide pas » — écran d'arbitrage, aucun refus modélisé ; la page jamais vierge ; l'identité du club tranchée ; le forfait général P4-69 ; l'annuaire national non stocké) dans [`etat-des-lieux.md`](../courantes/etat-des-lieux.md) §2 ; le cadrage d'origine (mesures FFBB du second balayage, exploitation de l'API, onboarding express) dans `git log -p --follow` ce fichier. **Reste devant** : les **gymnases de match** (§7, besoin neuf non codé) et les questions résiduelles (§8) ; §6.8 est conservé parce que §7 s'y appuie.

### 6.8 🟢 Résoudre les GYMNASES — la trouvaille du second balayage

`ffbbserver_salles` est **peuplé** (5 000+) et porte, par salle : `numero` (**l'identifiant FFBB de la salle**),
`libelle`, `adresse`, `commune`, et `_geo`. Testé : `VILLEURBANNE` → 17 salles du 69100, dont **GYMNASE JEAN
VILAR** — un gymnase de BCCL. **L'index couvre donc aussi NOS salles**, pas seulement celles des adversaires.

⚠ La recherche plein texte seule ne suffit pas — « GYMNASE JEAN VILAR » rend **2 999 hits**. Il faut
**désambiguïser par le code postal** ; même règle qu'au §2, on filtre sur le champ.

**Trois usages, et le premier change une décision déjà prise :**

1. **Le trajet devient réel, plus une estimation.** L'export FBI porte une colonne **`Salle`**. Nom + commune →
   la salle exacte et ses coordonnées. **Ça périme la réserve du §6.5** : plus besoin d'approximer par
   l'adresse du club (dont la géoloc est d'ailleurs en `status: "draft"` côté FFBB, donc non validée).
2. **Nos propres gymnases se pré-remplissent.** `Venue.latitude`/`longitude` et `Venue.externalRef` **existent**
   (entité + DTO) mais **le front ne les envoie jamais** — ils sont morts. Le `numero` FFBB est exactement ce
   qu'attend `externalRef`, et il donne au **gymnase de match** (§7) son identité officielle, celle qu'on
   déclare à la ligue.
3. **Le jour où `rencontres` se remplira**, la salle est **embarquée dans le document de match** avec adresse et
   coordonnées — plus aucune résolution à faire.

---

## 7. Les gymnases de match — un besoin neuf, avec une collision

> **« Il faut que l'on définisse nos gymnases de match : tous les gymnases d'entraînement ne sont pas des
> gymnases de match. »** (fondateur, 2026-08-02)

**Rien n'existe.** `Venue` porte `isExternal`, `canSplit`, `isActive`, `parentVenueId`, `latitude/longitude` —
**aucun marqueur d'aptitude au match**. [P1-4 (4)](roadmap.md) l'avait noté en passant (« un gymnase n'accueille
pas forcément des matchs ») sans le spécifier.

⚑ **La collision, à traiter avant de coder** : le wizard **exige aujourd'hui au moins un créneau par gymnase**
(règle affichée sous le formulaire d'ajout, cf. [`frontend-wizard.md`](../../frontend/docs/frontend-wizard.md)). Or un
gymnase **réservé aux matchs** — une salle plus grande louée le week-end, par exemple — n'a **aucun créneau
d'entraînement**. La règle actuelle le refuserait, ou forcerait le gestionnaire à inventer un créneau fictif
qui partirait ensuite au solveur.

**Trois questions, à trancher ensemble :**

1. **Un attribut ou deux listes ?** `Venue.canHostMatches` sur l'entité existante, ou un gymnase de match est-il
   une ressource d'une autre nature ? *(L'attribut paraît suffisant et évite de dupliquer adresse/géoloc.)*
2. **Que devient la validation « ≥ 1 créneau » ?** Elle doit devenir « ≥ 1 créneau **ou** gymnase de match »,
   sans quoi la saisie est bloquée.
3. **Le solveur doit-il savoir qu'un créneau est mangé par un match ?** Un gymnase mixte accueillant un match
   le samedi ne peut pas accueillir l'entraînement en même temps — c'est un axe **constraint semantics**, donc
   un test de non-régression obligatoire le jour venu.

**Ce que le §6.8 apporte ici** : un gymnase de match a une **identité officielle** côté FFBB (`salles.numero`),
et c'est elle qu'on déclare à la ligue. `Venue.externalRef` l'attend déjà. Le résoudre à la saisie donne au
passage la géoloc, qui sert le trajet.

*(Piste écartée : `capaciteSpectateur` — un niveau peut exiger une capacité minimale, mais le champ est **vide**
sur l'échantillon lu. Non exploitable.)*

---

## 8. Reste ouvert

| # | Sujet |
|---|---|
| **8.1** | **Forfait général.** Les matchs sont perdus et n'ont plus à être gérés ; surtout, l'équipe **n'a potentiellement plus besoin de ses créneaux**, réallouables. ⚠ `EngagedTeamGuard` verrouille toute équipe **ayant des matchs** — une équipe en forfait en a. **Forfait ≠ désengagement dans notre modèle** : il faudra un troisième état. Axe *périmètre engagé* → NR obligatoire. **Réel, pas prioritaire** (fondateur). |
| **8.2** | **Correspondance saison** FFBB `26-27` ↔ notre pivot du 15 juillet : posée une fois, où ? |

> ✅ **Le point juridique est FERMÉ, pas supprimé** (décision fondateur, 2026-08-02) : **on ne stocke pas
> d'annuaire.** Chaque club consulte la FFBB **pour lui-même, à la demande** — c'est de la consommation par
> locataire, pas de l'extraction de base. Le risque que soulevait ce point (droit *sui generis* du producteur
> de base de données, art. L341-1 CPI : l'extraction **substantielle** est protégée même quand chaque donnée
> est publique) **naissait du stockage des 4 635 organismes**, pas de l'usage.
>
> ⚠ **Ce que ça interdit, et qu'il faudra rappeler le jour où l'idée reviendra** : constituer une base
> d'adversaires nationale, un fichier de prospection commerciale à partir des `dateAffiliation` /
> `labellisation` / `offresPratiques`, ou tout cache global qui survivrait à la requête d'un club. Le jour où
> l'un de ces trois usages est demandé, **ce point se rouvre** et exige un avis juridique — il n'est pas
> tranché « pour toujours », il est tranché **pour l'architecture actuelle**.
>
> Non concerné : le réhébergement du logo et l'identité du club **de ce club-là**, qui existent depuis le
> lot C. Et l'annuaire d'adversaires enrichi par l'usage de [`gestion-matchs-ffbb.md`](gestion-matchs-ffbb.md)
> §5bis reste possible — il naît de **ce que les clubs saisissent**, pas d'une extraction FFBB.
