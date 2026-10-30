<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Reservation;
use App\Entity\Schedule;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\Season;
use App\Entity\SharedTrainingBlock;
use App\Entity\SharedTrainingBlockTeam;
use App\Entity\Sport;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Entity\TeamPeriodOverride;
use App\Entity\User;
use App\Entity\Venue;
use App\Entity\VenueTrainingSlot;
use App\Enum\LockLevel;
use App\Enum\LockOrigin;
use App\Enum\ScheduleStatus;
use App\Enum\SeasonStatus;
use App\Service\ReservationGroupOccupancy;
use App\Service\ClubGenerationLock;
use App\Service\MutualizeSlotService;
use App\Service\SchedulePlanProvisioner;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\CreatesPeriodPlanTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Lot 9 — le rail `POST /api/schedule-slots/{id}/mutualize` : depuis la fiche d'une séance, un
 * bloc de mutualisation est déclaré et ANCRÉ à la case, EN PLACE sur un plan de période, sans
 * verdict moteur. On prouve le geste heureux (bloc + verrous HARD/MANUAL co-localisés, activation
 * d'une équipe à 0 séance) ET chaque refus NOMMÉ / abus (403, 404, 409, 422).
 */
#[Group('phase1')]
#[Group('integration')]
final class MutualizeSlotApiTest extends WebTestCase
{
    use ChoosesPlanVersionTrait;
    use CreatesPeriodPlanTrait;
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    /** Geste heureux — deux équipes déjà placées : bloc créé (nom posé), les deux séances déplacées+verrouillées sur la case. */
    public function testMutualizesTwoPlacedTeamsAnchoredAndLocked(): void
    {
        $ctx = $this->seedPeriod('a', capacity: 2);
        $source = $this->placeSlot($ctx, $ctx['teams']['source'], $ctx['case']);
        // Le joueur « joiner » a sa propre séance AILLEURS (mardi), remplacée par la commune.
        $joinerSlot = $this->placeSlot($ctx, $ctx['teams']['joiner'], ['venueId' => $ctx['venueId'], 'dayOfWeek' => 3, 'startTime' => '18:00']);

        $this->client->request('POST', '/api/schedule-slots/' . $source->getId() . '/mutualize', [], [], $this->managerHeaders($ctx), json_encode([
            'teamIds' => [$ctx['teams']['source'], $ctx['teams']['joiner']],
            'label' => 'Baby U7-U9',
            'replacedSlotIds' => [$joinerSlot->getId()],
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(200);

        $this->em->clear();
        $this->scopeGucToClub($ctx['clubId']);
        $block = $this->em->getRepository(SharedTrainingBlock::class)->findOneBy(['clubId' => $ctx['clubId'], 'schedulePlanId' => $ctx['planId']]);
        self::assertInstanceOf(SharedTrainingBlock::class, $block);
        self::assertSame(1, $block->getCommonSessions());
        self::assertSame('Baby U7-U9', $block->getLabel());
        self::assertCount(2, $this->em->getRepository(SharedTrainingBlockTeam::class)->findBy(['blockId' => $block->getId()]));

        // Les deux séances co-localisées sur la case, verrouillées HARD/MANUAL ET LIÉES au bloc.
        foreach ([$source->getId(), $joinerSlot->getId()] as $slotId) {
            $slot = $this->em->getRepository(ScheduleSlotTemplate::class)->find($slotId);
            self::assertInstanceOf(ScheduleSlotTemplate::class, $slot);
            self::assertSame($ctx['venueId'], $slot->getVenueId());
            self::assertSame(2, $slot->getDayOfWeek());
            self::assertSame('18:00', $slot->getStartTime()->format('H:i'));
            self::assertSame(LockLevel::HARD, $slot->getLockLevel());
            self::assertSame(LockOrigin::MANUAL, $slot->getLockOrigin());
            self::assertSame($block->getId(), $slot->getSharedTrainingBlockId(), 'la séance de groupe est liée à son bloc (option B)');
        }
    }

    /** Équipe rejoignante à 0 séance : activée dans le plan (1 séance, source=mutualisation) + séance neuve verrouillée sur la case. */
    public function testActivatesAZeroSessionTeamMarkedMutualisation(): void
    {
        $ctx = $this->seedPeriod('b', capacity: 2);
        $source = $this->placeSlot($ctx, $ctx['teams']['source'], $ctx['case']);
        // Le joiner n'a AUCUNE séance dans ce planning → activation.

        $this->client->request('POST', '/api/schedule-slots/' . $source->getId() . '/mutualize', [], [], $this->managerHeaders($ctx), json_encode([
            'teamIds' => [$ctx['teams']['source'], $ctx['teams']['joiner']],
            'replacedSlotIds' => [],
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(200);
        $body = $this->responseData();
        self::assertSame([$ctx['teams']['joiner']], $body['activatedTeamIds']);

        $this->em->clear();
        $this->scopeGucToClub($ctx['clubId']);
        $override = $this->em->getRepository(TeamPeriodOverride::class)->findOneBy(['schedulePlanId' => $ctx['planId'], 'teamId' => $ctx['teams']['joiner']]);
        self::assertInstanceOf(TeamPeriodOverride::class, $override);
        self::assertTrue($override->isActive());
        self::assertSame(1, $override->getSessionsPerWeek());
        self::assertSame(MutualizeSlotService::OVERRIDE_SOURCE, $override->getSource());

        // Une séance NEUVE verrouillée pour le joiner sur la case, liée au bloc.
        $block = $this->em->getRepository(SharedTrainingBlock::class)->findOneBy(['clubId' => $ctx['clubId'], 'schedulePlanId' => $ctx['planId']]);
        self::assertInstanceOf(SharedTrainingBlock::class, $block);
        $created = $this->em->getRepository(ScheduleSlotTemplate::class)->findBy(['scheduleId' => $ctx['scheduleId'], 'teamId' => $ctx['teams']['joiner']]);
        self::assertCount(1, $created);
        self::assertSame(LockLevel::HARD, $created[0]->getLockLevel());
        self::assertSame($ctx['venueId'], $created[0]->getVenueId());
        self::assertSame('18:00', $created[0]->getStartTime()->format('H:i'));
        self::assertSame($block->getId(), $created[0]->getSharedTrainingBlockId());
    }

    public function testNonManagerRefused(): void
    {
        $ctx = $this->seedPeriod('c', capacity: 2);
        $source = $this->placeSlot($ctx, $ctx['teams']['source'], $ctx['case']);
        $member = $this->createMember($ctx['clubId'], 'editor');

        $this->client->request('POST', '/api/schedule-slots/' . $source->getId() . '/mutualize', [], [], $this->authHeaders($member) + ['CONTENT_TYPE' => 'application/json'], json_encode([
            'teamIds' => [$ctx['teams']['source'], $ctx['teams']['joiner']], 'replacedSlotIds' => [],
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(403);
        self::assertCount(0, $this->em->getRepository(SharedTrainingBlock::class)->findBy(['clubId' => $ctx['clubId']]));
    }

    public function testValidatedPlanRefused(): void
    {
        $ctx = $this->seedPeriod('d', capacity: 2);
        $source = $this->placeSlot($ctx, $ctx['teams']['source'], $ctx['case']);
        // Pointer la version du plan de période = « validé » (lecture seule).
        $this->scopeGucToClub($ctx['clubId']);
        self::getContainer()->get(SchedulePlanProvisioner::class)->choose($ctx['schedule']);
        $this->em->flush();

        $this->client->request('POST', '/api/schedule-slots/' . $source->getId() . '/mutualize', [], [], $this->managerHeaders($ctx), json_encode([
            'teamIds' => [$ctx['teams']['source'], $ctx['teams']['joiner']], 'replacedSlotIds' => [],
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(409);
    }

    public function testGenerationInProgressRefused(): void
    {
        $ctx = $this->seedPeriod('e', capacity: 2);
        $source = $this->placeSlot($ctx, $ctx['teams']['source'], $ctx['case']);
        $lock = self::getContainer()->get(ClubGenerationLock::class);
        $token = $lock->acquire($ctx['clubId'], 60);
        self::assertIsString($token);

        try {
            $this->client->request('POST', '/api/schedule-slots/' . $source->getId() . '/mutualize', [], [], $this->managerHeaders($ctx), json_encode([
                'teamIds' => [$ctx['teams']['source'], $ctx['teams']['joiner']], 'replacedSlotIds' => [],
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(409);
            self::assertSame('generation_in_progress', $this->responseData()['code'] ?? null);
        } finally {
            $lock->release($ctx['clubId'], $token);
        }
    }

    /** Case pleine : capacité 1 + une équipe NON-membre déjà posée à la case → 422. */
    public function testFullCaseRefused(): void
    {
        $ctx = $this->seedPeriod('f', capacity: 1);
        $source = $this->placeSlot($ctx, $ctx['teams']['source'], $ctx['case']);
        $intruder = $this->createTeam($ctx, 'Intrus', 2, true);
        $this->placeSlot($ctx, $intruder, $ctx['case']); // non-membre, occupe déjà la case

        $this->client->request('POST', '/api/schedule-slots/' . $source->getId() . '/mutualize', [], [], $this->managerHeaders($ctx), json_encode([
            'teamIds' => [$ctx['teams']['source'], $ctx['teams']['joiner']], 'replacedSlotIds' => [],
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);
        self::assertCount(0, $this->em->getRepository(SharedTrainingBlock::class)->findBy(['clubId' => $ctx['clubId']]));
    }

    /** Σ garde falsifiée : le joiner (2 séances/semaine) est déjà dans un bloc de 2 séances → +1 = 3 > 2 → 422. */
    public function testSumOverBudgetRefused(): void
    {
        $ctx = $this->seedPeriod('g', capacity: 2);
        $source = $this->placeSlot($ctx, $ctx['teams']['source'], $ctx['case']);
        $joinerSlot = $this->placeSlot($ctx, $ctx['teams']['joiner'], ['venueId' => $ctx['venueId'], 'dayOfWeek' => 3, 'startTime' => '18:00']);

        // Un AUTRE bloc de la même portée consommant déjà les 2 séances du joiner : +1 dépasse.
        $other = $this->createTeam($ctx, 'Autre', 2, true);
        $this->declareBlock($ctx, [$ctx['teams']['joiner'], $other], 2);

        $this->client->request('POST', '/api/schedule-slots/' . $source->getId() . '/mutualize', [], [], $this->managerHeaders($ctx), json_encode([
            'teamIds' => [$ctx['teams']['source'], $ctx['teams']['joiner']], 'replacedSlotIds' => [$joinerSlot->getId()],
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);
    }

    /**
     * Lot 2 (2026-10-13) — le socle est AUTORISÉ : l'ancre est une séance d'un planning de SAISON,
     * deux équipes DÉJÀ placées. Le bloc (et ses membres) naît à PORTÉE NULL — comme les blocs de
     * saison que `copySocleSharedBlocks` recopie ensuite vers les fermetures —, séances co-localisées
     * HARD/MANUAL et liées au bloc, exactement comme sur un plan de période.
     */
    public function testMutualizesAtSeasonBasePlanWithNullScope(): void
    {
        $ctx = $this->seedSeason('h');
        $source = $this->placeSlot($ctx, $ctx['teams']['source'], $ctx['case']);
        $joinerSlot = $this->placeSlot($ctx, $ctx['teams']['joiner'], ['venueId' => $ctx['venueId'], 'dayOfWeek' => 3, 'startTime' => '18:00']);

        $this->client->request('POST', '/api/schedule-slots/' . $source->getId() . '/mutualize', [], [], $this->managerHeaders($ctx), json_encode([
            'teamIds' => [$ctx['teams']['source'], $ctx['teams']['joiner']],
            'label' => 'Socle U11',
            'replacedSlotIds' => [$joinerSlot->getId()],
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(200);

        $this->em->clear();
        $this->scopeGucToClub($ctx['clubId']);
        // PORTÉE NULL : le bloc du socle n'est rattaché à aucun plan de période.
        $block = $this->em->getRepository(SharedTrainingBlock::class)->findOneBy(['clubId' => $ctx['clubId'], 'schedulePlanId' => null]);
        self::assertInstanceOf(SharedTrainingBlock::class, $block);
        self::assertNull($block->getSchedulePlanId(), 'au socle, le bloc vit à portée NULL');
        self::assertSame(1, $block->getCommonSessions());
        self::assertSame('Socle U11', $block->getLabel());
        $members = $this->em->getRepository(SharedTrainingBlockTeam::class)->findBy(['blockId' => $block->getId()]);
        self::assertCount(2, $members);
        foreach ($members as $member) {
            self::assertNull($member->getSchedulePlanId(), 'les membres du bloc de socle vivent aussi à portée NULL');
        }

        foreach ([$source->getId(), $joinerSlot->getId()] as $slotId) {
            $slot = $this->em->getRepository(ScheduleSlotTemplate::class)->find($slotId);
            self::assertInstanceOf(ScheduleSlotTemplate::class, $slot);
            self::assertSame($ctx['venueId'], $slot->getVenueId());
            self::assertSame(2, $slot->getDayOfWeek());
            self::assertSame('18:00', $slot->getStartTime()->format('H:i'));
            self::assertSame(LockLevel::HARD, $slot->getLockLevel());
            self::assertSame(LockOrigin::MANUAL, $slot->getLockOrigin());
            self::assertSame($block->getId(), $slot->getSharedTrainingBlockId());
        }
    }

    /**
     * Lot 2 — l'unique restriction du socle : activer une équipe à 0 séance est propre aux plans de
     * période. Au socle, une équipe rejoignante sans aucune séance est un REFUS 422 NOMMÉ (rien
     * n'est écrit). L'UI ne propose déjà pas ces équipes au socle ; ce 422 est le filet serveur.
     */
    public function testSeasonBasePlanZeroSessionActivationRefused(): void
    {
        $ctx = $this->seedSeason('h0');
        $source = $this->placeSlot($ctx, $ctx['teams']['source'], $ctx['case']);
        // Le joiner n'a AUCUNE séance dans ce planning de saison → activation interdite au socle.

        $this->client->request('POST', '/api/schedule-slots/' . $source->getId() . '/mutualize', [], [], $this->managerHeaders($ctx), json_encode([
            'teamIds' => [$ctx['teams']['source'], $ctx['teams']['joiner']], 'replacedSlotIds' => [],
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Au planning de saison, on ne peut pas mutualiser une équipe qui n\'a aucune séance', (string) ($this->responseData()['error'] ?? ''));
        self::assertCount(0, $this->em->getRepository(SharedTrainingBlock::class)->findBy(['clubId' => $ctx['clubId']]), 'rien n\'est écrit : aucun bloc, aucun override');
        self::assertCount(0, $this->em->getRepository(TeamPeriodOverride::class)->findBy(['clubId' => $ctx['clubId']]));
    }

    /** Ancre d'un AUTRE club : le filtre tenant la rend invisible → 404. */
    public function testForeignAnchorSlotNotFound(): void
    {
        $ctx = $this->seedPeriod('i', capacity: 2);
        $foreignSlot = $this->placeSlot($ctx, $ctx['teams']['source'], $ctx['case']);
        $other = $this->seedPeriod('j', capacity: 2); // un autre club

        // Une requête RÉELLE part d'un cache d'identité VIDE : sans ce clear, `find()` servirait la
        // séance de l'autre club depuis la map (court-circuitant le filtre tenant / RLS) et le geste
        // partirait au lieu de 404. On rejoue la requête à froid, comme en production.
        $this->em->clear();

        $this->client->request('POST', '/api/schedule-slots/' . $foreignSlot->getId() . '/mutualize', [], [], $this->managerHeaders($other), json_encode([
            'teamIds' => [$other['teams']['source'], $other['teams']['joiner']], 'replacedSlotIds' => [],
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(404);
    }

    /** Un bloc portant EXACTEMENT ces équipes existe déjà pour cette portée → 422. */
    public function testSameTeamSetAlreadyDeclaredRefused(): void
    {
        $ctx = $this->seedPeriod('k', capacity: 2);
        $source = $this->placeSlot($ctx, $ctx['teams']['source'], $ctx['case']);
        $this->declareBlock($ctx, [$ctx['teams']['source'], $ctx['teams']['joiner']], 1);

        $this->client->request('POST', '/api/schedule-slots/' . $source->getId() . '/mutualize', [], [], $this->managerHeaders($ctx), json_encode([
            'teamIds' => [$ctx['teams']['source'], $ctx['teams']['joiner']], 'replacedSlotIds' => [],
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);
        self::assertCount(1, $this->em->getRepository(SharedTrainingBlock::class)->findBy(['clubId' => $ctx['clubId']]), 'aucun second bloc identique');
    }

    /**
     * B2 — supprimer le bloc (DELETE) emporte SES séances de groupe LIÉES (FK ON DELETE CASCADE) ;
     * une séance NON liée d'un membre ailleurs ne bouge jamais (exemple fondateur U11M1 jeudi).
     */
    public function testDeletingTheBlockRemovesLinkedGroupSessionsButKeepsUnlinked(): void
    {
        $ctx = $this->seedPeriod('l', capacity: 2);
        $source = $this->placeSlot($ctx, $ctx['teams']['source'], $ctx['case']);
        $joinerSlot = $this->placeSlot($ctx, $ctx['teams']['joiner'], ['venueId' => $ctx['venueId'], 'dayOfWeek' => 3, 'startTime' => '18:00']);
        // Une séance NON liée de la source, ailleurs (jeudi) : elle survit à la suppression du bloc.
        $unlinked = $this->placeSlot($ctx, $ctx['teams']['source'], ['venueId' => $ctx['venueId'], 'dayOfWeek' => 4, 'startTime' => '18:00']);

        $this->client->request('POST', '/api/schedule-slots/' . $source->getId() . '/mutualize', [], [], $this->managerHeaders($ctx), json_encode([
            'teamIds' => [$ctx['teams']['source'], $ctx['teams']['joiner']], 'replacedSlotIds' => [$joinerSlot->getId()],
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(200);
        $blockId = $this->responseData()['blockId'];

        $this->client->request('DELETE', '/api/shared_training_blocks/' . $blockId, [], [], $this->authHeaders($ctx['user']));
        self::assertResponseStatusCodeSame(204);

        $this->em->clear();
        $this->scopeGucToClub($ctx['clubId']);
        self::assertNull($this->em->getRepository(SharedTrainingBlock::class)->find($blockId));
        self::assertNull($this->em->getRepository(ScheduleSlotTemplate::class)->find($source->getId()), 'la séance source liée part avec le bloc');
        self::assertNull($this->em->getRepository(ScheduleSlotTemplate::class)->find($joinerSlot->getId()), 'la séance remplacée liée part avec le bloc');
        self::assertNotNull($this->em->getRepository(ScheduleSlotTemplate::class)->find($unlinked->getId()), 'une séance NON liée ne bouge jamais');
    }

    /** B2 — supprimer le bloc emporte aussi les réservations « bloc-complètes » de sa portée. */
    public function testDeletingTheBlockRemovesBlocCompleteReservations(): void
    {
        $ctx = $this->seedPeriod('m', capacity: 2);
        $source = $this->placeSlot($ctx, $ctx['teams']['source'], $ctx['case']);
        // Une case bloc-complète : les DEUX membres réservés sur la case du bloc, dans la portée.
        $r1 = $this->createReservation($ctx, $ctx['teams']['source'], $ctx['case']);
        $r2 = $this->createReservation($ctx, $ctx['teams']['joiner'], $ctx['case']);

        $this->client->request('POST', '/api/schedule-slots/' . $source->getId() . '/mutualize', [], [], $this->managerHeaders($ctx), json_encode([
            'teamIds' => [$ctx['teams']['source'], $ctx['teams']['joiner']], 'replacedSlotIds' => [],
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(200);
        $blockId = $this->responseData()['blockId'];

        $this->client->request('DELETE', '/api/shared_training_blocks/' . $blockId, [], [], $this->authHeaders($ctx['user']));
        self::assertResponseStatusCodeSame(204);

        $this->em->clear();
        $this->scopeGucToClub($ctx['clubId']);
        self::assertNull($this->em->getRepository(Reservation::class)->find($r1), 'réservation bloc-complète emportée');
        self::assertNull($this->em->getRepository(Reservation::class)->find($r2), 'réservation bloc-complète emportée');
    }

    /**
     * Lot A-2, règle fondateur : la MÊME équipe ne siège jamais deux fois à la même case. Une équipe
     * rejoignante qui possède DÉJÀ, à la case d'ancrage, une AUTRE séance que celle qu'elle désigne
     * ferait doublon une fois la désignée déplacée → 422, rien n'est écrit (aucun bloc).
     */
    public function testJoiningTeamWithAnotherSessionAtAnchorCaseIsRefused(): void
    {
        $ctx = $this->seedPeriod('n', capacity: 2);
        $source = $this->placeSlot($ctx, $ctx['teams']['source'], $ctx['case']);
        // Le joiner a sa séance désignée AILLEURS (mercredi) ET une AUTRE séance À LA CASE d'ancrage.
        $joinerElsewhere = $this->placeSlot($ctx, $ctx['teams']['joiner'], ['venueId' => $ctx['venueId'], 'dayOfWeek' => 3, 'startTime' => '18:00']);
        $this->placeSlot($ctx, $ctx['teams']['joiner'], $ctx['case']);

        $this->client->request('POST', '/api/schedule-slots/' . $source->getId() . '/mutualize', [], [], $this->managerHeaders($ctx), json_encode([
            'teamIds' => [$ctx['teams']['source'], $ctx['teams']['joiner']],
            'replacedSlotIds' => [$joinerElsewhere->getId()],
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);
        self::assertCount(0, $this->em->getRepository(SharedTrainingBlock::class)->findBy(['clubId' => $ctx['clubId']]), 'aucun bloc créé : le doublon de case refuse le geste');
    }

    // ───────────────────────────── Lot 2 — MUTUALISER DEPUIS LE DÉPÔT (/mutualize-at-case) ─────────

    /** Équipe → équipe : un bloc NEUF {source, cible} naît, les deux séances co-localisées sur la case de la cible, HARD/MANUAL. */
    public function testAtCaseMutualizesTwoTeamsIntoNewBlock(): void
    {
        $ctx = $this->seedPeriod('ac1', capacity: 2);
        $target = $this->placeSlot($ctx, $ctx['teams']['source'], $ctx['case']);
        $source = $this->placeSlot($ctx, $ctx['teams']['joiner'], $this->sourceCase($ctx));

        $this->postAtCase($ctx, $source->getId(), $target->getId(), 'U18 commun');
        self::assertResponseStatusCodeSame(200);

        $this->em->clear();
        $this->scopeGucToClub($ctx['clubId']);
        $block = $this->em->getRepository(SharedTrainingBlock::class)->findOneBy(['clubId' => $ctx['clubId']]);
        self::assertInstanceOf(SharedTrainingBlock::class, $block);
        self::assertSame(1, $block->getCommonSessions());
        self::assertSame('U18 commun', $block->getLabel());
        self::assertCount(2, $this->em->getRepository(SharedTrainingBlockTeam::class)->findBy(['blockId' => $block->getId()]));
        foreach ([$source->getId(), $target->getId()] as $slotId) {
            $slot = $this->em->getRepository(ScheduleSlotTemplate::class)->find($slotId);
            self::assertInstanceOf(ScheduleSlotTemplate::class, $slot);
            self::assertSame(2, $slot->getDayOfWeek(), 'les deux séances siègent sur la case de la cible (ancre)');
            self::assertSame('18:00', $slot->getStartTime()->format('H:i'));
            self::assertSame(LockLevel::HARD, $slot->getLockLevel());
            self::assertSame($block->getId(), $slot->getSharedTrainingBlockId());
        }
    }

    /** Équipe → groupe (rejoindre) : C = groupe ∪ {source} ; le groupe-cible à 1 séance tombe à 0 et MEURT, ses séances reliées à C survivent. */
    public function testAtCaseTeamJoinsGroupAndEmptiedGroupDies(): void
    {
        $ctx = $this->seedPeriod('ac2', capacity: 3);
        $a1 = $this->createTeam($ctx, 'A1', 2, true);
        $a2 = $this->createTeam($ctx, 'A2', 2, true);
        $group = $this->seedGroupAtCase($ctx, [$a1, $a2], $ctx['case'], 1);
        $source = $this->placeSlot($ctx, $ctx['teams']['joiner'], $this->sourceCase($ctx));

        $this->postAtCase($ctx, $source->getId(), $group['slots'][$a1]->getId(), null);
        self::assertResponseStatusCodeSame(200);
        $body = $this->responseData();
        self::assertContains($group['block']->getId(), $body['removedBlockIds'], 'le groupe-cible vidé meurt');

        $this->em->clear();
        $this->scopeGucToClub($ctx['clubId']);
        self::assertNull($this->em->getRepository(SharedTrainingBlock::class)->find($group['block']->getId()), 'bloc cible supprimé');
        $blocks = $this->em->getRepository(SharedTrainingBlock::class)->findBy(['clubId' => $ctx['clubId']]);
        self::assertCount(1, $blocks, 'un unique bloc C demeure');
        self::assertCount(3, $this->em->getRepository(SharedTrainingBlockTeam::class)->findBy(['blockId' => $blocks[0]->getId()]), 'C = 3 équipes');
        foreach ([$group['slots'][$a1]->getId(), $group['slots'][$a2]->getId(), $source->getId()] as $slotId) {
            $slot = $this->em->getRepository(ScheduleSlotTemplate::class)->find($slotId);
            self::assertInstanceOf(ScheduleSlotTemplate::class, $slot, 'les séances reliées à C survivent (FK : reliées AVANT suppression)');
            self::assertSame($blocks[0]->getId(), $slot->getSharedTrainingBlockId());
            self::assertSame(2, $slot->getDayOfWeek());
        }
    }

    /**
     * Groupe → groupe (fusion) : C = A ∪ B. ⚠ FK ON DELETE CASCADE — on COMPTE les lignes : les 4
     * séances survivent (reliées à C AVANT la suppression de A et B), aucune emportée par la cascade.
     */
    public function testAtCaseGroupFusionRepartitionsAndKeepsAllSessions(): void
    {
        $ctx = $this->seedPeriod('ac3', capacity: 4);
        $a1 = $this->createTeam($ctx, 'A1', 2, true);
        $a2 = $this->createTeam($ctx, 'A2', 2, true);
        $b1 = $this->createTeam($ctx, 'B1', 2, true);
        $b2 = $this->createTeam($ctx, 'B2', 2, true);
        $groupA = $this->seedGroupAtCase($ctx, [$a1, $a2], $ctx['case'], 1);
        $groupB = $this->seedGroupAtCase($ctx, [$b1, $b2], $this->sourceCase($ctx), 1);

        $slotCountBefore = \count($this->em->getRepository(ScheduleSlotTemplate::class)->findBy(['scheduleId' => $ctx['scheduleId']]));
        self::assertSame(4, $slotCountBefore);

        $this->postAtCase($ctx, $groupB['slots'][$b1]->getId(), $groupA['slots'][$a1]->getId(), null);
        self::assertResponseStatusCodeSame(200);
        $body = $this->responseData();
        self::assertEqualsCanonicalizing([$groupA['block']->getId(), $groupB['block']->getId()], $body['removedBlockIds'], 'A et B meurent tous deux');

        $this->em->clear();
        $this->scopeGucToClub($ctx['clubId']);
        $slotCountAfter = \count($this->em->getRepository(ScheduleSlotTemplate::class)->findBy(['scheduleId' => $ctx['scheduleId']]));
        self::assertSame(4, $slotCountAfter, 'les 4 séances survivent — aucune emportée par la cascade FK');
        self::assertNull($this->em->getRepository(SharedTrainingBlock::class)->find($groupA['block']->getId()));
        self::assertNull($this->em->getRepository(SharedTrainingBlock::class)->find($groupB['block']->getId()));
        $blocks = $this->em->getRepository(SharedTrainingBlock::class)->findBy(['clubId' => $ctx['clubId']]);
        self::assertCount(1, $blocks, 'un unique bloc C demeure');
        self::assertCount(4, $this->em->getRepository(SharedTrainingBlockTeam::class)->findBy(['blockId' => $blocks[0]->getId()]), 'C = 4 équipes');
        foreach ([$a1, $a2, $b1, $b2] as $teamId) {
            $slot = $this->em->getRepository(ScheduleSlotTemplate::class)->findOneBy(['scheduleId' => $ctx['scheduleId'], 'teamId' => $teamId]);
            self::assertInstanceOf(ScheduleSlotTemplate::class, $slot);
            self::assertSame($blocks[0]->getId(), $slot->getSharedTrainingBlockId(), 'chaque séance est reliée à C');
            self::assertSame(2, $slot->getDayOfWeek(), 'C siège sur la case de la cible');
        }
    }

    /** Si l'ensemble C égale un bloc existant, ce bloc passe à +1 séance commune (pas de doublon) — remplace le refus « déjà déclaré » sur ce chemin. */
    public function testAtCaseFeedsExistingBlockWithSameTeamSet(): void
    {
        $ctx = $this->seedPeriod('ac4', capacity: 2);
        $target = $this->placeSlot($ctx, $ctx['teams']['source'], $ctx['case']);
        $source = $this->placeSlot($ctx, $ctx['teams']['joiner'], $this->sourceCase($ctx));
        // Un bloc {source, joiner} existe déjà (1 séance commune ailleurs) dans la portée.
        $this->declareBlock($ctx, [$ctx['teams']['source'], $ctx['teams']['joiner']], 1);

        $this->postAtCase($ctx, $source->getId(), $target->getId(), null);
        self::assertResponseStatusCodeSame(200);

        $this->em->clear();
        $this->scopeGucToClub($ctx['clubId']);
        $blocks = $this->em->getRepository(SharedTrainingBlock::class)->findBy(['clubId' => $ctx['clubId']]);
        self::assertCount(1, $blocks, 'aucun bloc en doublon : le bloc existant est alimenté');
        self::assertSame(2, $blocks[0]->getCommonSessions(), 'le bloc existant passe à +1 séance commune');
    }

    /** Disjonction : une équipe présente des DEUX côtés (source déjà membre du groupe cible) → 422, rien écrit. */
    public function testAtCaseOverlappingTeamSetsRefused(): void
    {
        $ctx = $this->seedPeriod('ac5', capacity: 3);
        $shared = $this->createTeam($ctx, 'Partagée', 2, true);
        $a2 = $this->createTeam($ctx, 'A2', 2, true);
        $group = $this->seedGroupAtCase($ctx, [$shared, $a2], $ctx['case'], 1);
        // La source porte une équipe DÉJÀ membre du groupe cible → disjonction violée.
        $source = $this->placeSlot($ctx, $shared, $this->sourceCase($ctx));

        $this->postAtCase($ctx, $source->getId(), $group['slots'][$a2]->getId(), null);
        self::assertResponseStatusCodeSame(422);
    }

    /** > 10 équipes : C dépasse le plafond → 422, rien écrit. */
    public function testAtCaseTooManyTeamsRefused(): void
    {
        $ctx = $this->seedPeriod('ac6', capacity: 12);
        $members = [];
        for ($i = 0; $i < 10; ++$i) {
            $members[] = $this->createTeam($ctx, 'M' . $i, 2, true);
        }
        $group = $this->seedGroupAtCase($ctx, $members, $ctx['case'], 1);
        $source = $this->placeSlot($ctx, $ctx['teams']['joiner'], $this->sourceCase($ctx)); // 10 + 1 = 11 > 10

        $this->postAtCase($ctx, $source->getId(), $group['slots'][$members[0]]->getId(), null);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('au plus 10', (string) ($this->responseData()['error'] ?? ''));
    }

    /** Capacité : la case cible (capacité 1) héberge déjà une équipe NON-membre → le bloc n'y tient pas → 422. */
    public function testAtCaseFullCaseRefused(): void
    {
        $ctx = $this->seedPeriod('ac7', capacity: 1);
        $target = $this->placeSlot($ctx, $ctx['teams']['source'], $ctx['case']);
        $intruder = $this->createTeam($ctx, 'Intrus', 2, true);
        $this->placeSlot($ctx, $intruder, $ctx['case']); // non-membre déjà à la case cible
        $source = $this->placeSlot($ctx, $ctx['teams']['joiner'], $this->sourceCase($ctx));

        $this->postAtCase($ctx, $source->getId(), $target->getId(), null);
        self::assertResponseStatusCodeSame(422);
        self::assertCount(0, $this->em->getRepository(SharedTrainingBlock::class)->findBy(['clubId' => $ctx['clubId']]));
    }

    /** Verrou GESTIONNAIRE (lockLevel ≠ NONE, sans bloc) sur une séance impliquée → 422 souverain, rien écrit. */
    public function testAtCaseManagerLockedSlotRefused(): void
    {
        $ctx = $this->seedPeriod('ac8', capacity: 2);
        $target = $this->placeSlot($ctx, $ctx['teams']['source'], $ctx['case']);
        $source = $this->placeSlot($ctx, $ctx['teams']['joiner'], $this->sourceCase($ctx));
        $this->scopeGucToClub($ctx['clubId']);
        $source->setLockLevel(LockLevel::HARD)->setLockOrigin(LockOrigin::MANUAL); // verrou à la main, PAS un ancrage
        $this->em->flush();

        $this->postAtCase($ctx, $source->getId(), $target->getId(), null);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('verrouillée', (string) ($this->responseData()['error'] ?? ''));
        self::assertCount(0, $this->em->getRepository(SharedTrainingBlock::class)->findBy(['clubId' => $ctx['clubId']]));
    }

    /** Cross-tenant : une séance cible d'un AUTRE club est invisible (filtre tenant) → 404, rien écrit. */
    public function testAtCaseForeignTargetNotFound(): void
    {
        $ctx = $this->seedPeriod('ac9', capacity: 2);
        $source = $this->placeSlot($ctx, $ctx['teams']['source'], $this->sourceCase($ctx));
        $other = $this->seedPeriod('ac9b', capacity: 2);
        $foreignTarget = $this->placeSlot($other, $other['teams']['source'], $other['case']);
        $this->em->clear();

        $this->postAtCase($ctx, $source->getId(), $foreignTarget->getId(), null);
        self::assertResponseStatusCodeSame(404);
    }

    /** dryRun : les gardes passent mais RIEN n'est écrit (aucun bloc, aucune séance reliée/déplacée). */
    public function testAtCaseDryRunWritesNothing(): void
    {
        $ctx = $this->seedPeriod('ac10', capacity: 2);
        $target = $this->placeSlot($ctx, $ctx['teams']['source'], $ctx['case']);
        $source = $this->placeSlot($ctx, $ctx['teams']['joiner'], $this->sourceCase($ctx));

        $this->client->request('POST', '/api/schedule-slots/mutualize-at-case', [], [], $this->managerHeaders($ctx), json_encode([
            'sourceSlotId' => $source->getId(), 'targetSlotId' => $target->getId(), 'dryRun' => true,
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(200);
        self::assertTrue($this->responseData()['dryRun'] ?? false);

        $this->em->clear();
        $this->scopeGucToClub($ctx['clubId']);
        self::assertCount(0, $this->em->getRepository(SharedTrainingBlock::class)->findBy(['clubId' => $ctx['clubId']]), 'un essai ne crée aucun bloc');
        $stillSource = $this->em->getRepository(ScheduleSlotTemplate::class)->find($source->getId());
        self::assertSame(3, $stillSource?->getDayOfWeek(), 'un essai ne déplace pas la source');
        self::assertNull($stillSource?->getSharedTrainingBlockId(), 'un essai ne relie aucune séance');
    }

    /** Non-manager → 403, rien écrit. */
    public function testAtCaseNonManagerForbidden(): void
    {
        $ctx = $this->seedPeriod('ac11', capacity: 2);
        $target = $this->placeSlot($ctx, $ctx['teams']['source'], $ctx['case']);
        $source = $this->placeSlot($ctx, $ctx['teams']['joiner'], $this->sourceCase($ctx));
        $member = $this->createMember($ctx['clubId'], 'editor');

        $this->client->request('POST', '/api/schedule-slots/mutualize-at-case', [], [], $this->authHeaders($member) + ['CONTENT_TYPE' => 'application/json'], json_encode([
            'sourceSlotId' => $source->getId(), 'targetSlotId' => $target->getId(),
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(403);
    }

    /** Au SOCLE : équipe → équipe donne un bloc de portée NULL (comme les blocs de saison). */
    /**
     * Promesse fondateur 2026-10-30 : un groupe ne garde jamais moins de séances communes que de cases
     * réservées. Une équipe qui rejoint une séance du groupe lui retire une séance commune (K 2 → 1) ;
     * ses deux cases réservées restent. Refuser ou retirer la réservation tiennent la promesse : on
     * vérifie l'état final, pas le moyen.
     */
    public function testAtCaseNeverLeavesAGroupWithMoreReservedCasesThanSessions(): void
    {
        $ctx = $this->seedSeason('acR');
        $a = $this->createTeam($ctx, 'Groupe A', 4, true);
        $b = $this->createTeam($ctx, 'Groupe B', 4, true);
        $joiner = $this->createTeam($ctx, 'Rejoint', 4, true);
        $other = ['venueId' => $ctx['venueId'], 'dayOfWeek' => 4, 'startTime' => '18:00'];
        $group = $this->seedGroupAtCase($ctx, [$a, $b], $ctx['case'], 2);
        foreach ([$a, $b] as $teamId) {
            $this->createReservation($ctx, $teamId, $ctx['case']);
            $this->createReservation($ctx, $teamId, $other);
        }
        $source = $this->placeSlot($ctx, $joiner, $this->sourceCase($ctx));

        $this->postAtCase($ctx, $source->getId(), $group['slots'][$a]->getId(), null);

        $this->em->clear();
        $this->scopeGucToClub($ctx['clubId']);
        $block = $this->em->getRepository(SharedTrainingBlock::class)->find($group['block']->getId());
        if (!$block instanceof SharedTrainingBlock) {
            self::assertSame([], $this->em->getRepository(Reservation::class)->findBy(['clubId' => $ctx['clubId'], 'teamId' => [$a, $b]]), 'un groupe mort ne laisse pas de réservation de groupe');

            return;
        }
        $reservations = $this->em->getRepository(Reservation::class)->findBy(['clubId' => $ctx['clubId']]);
        $complete = ReservationGroupOccupancy::reservationsOnGroupCompleteCases($reservations, [$a => true, $b => true]);
        self::assertLessThanOrEqual(
            $block->getCommonSessions(),
            \count($complete) / 2,
            'plus de cases réservées que de séances communes : la génération suivante sort en échec',
        );
    }

    public function testAtCaseAtSeasonBasePlanWithNullScope(): void
    {
        $ctx = $this->seedSeason('acS');
        $target = $this->placeSlot($ctx, $ctx['teams']['source'], $ctx['case']);
        $source = $this->placeSlot($ctx, $ctx['teams']['joiner'], $this->sourceCase($ctx));

        $this->postAtCase($ctx, $source->getId(), $target->getId(), null);
        self::assertResponseStatusCodeSame(200);

        $this->em->clear();
        $this->scopeGucToClub($ctx['clubId']);
        $block = $this->em->getRepository(SharedTrainingBlock::class)->findOneBy(['clubId' => $ctx['clubId']]);
        self::assertInstanceOf(SharedTrainingBlock::class, $block);
        self::assertNull($block->getSchedulePlanId(), 'au socle, le bloc vit à portée NULL');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    /** La case SOURCE d'un dépôt (jour 3, 18h) — distincte de la case cible `$ctx['case']` (jour 2). */
    private function sourceCase(array $ctx): array
    {
        return ['venueId' => $ctx['venueId'], 'dayOfWeek' => 3, 'startTime' => '18:00'];
    }

    /**
     * Un groupe (bloc + adhésions + séances HARD/MANUAL liées) posé à une case. Les séances naissent
     * co-localisées à `$case`, reliées au bloc — exactement ce que le geste de mutualisation produit.
     *
     * @param array{clubId: string, seasonId: string, scheduleId: string, planId?: ?string} $ctx
     * @param list<string>                                                                  $teamIds
     * @param array{venueId: string, dayOfWeek: int, startTime: string}                     $case
     *
     * @return array{block: SharedTrainingBlock, slots: array<string, ScheduleSlotTemplate>}
     */
    private function seedGroupAtCase(array $ctx, array $teamIds, array $case, int $commonSessions): array
    {
        $this->scopeGucToClub($ctx['clubId']);
        $planId = $ctx['planId'] ?? null;
        $block = (new SharedTrainingBlock)->setClubId($ctx['clubId'])->setSeasonId($ctx['seasonId'])->setSchedulePlanId($planId)->setCommonSessions($commonSessions);
        $this->em->persist($block);
        $this->em->flush();

        $slots = [];
        foreach ($teamIds as $teamId) {
            $member = (new SharedTrainingBlockTeam)->setClubId($ctx['clubId'])->setSeasonId($ctx['seasonId'])->setSchedulePlanId($planId)->setBlockId($block->getId())->setTeamId($teamId);
            $this->em->persist($member);
            $slot = (new ScheduleSlotTemplate)
                ->setClubId($ctx['clubId'])
                ->setSeasonId($ctx['seasonId'])
                ->setScheduleId($ctx['scheduleId'])
                ->setTeamId($teamId)
                ->setVenueId($case['venueId'])
                ->setDayOfWeek($case['dayOfWeek'])
                ->setStartTime(DateTimeImmutable::createFromFormat('!H:i', $case['startTime']))
                ->setDurationMinutes(90)
                ->setLockLevel(LockLevel::HARD)
                ->setLockOrigin(LockOrigin::MANUAL)
                ->setSharedTrainingBlockId($block->getId());
            $this->em->persist($slot);
            $slots[$teamId] = $slot;
        }
        $this->em->flush();

        return ['block' => $block, 'slots' => $slots];
    }

    /** POST /mutualize-at-case avec un label optionnel. */
    private function postAtCase(array $ctx, string $sourceSlotId, string $targetSlotId, ?string $label): void
    {
        $body = ['sourceSlotId' => $sourceSlotId, 'targetSlotId' => $targetSlotId];
        if (null !== $label) {
            $body['label'] = $label;
        }
        $this->client->request('POST', '/api/schedule-slots/mutualize-at-case', [], [], $this->managerHeaders($ctx), json_encode($body, \JSON_THROW_ON_ERROR));
    }

    /**
     * Un club/manager/saison + un gymnase et sa fenêtre à la case (lundi 18h, couche PÉRIODE), une
     * équipe SOURCE et une équipe JOINER, un planning de PÉRIODE éditable.
     *
     * @return array{clubId: string, seasonId: string, planId: string, scheduleId: string, schedule: Schedule, venueId: string, categoryId: string, tierId: int, user: User, teams: array{source: string, joiner: string}, case: array{venueId: string, dayOfWeek: int, startTime: string}}
     */
    private function seedPeriod(string $suffix, int $capacity): array
    {
        $ctx = $this->seedBase($suffix, $capacity, season: false);
        $planId = $this->createPeriodPlan($ctx['clubId'], $ctx['seasonId']);
        $ctx['planId'] = $planId;
        $ctx['schedule'] = $this->linkPeriodSchedule($ctx['clubId'], $ctx['seasonId'], $planId);
        $ctx['scheduleId'] = $ctx['schedule']->getId();
        // La fenêtre de gymnase à la case vit dans la COUCHE du plan de période.
        $this->createWindow($ctx['clubId'], $ctx['seasonId'], $planId, $ctx['venueId'], 2, '18:00', $capacity);

        return $ctx;
    }

    /**
     * La variante SOCLE : le planning est la version du plan SEASON (isSeasonSchedule = true).
     *
     * @return array{clubId: string, seasonId: string, planId: ?string, scheduleId: string, schedule: Schedule, venueId: string, categoryId: string, tierId: int, user: User, teams: array{source: string, joiner: string}, case: array{venueId: string, dayOfWeek: int, startTime: string}}
     */
    private function seedSeason(string $suffix): array
    {
        $ctx = $this->seedBase($suffix, capacity: 2, season: true);
        $schedule = (new Schedule)->setClubId($ctx['clubId'])->setSeasonId($ctx['seasonId'])->setName('Socle')->setStatus(ScheduleStatus::COMPLETED)->setScore(80);
        $this->linkSeededSchedule($schedule); // plan SEASON
        $this->em->flush();
        $ctx['planId'] = null;
        $ctx['schedule'] = $schedule;
        $ctx['scheduleId'] = $schedule->getId();
        $this->createWindow($ctx['clubId'], $ctx['seasonId'], null, $ctx['venueId'], 2, '18:00', 2);

        return $ctx;
    }

    /**
     * Le socle commun : club + manager + saison + gymnase + 2 équipes (source, joiner).
     *
     * @return array{clubId: string, seasonId: string, venueId: string, categoryId: string, tierId: int, user: User, teams: array{source: string, joiner: string}, case: array{venueId: string, dayOfWeek: int, startTime: string}}
     */
    private function seedBase(string $suffix, int $capacity, bool $season): array
    {
        $uid = uniqid($suffix, true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $club = (new Club)->setName('Club lot9 ' . $suffix)->setSlug('club-lot9-' . $uid)->setTimezone('Europe/Paris')->setLocale('fr')->setOnboardingCompleted(true)->setFfbbClubCode(strtoupper(substr(md5($uid), 0, 3)) . strtoupper(substr(md5($uid), 3, 10)));
        $this->em->persist($club);

        $user = (new User)->setEmail('m9' . $uid . '@test.com')->setFirstName('Gest')->setLastName('User')->setPasswordHash($hasher->hashPassword(new User, 'pass'));
        $this->em->persist($user);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());
        $membership = (new ClubUser)->setClubId($club->getId())->setUserId($user->getId())->setRole('admin')->setIsActive(true);
        $this->em->persist($membership);

        $season = (new Season)->setClubId($club->getId())->setName('2026')->setStartDate(new DateTimeImmutable('2026-08-01'))->setEndDate(new DateTimeImmutable('2027-07-15'))->setStatus(SeasonStatus::ACTIVE);
        $season->setTransitionData([]);
        $this->em->persist($season);
        $this->em->flush();
        $this->provisionSeasonPlan($season);

        $venue = (new Venue)->setClubId($club->getId())->setSeasonId($season->getId())->setName('Gymnase ' . $suffix)->setSource('manual');
        $this->em->persist($venue);

        $sport = (new Sport)->setName('Basket ' . $uid)->setSlug('basket-' . $uid)->setIsActive(true);
        $this->em->persist($sport);
        $category = (new SportCategory)->setClubId($club->getId())->setSportId($sport->getId())->setName('U9-' . $uid)->setIsCustom(false)->setSortOrder(0);
        $this->em->persist($category);
        $this->em->flush();

        $ctx = [
            'clubId' => $club->getId(), 'seasonId' => $season->getId(), 'venueId' => $venue->getId(),
            'categoryId' => $category->getId(), 'tierId' => 3, 'user' => $user,
            'case' => ['venueId' => $venue->getId(), 'dayOfWeek' => 2, 'startTime' => '18:00'],
        ];
        $ctx['teams'] = [
            'source' => $this->createTeam($ctx, 'Source', 2, true),
            'joiner' => $this->createTeam($ctx, 'Joiner', 2, true),
        ];

        return $ctx;
    }

    /**
     * @param array{clubId: string, seasonId: string, categoryId: string, tierId: int} $ctx
     */
    private function createTeam(array $ctx, string $name, int $sessionsPerWeek, bool $active): string
    {
        $this->scopeGucToClub($ctx['clubId']);
        $team = (new Team)
            ->setClubId($ctx['clubId'])
            ->setSeasonId($ctx['seasonId'])
            ->setSportCategoryId($ctx['categoryId'])
            ->setPriorityTierId($ctx['tierId'])
            ->setName($name)
            ->setSessionsPerWeek($sessionsPerWeek)
            ->setIsActive($active);
        $this->em->persist($team);
        $this->em->flush();

        return $team->getId();
    }

    /**
     * @param array{clubId: string, seasonId: string, scheduleId: string} $ctx
     * @param array{venueId: string, dayOfWeek: int, startTime: string}   $case
     */
    private function placeSlot(array $ctx, string $teamId, array $case): ScheduleSlotTemplate
    {
        $this->scopeGucToClub($ctx['clubId']);
        $slot = (new ScheduleSlotTemplate)
            ->setClubId($ctx['clubId'])
            ->setSeasonId($ctx['seasonId'])
            ->setScheduleId($ctx['scheduleId'])
            ->setTeamId($teamId)
            ->setVenueId($case['venueId'])
            ->setDayOfWeek($case['dayOfWeek'])
            ->setStartTime(DateTimeImmutable::createFromFormat('!H:i', $case['startTime']))
            ->setDurationMinutes(90);
        $this->em->persist($slot);
        $this->em->flush();

        return $slot;
    }

    /**
     * @param array{clubId: string, seasonId: string, planId?: ?string} $ctx
     * @param array{venueId: string, dayOfWeek: int, startTime: string} $case
     */
    private function createReservation(array $ctx, string $teamId, array $case): string
    {
        $this->scopeGucToClub($ctx['clubId']);
        $reservation = (new Reservation)
            ->setClubId($ctx['clubId'])
            ->setSeasonId($ctx['seasonId'])
            ->setTeamId($teamId)
            ->setVenueId($case['venueId'])
            ->setDayOfWeek($case['dayOfWeek'])
            ->setStartTime(DateTimeImmutable::createFromFormat('!H:i', $case['startTime']))
            ->setDurationMinutes(90);
        $reservation->setSchedulePlanId($ctx['planId'] ?? null);
        $this->em->persist($reservation);
        $this->em->flush();

        return $reservation->getId();
    }

    private function linkPeriodSchedule(string $clubId, string $seasonId, string $planId): Schedule
    {
        $this->scopeGucToClub($clubId);
        $schedule = (new Schedule)->setClubId($clubId)->setSeasonId($seasonId)->setName('Période')->setStatus(ScheduleStatus::COMPLETED)->setScore(80);
        $schedule->setSchedulePlanId($planId);
        $this->em->persist($schedule);
        self::getContainer()->get(SchedulePlanProvisioner::class)->linkSchedule($schedule);
        $this->em->flush();

        return $schedule;
    }

    private function createWindow(string $clubId, string $seasonId, ?string $planId, string $venueId, int $dayOfWeek, string $startTime, int $capacity): void
    {
        $this->scopeGucToClub($clubId);
        $window = (new VenueTrainingSlot)
            ->setClubId($clubId)
            ->setSeasonId($seasonId)
            ->setVenueId($venueId)
            ->setDayOfWeek($dayOfWeek)
            ->setStartTime(DateTimeImmutable::createFromFormat('!H:i', $startTime))
            ->setDurationMinutes(90)
            ->setCapacity($capacity);
        $window->setSchedulePlanId($planId);
        $this->em->persist($window);
        $this->em->flush();
    }

    /**
     * @param array{clubId: string, seasonId: string, planId?: ?string} $ctx
     * @param list<string>                                              $teamIds
     */
    private function declareBlock(array $ctx, array $teamIds, int $commonSessions): void
    {
        $this->scopeGucToClub($ctx['clubId']);
        $planId = $ctx['planId'] ?? null;
        $block = (new SharedTrainingBlock)->setClubId($ctx['clubId'])->setSeasonId($ctx['seasonId'])->setSchedulePlanId($planId)->setCommonSessions($commonSessions);
        $this->em->persist($block);
        foreach ($teamIds as $teamId) {
            $member = (new SharedTrainingBlockTeam)->setClubId($ctx['clubId'])->setSeasonId($ctx['seasonId'])->setSchedulePlanId($planId)->setBlockId($block->getId())->setTeamId($teamId);
            $this->em->persist($member);
        }
        $this->em->flush();
    }

    private function createMember(string $clubId, string $role): User
    {
        $hasher = self::getContainer()->get('security.user_password_hasher');
        $uid = uniqid($role, true);
        $user = (new User)->setEmail($role . $uid . '@test.com')->setFirstName('N')->setLastName('Member')->setPasswordHash($hasher->hashPassword(new User, 'pass'));
        $this->em->persist($user);

        $this->scopeGucToClub($clubId);
        $membership = (new ClubUser)->setClubId($clubId)->setUserId($user->getId())->setRole($role)->setIsActive(true);
        $this->em->persist($membership);
        $this->em->flush();

        return $user;
    }

    /**
     * @param array{user: User} $ctx
     *
     * @return array{HTTP_AUTHORIZATION: string, CONTENT_TYPE: string}
     */
    private function managerHeaders(array $ctx): array
    {
        return $this->authHeaders($ctx['user']) + ['CONTENT_TYPE' => 'application/json'];
    }

    /**
     * @return array{HTTP_AUTHORIZATION: string}
     */
    private function authHeaders(User $user): array
    {
        $token = self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);

        return ['HTTP_AUTHORIZATION' => 'Bearer ' . $token];
    }

    /** @return array<string, mixed> */
    private function responseData(): array
    {
        /** @var array<string, mixed> $data */
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $data;
    }
}
