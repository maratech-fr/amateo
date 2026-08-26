<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\CalendarEntry;
use App\Entity\Fixture;
use App\Entity\Schedule;
use App\Entity\Season;
use App\Enum\ScheduleStatus;
use App\Service\FixtureVenueLossMarker;
use App\Service\ManagementAccessGuard;
use App\Service\OrphanedFixtureFinder;
use App\Service\OverlayManager;
use App\Service\ScheduleCapabilityResolver;
use App\Service\SchedulePlanProvisioner;
use App\Service\WriteTargetSeasonResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

/**
 * Choose a COMPLETED version: the manager settles on it and the plan POINTS at
 * it (ADR-0002 inv. 1). To edit again, reopen it (ReopenScheduleController).
 *
 * "Validated" is not a status — it is derived from the pointer, which is the
 * single truth. Choosing a version also DELETES its siblings of the same scope
 * (season versions share calendarEntryId=null, a period's versions share that
 * period's id): the plan holds the one version that counts, not a graveyard.
 * Overlays are never touched by a season-plan choice. A sibling still
 * generating blocks the choice (409) — a running solve cannot be deleted out
 * from under the worker.
 */
final class ValidateScheduleController extends AbstractController implements SeasonScopedWriteInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RequestStack $requestStack,
        private readonly ManagementAccessGuard $managementAccessGuard,
        private readonly OverlayManager $overlayManager,
        private readonly SchedulePlanProvisioner $schedulePlanProvisioner,
        private readonly ScheduleCapabilityResolver $capabilityResolver,
        private readonly WriteTargetSeasonResolver $writeTargetSeasonResolver,
        private readonly OrphanedFixtureFinder $orphanedFixtureFinder,
        private readonly FixtureVenueLossMarker $fixtureVenueLossMarker,
    ) {}

    // SEC-13 — la cible est le Schedule nommé dans l'URL (id de version).
    public function writeTargetSeasonId(Request $request): ?string
    {
        $id = $request->attributes->get('id');

        return \is_string($id) ? $this->writeTargetSeasonResolver->ofSchedule($id) : null;
    }

    #[Route('/api/schedules/{id}/validate', name: 'api_schedule_validate', methods: ['POST'])]
    public function __invoke(string $id): JsonResponse
    {
        $this->managementAccessGuard->assertManager(); // SEC-07

        try {
            $schedule = $this->entityManager->getRepository(Schedule::class)->find($id);
        } catch (Throwable) {
            $schedule = null;
        }

        if (!$schedule instanceof Schedule) {
            return $this->json(['error' => 'Planning introuvable.'], Response::HTTP_NOT_FOUND);
        }

        $currentClubId = $this->resolveCurrentClubId();
        if (null !== $currentClubId && $schedule->getClubId() !== $currentClubId) {
            return $this->json(['error' => 'Accès refusé.'], Response::HTTP_FORBIDDEN);
        }

        // Note : le statut est RE-VÉRIFIÉ sous le verrou (voir plus bas). Ce pré-contrôle
        // n'existe que pour rendre un 409 franc sans ouvrir de transaction.
        if (ScheduleStatus::COMPLETED !== $schedule->getStatus()) {
            return $this->json(['error' => 'Seul un planning terminé peut être validé.'], Response::HTTP_CONFLICT);
        }

        // TOUT ce qui décide vit sous le verrou de portée du plan, DANS la transaction :
        // `pg_advisory_xact_lock` n'existe qu'en transaction, et lire les sœurs ou le
        // pointeur avant de le prendre laisse deux validations concurrentes décider sur
        // la même photo. Avant la bascule, cette course ne faisait que mal poser deux
        // statuts, et ARCHIVED rendait tout récupérable. Désormais les sœurs sont
        // SUPPRIMÉES : deux validations simultanées (deux onglets, un double-clic)
        // détruiraient DÉFINITIVEMENT toutes les versions de la saison et laisseraient
        // `chosen_schedule_id` nommer une ligne morte.
        //
        // La portée est celle du linkSchedule : les versions de saison partagent
        // `season:{id}`, celles d'une période l'id de cette période — sérialiser sur une
        // autre clé ne protégerait rien.
        //
        // ADR-0002 C4 : l'entrée-période se dérive du PLAN (plan.calendarEntryId), plus de
        // schedule.calendarEntryId (redondant, même valeur). periodEntryIdOf LÈVE si le
        // schedule n'a pas de plan (ruling 2026-07-17) : un schedule sans plan ne se fait
        // JAMAIS passer pour le socle — l'ancien `null === calendarEntryId` l'y faisait
        // retomber, et valider ce faux-socle aurait supprimé TOUTES les versions de la saison.
        $entryId = $this->schedulePlanProvisioner->periodEntryIdOf($schedule);
        $planId = $schedule->getSchedulePlanId(); // non-null ici : periodEntryIdOf a levé sinon

        return $this->entityManager->wrapInTransaction(function () use ($schedule, $entryId, $planId): JsonResponse {
            $this->schedulePlanProvisioner->lockPlanScope($entryId ?? SchedulePlanProvisioner::seasonScopeKey($schedule->getSeasonId()));

            // RELIRE la version elle-même sous le verrou. Sérialiser le scan des sœurs
            // ne suffit pas : l'entité en mémoire a été chargée AVANT le verrou, et la
            // requête qui vient de commiter a pu la supprimer (c'était une de SES sœurs)
            // ou la faire repartir en solve. Sans ça, la seconde validation pointerait le
            // plan sur une ligne MORTE — `chosen_schedule_id` est une colonne guid nue,
            // aucune FK ne l'arrête — puis supprimerait la survivante : zéro version,
            // pointeur fantôme, club renvoyé au wizard avec ses matchs.
            //
            // La relecture se fait en SQL, pas via refresh()/find() : `refresh()` sur une
            // ligne disparue ne lève RIEN (il SELECTe puis hydrate zéro ligne — voir
            // BasicEntityPersister::refresh) et laisse l'entité MANAGED avec son état
            // d'avant le verrou ; `find()` la rendrait depuis l'identity map sans toucher
            // la base. Les deux répondraient « COMPLETED » sur une version morte.
            $fresh = $this->entityManager->getConnection()->fetchOne(
                'SELECT status FROM schedule WHERE id = :id',
                ['id' => $schedule->getId()],
            );
            if (false === $fresh) {
                return $this->json(['error' => 'Cette version n\'existe plus — rechargez le planning.'], Response::HTTP_CONFLICT);
            }
            if (ScheduleStatus::COMPLETED->value !== $fresh) {
                return $this->json(['error' => 'Cette version a changé entre-temps — rechargez le planning.'], Response::HTTP_CONFLICT);
            }

            // Les versions sœurs de MÊME portée = MÊME plan (C4 : schedulePlanId, plus
            // calendarEntryId). Une sœur en cours de solve bloque : on ne supprime pas un
            // planning sous les pieds du worker qui l'écrit.
            /** @var list<Schedule> $versions */
            $versions = $this->entityManager->getRepository(Schedule::class)->findBy([
                'clubId' => $schedule->getClubId(),
                'seasonId' => $schedule->getSeasonId(),
                'schedulePlanId' => $planId,
            ]);
            $siblings = [];
            foreach ($versions as $sibling) {
                if ($sibling->getId() === $schedule->getId()) {
                    continue;
                }
                if ($this->capabilityResolver->isInFlight($sibling)) {
                    return $this->json(['error' => 'Une autre version est en cours de génération — attendez sa fin avant de valider.'], Response::HTTP_CONFLICT);
                }
                $siblings[] = $sibling;
            }

            $season = $this->entityManager->getRepository(Season::class)->find($schedule->getSeasonId());

            // Garde destructive (même idiome que ReopenScheduleController) : choisir une
            // AUTRE version déplace le calendrier de base, ce qui invalide les plans
            // secondaires bâtis sur l'ancien socle. Clé sur le POINTEUR — la seule
            // vérité (inv. 1/14) — et jamais composer silencieusement un ajustement
            // par-dessus un autre plan de base.
            // Un pointeur NULL n'exempte pas : le plan est alors un espace de travail, mais
            // des plans secondaires peuvent survivre (socle rouvert, donnée migrée). Choisir
            // cette version leur donnerait un autre socle que celui sur lequel ils ont été
            // bâtis — silencieusement. La seule question est « le plan pointe-t-il DÉJÀ cette
            // version ? » ; sinon le calendrier bouge, et il faut le confirmer.
            // (Cas normal : à la 1re validation aucun plan secondaire n'existe — inv. 13 les
            // interdit sans socle pointé — donc la garde ne coûte rien.)
            $currentlyChosen = $this->schedulePlanProvisioner->chosenOfSeasonPlan($schedule->getSeasonId());
            $overlaysToDelete = [];
            if (null === $entryId && $schedule->getId() !== $currentlyChosen) {
                $overlaysToDelete = $this->overlayManager->periodPlansInvalidatedBySeasonChange($schedule->getClubId(), $schedule->getSeasonId());
                if ([] !== $overlaysToDelete && !$this->confirmedDeleteOverlays()) {
                    return $this->json([
                        'code' => 'overlays_exist',
                        'error' => 'Choisir cette version remplace le planning de la saison : les plannings de période à venir sont supprimés et devront être refaits.',
                        'count' => \count($overlaysToDelete),
                        'overlays' => array_map(static fn (CalendarEntry $e): array => [
                            'entryId' => $e->getId(),
                            'title' => $e->getTitle(),
                        ], $overlaysToDelete),
                    ], Response::HTTP_CONFLICT);
                }
            }

            // Suppression des plans secondaires + pointeur + suppression des versions
            // sœurs commitent ensemble (un échec en cours de route ne doit pas laisser
            // un plan à moitié basculé).
            foreach ($overlaysToDelete as $entry) {
                // Le PLAN entier, pas seulement ses versions : le socle est la base dont
                // la grille d'une période est copiée, la déplacer périme cette copie
                // (décision fondateur 2026-07-24). force : destruction confirmée.
                $this->overlayManager->deletePeriodPlanForEntry($entry, force: true);
            }

            // ADR-0002 inv. 1 — VALIDER = POINTER. Seule vérité : « validé » se dérive
            // du pointeur, il n'y a plus de statut pour le dire.
            if (!$this->schedulePlanProvisioner->choose($schedule)) {
                throw new ConflictHttpException('Cette version n\'est rattachée à aucun planning — impossible de la choisir.');
            }

            // La ★ (photo chargée) peut être posée sur une sœur qu'on s'apprête à
            // supprimer : la repointer sur la version choisie, dont la photo devient
            // la vérité (inv. 17 — la ★ reste, c'est l'auto-POINTEUR qui est mort).
            if ($season instanceof Season && null !== $season->getLiveContextScheduleId()) {
                foreach ($siblings as $sibling) {
                    if ($sibling->getId() === $season->getLiveContextScheduleId()) {
                        $season->setLiveContextScheduleId($schedule->getId());
                        break;
                    }
                }
            }

            // Le plan de la période pointe déjà sa version choisie : choose() ci-dessus
            // a posé chosenScheduleId sur le plan (SEASON ou période), source unique de
            // la version active depuis le lot D-b — plus de pointeur inverse sur l'entrée.

            // inv. 1 : les versions non choisies sont SUPPRIMÉES (plus de filet
            // ARCHIVED). Les pointeurs ont tous été déplacés sur la gagnante ci-dessus.
            foreach ($siblings as $sibling) {
                $this->overlayManager->deleteVersion($sibling);
            }

            // P2-52 (RMM-10) — LA VALIDATION EST LA GÂCHETTE. Un match pointant une salle
            // disparue (gymnase supprimé, ou pointeur laissé pendouillant par une exploration
            // « Charger cette version ») est dépointé ICI, à parité stricte avec la route
            // d'annonce `validate-impact` (MÊME prédicat, `OrphanedFixtureFinder`). N=0 →
            // `mark([])` ne touche RIEN, la validation reste byte-identique à hier. N>0 → les
            // matchs repassent « à placer » avec la raison persistante venue_lost, heure conservée.
            $orphanedIds = array_map(
                static fn (Fixture $fixture): string => $fixture->getId(),
                $this->orphanedFixtureFinder->orphanedFixtures($schedule->getClubId(), $schedule->getSeasonId()),
            );
            $this->fixtureVenueLossMarker->mark($orphanedIds);

            return $this->json(['id' => $schedule->getId(), 'chosen' => true], Response::HTTP_OK);
        });
    }

    private function confirmedDeleteOverlays(): bool
    {
        $request = $this->requestStack->getCurrentRequest();
        $content = $request?->getContent();
        if (!\is_string($content) || '' === $content) {
            return false;
        }
        $data = json_decode($content, true);

        return \is_array($data) && true === ($data['confirmDeleteOverlays'] ?? false);
    }

    private function resolveCurrentClubId(): ?string
    {
        $request = $this->requestStack->getCurrentRequest();

        $clubId = $request?->attributes->get('_club_id');
        if (\is_string($clubId) && '' !== $clubId) {
            return $clubId;
        }

        $clubId = $request?->headers->get('X-Club-Id');
        if (\is_string($clubId) && '' !== $clubId) {
            return $clubId;
        }

        return null;
    }
}
