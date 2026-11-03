<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Club;
use App\Entity\ConflictMailing;
use App\Entity\ConflictResolution;
use App\Entity\Fixture;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\ConflictResolutionStatus;
use App\Enum\FbiCorrectionField;
use App\Repository\ConflictMailingRepository;
use App\Repository\ConflictResolutionRepository;
use App\Service\ConflictCoachMailing;
use App\Service\ConflictFeedAssembler;
use App\Service\FbiCorrectionLedger;
use App\Service\ManagementAccessGuard;
use App\Service\SeasonResolver;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * On-the-fly match/training conflict radar for a single coach (spec
 * gestion-matchs palier A, PR-2 — + VENUE_UNAVAILABLE since P1-4 PR B).
 * Recomputed at each call from the current fixtures + the schedule effective on
 * each match date (period overlay, else the season baseline) — nothing is
 * persisted. Read-only display feed for the placement grid / radar.
 *
 * Tenant scope: everything is loaded through mapped-entity repositories, so the
 * Doctrine club+season filters apply automatically — a club only ever sees its
 * own conflicts (guarded by FixtureConflictsApiTest).
 */
final class FixtureConflictsController extends AbstractController
{
    use ResolvesCurrentClubTrait;

    // P4-207: a conflict fingerprint is `TYPE:field(,field)*(:field(,field)*)*` where
    // TYPE is A-Z_ and every field is a uuid (see ConflictFingerprinter) — so colons,
    // commas and hex/hyphen only, never a slash. A strict requirement keeps a malformed
    // fingerprint a 404 (routing) instead of reaching the action.
    private const string FINGERPRINT_REQUIREMENT = '[A-Z_]+:[0-9a-fA-F,:-]+';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RequestStack $requestStack,
        private readonly SeasonResolver $seasonResolver,
        private readonly ConflictFeedAssembler $feedAssembler,
        private readonly ManagementAccessGuard $managementAccessGuard,
        private readonly ConflictResolutionRepository $resolutionRepository,
        private readonly ConflictMailingRepository $mailingRepository,
        private readonly ConflictCoachMailing $coachMailing,
        private readonly FbiCorrectionLedger $fbiCorrectionLedger,
        private readonly ClockInterface $clock,
    ) {}

    // priority > 0: this static path must win over API Platform's /api/fixtures/{id}
    // item route, which would otherwise swallow "conflicts" as an (invalid) uuid.
    #[Route('/api/fixtures/conflicts', name: 'api_fixture_conflicts', methods: ['GET'], priority: 10)]
    public function conflicts(): JsonResponse
    {
        $clubId = $this->resolveCurrentClubId($this->requestStack);
        if (null === $clubId) {
            return $this->json(['error' => 'No club in context.'], Response::HTTP_BAD_REQUEST);
        }

        [$season, $conflicts, $seasonPlanChosen, $held, $groupsBySchedule] = $this->computeConflicts($clubId);

        // P4-207 — champ ADDITIF `resolution` : le statut de traitement posé par le
        // gestionnaire, joint par empreinte sur les lignes du club+saison courants. La
        // map ne sert QUE les empreintes du flux ⇒ un orphelin (empreinte disparue) reste
        // invisible. Aucune ligne = « à traiter » = `null` (le défaut n'a pas de ligne).
        $byFingerprint = $season instanceof Season ? $this->resolutionRepository->mapByFingerprint($season->getId()) : [];
        $conflicts = array_map(
            function (array $conflict) use ($byFingerprint): array {
                $fingerprint = $conflict['fingerprint'] ?? null;
                $row = \is_string($fingerprint) ? ($byFingerprint[$fingerprint] ?? null) : null;

                return $conflict + ['resolution' => $row instanceof ConflictResolution ? $this->resolutionView($row) : null];
            },
            $conflicts,
        );

        return $this->json([
            'clubId' => $clubId,
            'seasonId' => $season?->getId(),
            'conflicts' => $conflicts,
            // Les lignes qu'un autre coach tient ce jour-là : rien à traiter, donc ni gravité, ni
            // empreinte, ni traitement, et hors du compteur et du delta de visite.
            'held' => $held,
            // Même raison que le radar des périodes : sans version pointée, la saison
            // n'a pas de calendrier, `MATCH_TRAINING` ne peut rien détecter hors période,
            // et `conflicts: []` devient indiscernable d'une saison réellement saine. Le
            // gestionnaire poserait un match sur un entraînement vivant. Un silence qui
            // ment est pire qu'un blanc.
            'seasonPlanChosen' => $seasonPlanChosen,
            'coachMailing' => $this->coachMailingView($clubId, $season, $conflicts, $groupsBySchedule),
        ]);
    }

    /**
     * PUT — le gestionnaire pose (ou remplace) le statut de traitement d'un conflit.
     * L'empreinte DOIT appartenir au flux COURANT du club+saison (recalculé par la
     * même plomberie que le GET) : une empreinte absente → 422 (jamais un statut
     * fantôme). Upsert sur la clé unique (club, saison, empreinte).
     */
    #[Route('/api/fixtures/conflicts/{fingerprint}/resolution', name: 'api_fixture_conflict_resolution_put', requirements: ['fingerprint' => self::FINGERPRINT_REQUIREMENT], methods: ['PUT'], priority: 10)]
    public function putResolution(Request $request, string $fingerprint): JsonResponse
    {
        $this->managementAccessGuard->assertManager(); // SEC-07 first, so 403 wins.

        $clubId = $this->resolveCurrentClubId($this->requestStack);
        if (null === $clubId) {
            return $this->json(['error' => 'No club in context.'], Response::HTTP_BAD_REQUEST);
        }

        /** @var mixed $payload */
        $payload = json_decode($request->getContent(), true);
        $payload = \is_array($payload) ? $payload : [];

        $status = ConflictResolutionStatus::tryFrom(\is_string($payload['status'] ?? null) ? $payload['status'] : '');
        if (!$status instanceof ConflictResolutionStatus) {
            return $this->json(['error' => 'Statut de traitement inconnu.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $note = null;
        if (\is_string($payload['note'] ?? null) && '' !== trim($payload['note'])) {
            $note = trim($payload['note']);
            if (mb_strlen($note) > 500) {
                return $this->json(['error' => 'La note ne peut pas dépasser 500 caractères.'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        [$season, $conflicts] = $this->computeConflicts($clubId);
        $conflict = null;
        foreach ($conflicts as $c) {
            if ($fingerprint === (string) ($c['fingerprint'] ?? '')) {
                $conflict = $c;
                break;
            }
        }
        if (!$season instanceof Season || null === $conflict) {
            return $this->json(['error' => 'Ce conflit n\'existe plus dans le radar actuel.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $conflictType = \is_string($conflict['type'] ?? null) ? $conflict['type'] : '';

        // Chaque famille a sa table de statuts (les 3 de base + les siens). Un statut hors
        // de cette table est refusé — le front masque le geste, mais le serveur est souverain.
        if (!\in_array($status, ConflictResolutionStatus::casesForFamily($conflictType), true)) {
            return $this->json(['error' => 'Ce statut de traitement ne s\'applique pas à cette famille de conflit.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // « Coache, ne joue pas » / « Joue, ne coache pas » n'ont de sens que si la personne
        // JOUE réellement un des côtés (un côté servi porte le rôle PLAYER) — sinon 422 parlant.
        if (
            \in_array($status, [ConflictResolutionStatus::COACHES_NOT_PLAYING, ConflictResolutionStatus::PLAYS_NOT_COACHING], true)
            && !$this->conflictHasPlayerSide($conflict)
        ) {
            return $this->json(['error' => 'Ce statut est réservé aux conflits où la personne joue.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // « Erreur FBI » (collision de gymnase) alimente le registre « à corriger dans FBI » :
        // le complément désigne la rencontre fautive (un des deux côtés du conflit) et le champ
        // (date/kickoff/venue). Un seul appel, atomique avec la résolution (un flush plus bas).
        // Un complément envoyé avec un AUTRE statut est incohérent : on le refuse explicitement
        // plutôt que de l'ignorer en silence (il ne serait jamais lu).
        $fbiTarget = null;
        if (ConflictResolutionStatus::FBI_ERROR === $status) {
            $resolved = $this->resolveFbiErrorTarget($payload['fbiCorrection'] ?? null, $conflict);
            if (\is_string($resolved)) {
                return $this->json(['error' => $resolved], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $fbiTarget = $resolved;
        } elseif (null !== ($payload['fbiCorrection'] ?? null)) {
            return $this->json(['error' => 'Un complément « erreur FBI » ne s\'applique qu\'au statut « erreur FBI ».'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $user = $this->getUser();
        \assert($user instanceof User); // assertManager() proved an active management membership.

        $row = $this->resolutionRepository->findOneByFingerprint($season->getId(), $fingerprint)
            ?? (new ConflictResolution)
                ->setClubId($clubId)
                ->setSeasonId($season->getId())
                ->setFingerprint($fingerprint);
        $row->setStatus($status)
            ->setNote($note)
            ->setUpdatedBy($user->getId());
        $this->entityManager->persist($row);

        if (null !== $fbiTarget) {
            // Valeur cible VIDE (l'appli ne connaît pas la bonne valeur : elle a importé
            // l'erreur, inventer serait mentir) ; l'entrée dit seulement « ce champ est à
            // vérifier dans FBI ». Rattachée au conflit : re-déclarer sur CE conflit ferme
            // la déclaration précédente (une seule vivante par conflit, lot N) ; un
            // double-clic sur la MÊME cible re-date sans dupliquer.
            [$fixture, $field] = $fbiTarget;
            $this->fbiCorrectionLedger->declareFromConflict($fixture, $field, $fingerprint, DateTimeImmutable::createFromInterface($this->clock->now()));
        }

        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            // Course : la pose est « lecture puis insertion » sans verrou. Deux PUT
            // simultanés du même (club, saison, empreinte) — ou de la même entrée de
            // registre « erreur FBI » — perdent l'unicité au flush. Le geste est
            // IDEMPOTENT : on relit la ligne gagnante et on rend un succès, jamais un 500
            // (l'idiome de rattrapage déjà en place dans six autres contrôleurs). Le flush
            // échoué a CLOS l'EM, mais la connexion — et son GUC tenant — vit : relecture
            // SQL brute, scopée club par la RLS.
            $winner = $this->entityManager->getConnection()->fetchAssociative(
                'SELECT status, note, updated_at FROM conflict_resolution WHERE season_id = ? AND fingerprint = ?',
                [$season->getId(), $fingerprint],
            );
            $view = \is_array($winner)
                ? ['status' => (string) $winner['status'], 'note' => null !== $winner['note'] ? (string) $winner['note'] : null, 'updatedAt' => new DateTimeImmutable((string) $winner['updated_at'])->format(DateTimeInterface::ATOM)]
                : ['status' => $status->value, 'note' => $note, 'updatedAt' => DateTimeImmutable::createFromInterface($this->clock->now())->format(DateTimeInterface::ATOM)];

            return $this->json(['fingerprint' => $fingerprint, 'resolution' => $view], Response::HTTP_OK);
        }

        return $this->json(['fingerprint' => $fingerprint, 'resolution' => $this->resolutionView($row)], Response::HTTP_OK);
    }

    /**
     * DELETE — retour à « à traiter » : la ligne disparaît. Idempotent (204 même sans
     * ligne) — « à traiter » EST l'absence de ligne.
     */
    #[Route('/api/fixtures/conflicts/{fingerprint}/resolution', name: 'api_fixture_conflict_resolution_delete', requirements: ['fingerprint' => self::FINGERPRINT_REQUIREMENT], methods: ['DELETE'], priority: 10)]
    public function deleteResolution(string $fingerprint): Response
    {
        $this->managementAccessGuard->assertManager(); // SEC-07

        $clubId = $this->resolveCurrentClubId($this->requestStack);
        if (null === $clubId) {
            return $this->json(['error' => 'No club in context.'], Response::HTTP_BAD_REQUEST);
        }

        $season = $this->seasonResolver->selectedOrCurrent($this->requestStack->getCurrentRequest(), $clubId);
        if ($season instanceof Season) {
            $row = $this->resolutionRepository->findOneByFingerprint($season->getId(), $fingerprint);
            if ($row instanceof ConflictResolution) {
                $this->entityManager->remove($row);
                $this->entityManager->flush();
            }
        }

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * The live conflict radar of a club, decorated (fingerprint + away-side place) by the single
     * {@see ConflictFeedAssembler} — shared with the coach mailing. Shared by the GET feed and the
     * resolution writes (which need the CURRENT flow to reject a vanished conflict).
     *
     * @return array{0: Season|null, 1: list<array<string, mixed>>, 2: bool, 3: list<array<string, mixed>>, 4: array<string, list<list<string>>>} season, conflicts (with fingerprint), seasonPlanChosen, held lines, groupsBySchedule
     */
    private function computeConflicts(string $clubId): array
    {
        $season = $this->seasonResolver->selectedOrCurrent($this->requestStack->getCurrentRequest(), $clubId);
        $feed = $this->feedAssembler->assemble($clubId, $season);

        return [$season, $feed['conflicts'], $feed['seasonPlanChosen'], $feed['held'], $feed['groupsBySchedule']];
    }

    /**
     * Envoi des conflits aux coachs — le champ ADDITIF `coachMailing`, toujours servi : les
     * compteurs du bouton calculés par le MÊME service que l'envoi ({@see ConflictCoachMailing}),
     * et la ligne « Envoyé le … » du dernier envoi de la saison (auteur résolu à la lecture).
     *
     * @param list<array<string, mixed>>        $conflicts
     * @param array<string, list<list<string>>> $groupsBySchedule
     *
     * @return array{personConflictCount: int, pendingRecipientCount: int, last: array{sentAt: string, sentByFirstName: string|null, mailedCount: int, skippedCoachIds: list<string>}|null}
     */
    private function coachMailingView(string $clubId, ?Season $season, array $conflicts, array $groupsBySchedule): array
    {
        $row = $season instanceof Season ? $this->mailingRepository->findOneBySeason($season->getId()) : null;
        $inputs = $this->feedAssembler->mailingInputs();
        $plan = $this->coachMailing->plan($conflicts, $inputs['mainCoachIdsByTeam'], $groupsBySchedule, $row?->getRecipientCoachIds() ?? [], $inputs['coachesById']);

        $last = null;
        if ($row instanceof ConflictMailing) {
            $sender = null !== $row->getSentByUserId() ? $this->entityManager->getRepository(User::class)->find($row->getSentByUserId()) : null;
            $club = $this->entityManager->getRepository(Club::class)->find($clubId);
            $last = [
                'sentAt' => ConflictCoachMailController::wallClock($row->getSentAt(), $club instanceof Club ? $club : null),
                'sentByFirstName' => $sender instanceof User ? $sender->getFirstName() : null,
                'mailedCount' => $row->getMailedCount(),
                'skippedCoachIds' => $row->getSkippedCoachIds(),
            ];
        }

        return ['personConflictCount' => $plan['personConflictCount'], 'pendingRecipientCount' => $plan['pendingRecipientCount'], 'last' => $last];
    }

    /**
     * Un côté servi du conflit porte-t-il le rôle PLAYER (la personne JOUE) ? On lit les
     * rôles DÉJÀ servis (jamais de redérivation) sur les quatre côtés possibles.
     *
     * @param array<string, mixed> $conflict
     */
    private function conflictHasPlayerSide(array $conflict): bool
    {
        foreach (['left', 'right', 'fixture', 'training'] as $sideKey) {
            $side = $conflict[$sideKey] ?? null;
            if (\is_array($side) && 'PLAYER' === ($side['role'] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Résout et valide le complément « erreur FBI » d'un PUT de résolution : la rencontre
     * fautive DOIT être l'un des côtés du conflit COURANT (donc du club, déjà filtré par la
     * même plomberie que le GET), et le champ l'un des trois du registre (date/kickoff/venue).
     * Renvoie [Fixture, FbiCorrectionField] prête à ouvrir, ou un message d'erreur français
     * (le contrôleur en fait un 422) — jamais une exception.
     *
     * @param array<string, mixed> $conflict
     *
     * @return array{0: Fixture, 1: FbiCorrectionField}|string
     */
    private function resolveFbiErrorTarget(mixed $payload, array $conflict): array|string
    {
        if (!\is_array($payload)) {
            return 'Une erreur FBI doit préciser la rencontre fautive et le champ concerné.';
        }
        $field = FbiCorrectionField::tryFrom(\is_string($payload['field'] ?? null) ? $payload['field'] : '');
        if (!$field instanceof FbiCorrectionField) {
            return 'Le champ à corriger doit être la date, l\'heure ou la salle.';
        }
        $fixtureId = \is_string($payload['fixtureId'] ?? null) ? $payload['fixtureId'] : '';
        if ('' === $fixtureId || !\in_array($fixtureId, $this->conflictSideFixtureIds($conflict), true)) {
            return 'La rencontre fautive doit être l\'une des deux rencontres de ce conflit.';
        }
        // Tenant/season filtered — la rencontre est un côté du conflit du club, elle
        // résout ; un null défensif (course) reste un 422 lisible, jamais un 500.
        $fixture = $this->entityManager->getRepository(Fixture::class)->findOneBy(['id' => $fixtureId]);
        if (!$fixture instanceof Fixture) {
            return 'La rencontre fautive est introuvable.';
        }

        return [$fixture, $field];
    }

    /**
     * Les identifiants de rencontre portés par les côtés MATCH d'un conflit
     * (left / right / fixture) — le périmètre où une « erreur FBI » peut viser.
     *
     * @param array<string, mixed> $conflict
     *
     * @return list<string>
     */
    private function conflictSideFixtureIds(array $conflict): array
    {
        $ids = [];
        foreach (['left', 'right', 'fixture'] as $sideKey) {
            $side = $conflict[$sideKey] ?? null;
            if (\is_array($side) && \is_string($side['fixtureId'] ?? null)) {
                $ids[] = $side['fixtureId'];
            }
        }

        return $ids;
    }

    /** @return array{status: string, note: string|null, updatedAt: string} */
    private function resolutionView(ConflictResolution $row): array
    {
        return [
            'status' => $row->getStatus()->value,
            'note' => $row->getNote(),
            'updatedAt' => $row->getUpdatedAt()->format(DateTimeInterface::ATOM),
        ];
    }
}
