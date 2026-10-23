# Module matchs (FFBB) — le périmètre engagé

> `TeamEngagementGuard` : ce qu'un match engage sur l'équipe et sur le gymnase. Découpé
> mécaniquement de [`module-matchs.md`](module-matchs.md) (ancien §11) — contenu déplacé
> VERBATIM, non réédité, plafond `DocSizeCeilingTest`.

## 11. Le périmètre engagé (`TeamEngagementGuard`)

Valider le planning valide aussi un périmètre : les équipes qui font de la compétition. **Engagée**
= porte au moins un `Fixture`, quel qu'en soit le statut (l'import crée tout en `UNPLACED` —
filtrer sur le statut serait inerte au moment précis où la garde doit mordre). Sur une équipe
engagée : suppression → **409** ; changement de `Team.level` par le **PUT générique** → **409, sans
exception sur ce chemin** (le niveau alimente la photo de structure d'une version validée) ;
nom/créneaux/gymnase/`isActive`/`priorityTierId` restent libres. La règle vit à un seul endroit
(`TeamEngagementGuard`, `TeamResource.isEngaged`) ; les purges de masse et le restore de structure
la contournent par construction — `StructureRestorer::assertRestoreKeepsEngagedTeams` refuse en 409
le chargement d'une version qui ne contiendrait pas une équipe engagée (avec son niveau).

**Exception nommée, un seul chemin** : le confirm d'appariement FFBB
(`POST /api/ffbb/engagements/confirm`) peut écrire `Team.level` sur une équipe engagée — « le niveau
d'une équipe JEUNE suit son engagement FFBB » (détail § « Engagements FFBB » ci-dessus) — mais
seulement sur clic explicite du gestionnaire, et toujours la valeur RE-DÉDUITE côté serveur (jamais
un niveau fourni par le client). La garde générique reste souveraine partout ailleurs, PUT compris.

**La salle d'un match est désormais protégée À LA SOURCE** (chantier A « fermetures à la source »,
décision fondateur 2026-10-22 — INVERSE ce qui suivait jusque-là) : un gymnase qui porte au moins
un match à domicile `PLACED`/`SUBMITTED`/`VALIDATED` ne peut plus être supprimé — refus 422 nommé
(`VenueStateProcessor::cascadeBeforeDelete`), AVANT toute cascade, zéro écriture ;
`DeletionImpactCounter::forVenue` annonce `blocked`+`reason` pour que l'écran grise la
confirmation plutôt que d'offrir un geste qui finirait en 422. Un `PUT /api/fixtures/{id}` qui
viderait `venueId` d'un tel match SANS le repasser `UNPLACED` dans le MÊME geste est refusé
pareillement (`FixtureStateProcessor`, 422) — le dé-placement normal (statut et salle vidés
ensemble) reste permis. `FixtureVenueLossStep` a quitté `CascadePlan` : la suppression d'un
gymnase ne dépointe plus aucun match (un gymnase SANS match reste supprimable, cascade inchangée).

Le foyer `FixtureVenueLossMarker` n'a donc plus qu'**une** gâchette : la VALIDATION d'un planning
(`GET /api/schedules/{id}/validate-impact` annonce `{orphanedFixtures, declaredOrphanedFixtures}`
avant confirmation ; `POST …/validate` dépointe exactement ce que l'impact a annoncé — même
prédicat `OrphanedFixtureFinder`, parité par construction). L'exploration (« Charger cette
version ») ne dépointe **toujours rien** — un match dont le gymnase est absent de la photo survit
intact, nommant un `venueId` transitoirement pendouillant, résorbé seulement à la validation
suivante. Le périmètre engagé résiste à cette gâchette (le match existe toujours après
dépointage, son équipe reste engagée) et, a fortiori, à la nouvelle garde de suppression (le
match garde sa salle, rien ne bouge).

