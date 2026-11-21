# Planning bac à sable : un socle d'essai indépendant

**Statut : besoin validé, direction tranchée, EN ATTENTE du lot conception** ([`lot-conception.md`](lot-conception.md), P1-6 : la structure attachée à la saison est le couplage à défaire d'abord) ; plan non commencé, `morpheus` non lancé. Matière de départ pour le cycle complet (`/plan` par `morpheus`, `smith` obligatoire : génération, isolation entre clubs, cycle de vie du planning, ADR-0002). Les citations `fichier:ligne` viennent du cadrage `oracle` du 2026-11-19 : à relire à la source au plan.

## Le besoin

Un gestionnaire veut essayer des organisations (exemple : un changement de coach) sans toucher au vrai planning. Aujourd'hui le seul chemin est de modifier la structure réelle, de rouvrir le socle (ce qui détruit les plans de période futurs), de générer une V2 puis de « Charger » l'ancienne version pour revenir. L'essai a un coût réel et modifie la structure vivante.

Le bac à sable est un **socle de saison indépendant, relié à rien**, qui part d'une copie du club actuel pour ne pas repartir de zéro. On y ajoute ou retire des équipes et des gymnases, on y génère, on y fait des versions : comme si l'on configurait le socle d'un autre club.

## Décisions du fondateur (2026-11-19)

1. **Direction A** : une copie complète de la structure (gymnases, créneaux, coachs, équipes, liens, règles) dans une saison d'essai à part, sur le modèle de « Préparer la saison suivante ». Écartée : B (type de plan SANDBOX avec surcharges sur la structure réelle), car elle ne sait ni créer ni retirer une équipe ou un gymnase.
2. **Un socle en vigueur est nécessaire** pour créer l'essai ; la copie est prise dessus et **figée** à la naissance (le réel change ensuite, l'essai ne suit pas).
3. **Un seul planning d'essai par saison**, avec plusieurs versions (générer, comparer, charger à l'intérieur de l'essai).
4. **Chaque génération consomme un crédit** (pool du club), sans autre plafond.
5. **Rien n'en sort** : ni export PDF/xlsx, ni report vers un plan réel, ni lien avec le réel. Écrit et assumé.
6. **Grisé au 1er mai**, à l'ouverture de « Préparer la saison suivante » (`specs/courantes/modules-produit.md` §4) : pour tester la saison suivante, on la prépare, et elle est payante. Les données sont gardées. La coupure est une règle **serveur** (génération et lecture), lue sur `ClubClock` ; le 1er mai n'est tenu côté serveur que pour la bascule de saison (`SeasonTransitionService::preparationOpensOn`), pas pour la génération ni la lecture. À confirmer au plan : « grisé » = lecture seule.
7. **Aucun envoi de mail** : l'essai n'a ni module matchs ni vacances (les conflits envoyés aux coachs et les doléances n'existent que là).

**Pourquoi le 1er mai** : sans coupure, un club qui ne renouvelle pas fabriquerait le planning de la saison suivante dans l'essai avant de partir. Effacer les données au 1er mai ne protège pas (l'export précède l'effacement) ; l'absence d'export et de report ferme le reste.

## Ce que le code fait (cadrage `oracle`)

- Types de plan : `SEASON`, `CLOSURE`, `HOLIDAY` (`backend/src/Enum/SchedulePlanType.php:15-17`). Le plan SEASON pointé est le calendrier (`SchedulePlan.php:31-35`).
- La structure appartient à la **saison**, pas au plan : `ScheduleConstraintBuilder::buildForClubSeason` (`ScheduleConstraintBuilder.php:251`), photo de structure sur 10 familles (`StructureSnapshotter.php:52-63`).
- Seul modèle de copie : `SeasonTransitionService::copy` (`SeasonTransitionService.php:157-216`), sans lien avec la source (`:39-40`) ; la bascule exige la saison suivante payée (`:100-102`) et n'ouvre qu'à partir du 1er mai (`:75-80`).
- Rail « travailler dans une autre saison » : en-tête `X-Season-Id` (`TenantFilterListener.php:96,263`, `seasonStore.ts:6`) ; `SeasonResolver::currentAmong` choisit la saison courante parmi **toutes** celles du club (`SeasonResolver.php:67-107`) ; 45 fichiers appellent `seasonsForClub`, `currentSeason` ou `selectedOrCurrent`.
- Crédits : pool commun du club (`PlanEntitlements.php:83,126`), consommés par `generate`, `regenerate`, le comblement et les exports (sorties déclarées, `backend/src/Access/Output.php`).

## Ce qui coince, à écrire au plan

1. **Une saison d'essai ne doit jamais devenir « la saison courante »** : chaque lecteur de « les saisons du club » (45 fichiers) est un oubli possible, donc une fuite ou un faux calendrier.
2. **La coupure de l'essai au 1er mai est à faire côté serveur** (la bascule de saison tient déjà la date) ; sinon le contournement passe par l'API.
3. **Copie sans lien d'origine** : acceptable ici, puisqu'il n'y a pas de report.
4. **Périmètre engagé** : une équipe copiée porte-t-elle son engagement ? Peut-on la supprimer dans l'essai ? À trancher.
5. **Les adresses mail des coachs copiés** : sans mail dans l'essai, rien ne part, mais le garde doit être posé côté serveur.

## Chemins d'abus à tester (isolation entre clubs, horloge)

1. Un gestionnaire du club A envoie `X-Season-Id` ou un identifiant de plan de l'essai du club B : 404 identique à celui d'un objet inexistant.
2. Une génération de l'essai ne lit que la structure de l'essai, jamais le réel ni un autre club.
3. Le 2 mai, génération ou lecture de l'essai par appel direct à l'API : refus serveur.
4. Un club démo pose une horloge simulée au 30 avril pour rouvrir son essai : la coupure lit `ClubClock`, seul un club démo peut simuler la date.

## Lignes voisines

- **P4-597** (une occupation de gymnase rattachée au plan d'une autre saison du même club, existence seule contrôlée) : **à embarquer**, un plan d'essai est « un plan d'une autre saison ».
- **P4-612** (variante de semaine, planning « avec événement ») : partage la notion de « planning sans date à côté du socle » et la difficulté ADR-0002. Décision de conception commune à prendre avant de planifier l'un des deux. Différence : la variante est un planning réel qu'on applique, l'essai ne l'est jamais.
- **P4-509** (la confirmation de « Charger cette version » n'annonce pas deux effets) : non embarquée, sauf si une PR touche cet écran.
- Hors sujet : mode démo libre-service, stacks de test de l'équipe (`with-sandbox`, épreuve), « Préparer la saison suivante » (modèle de copie, pas le besoin).

## Taille

L, 2 PR, 30 à 45 fichiers selon `oracle`. Axes §7.1 : isolation entre clubs, pipeline de génération, cycle de vie du planning, périmètre engagé. Contrat backend⇄engine : payload inchangé.
