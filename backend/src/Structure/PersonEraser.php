<?php

declare(strict_types=1);

namespace App\Structure;

use App\Entity\ClubMailboxMessage;
use App\Entity\Coach;
use App\Entity\Feedback;
use App\Entity\Person;
use App\Entity\Schedule;
use App\Entity\ScheduleDiagnostic;
use App\Entity\ScheduleStructureSnapshot;
use App\Entity\Season;
use App\Enum\AuditAction;
use App\Enum\ConstraintScope;
use App\Service\AuditTrail;
use App\Service\CoachWishCampaignTokenSync;
use App\Service\DisablesTenantFilters;
use App\Service\EntityCascadeDeleter;
use App\Service\PdfGenerator;
use App\Service\ScheduleConstraintBuilder;
use App\Service\TenantConnectionContext;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

/**
 * Efface une personne du club (RGPD, art. 17) : tout ce qui vient d'elle quitte la base, dans toutes
 * les saisons du club, lecture seule comprise. Ses séances restent, sans coach ; la collecte ouverte
 * passe au coach suivant ; ce qui ne vient pas d'elle reste lisible.
 *
 * **Une seule règle de remplacement**, par {@see self::PLACEHOLDER} :
 *  - données structurées, par IDENTIFIANT seulement : la ligne de ses rôles dans une demande figée
 *    (prénom et nom) et le nom de ses règles (portée coach, cible = l'un de ses rôles) ;
 *  - textes libres (diagnostics, signalements, boîte d'un club de démonstration, demandes figées) :
 *    son nom composé « prénom nom », mot entier, casse ignorée. Un nom réduit à un seul mot n'est
 *    JAMAIS remplacé par texte : il se retrouverait dans le nom d'une autre personne.
 * Une homonyme exacte du même club garde donc sa fiche, son rôle et sa ligne dans les demandes ; un
 * autre club n'est jamais lu (club en clause explicite, et RLS sous le contexte du club).
 *
 * Joué sous le contexte de club (GUC posé, puis rendu à sa valeur d'avant), dans une transaction.
 * Les fichiers PDF et le cache des demandes, hors base, sont traités après la validation.
 */
final class PersonEraser
{
    use DisablesTenantFilters;

    public const string PLACEHOLDER = 'Personne supprimée';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TenantConnectionContext $tenantConnectionContext,
        private readonly EntityCascadeDeleter $cascadeDeleter,
        private readonly CoachWishCampaignTokenSync $tokenSync,
        private readonly AuditTrail $auditTrail,
        #[Autowire(service: 'cache.schedule')]
        private readonly TagAwareCacheInterface $scheduleCache,
    ) {}

    /**
     * Les rôles coach de la fiche, dans le club, toutes saisons. Vide si la fiche n'est pas de ce club.
     *
     * @return list<string>
     */
    public function coachIdsOf(string $clubId, string $personId): array
    {
        return $this->asClub($clubId, fn (): array => array_map(
            static fn (Coach $coach): string => $coach->getId(),
            $this->coachesOf($clubId, $personId),
        ));
    }

    /**
     * @throws InvalidArgumentException la fiche n'existe pas dans ce club
     *
     * @return array{removedCoachIds: list<string>, blankedWishes: int, rewrittenSchedules: int, rewrittenFeedbacks: int, rewrittenMailboxMessages: int}
     */
    public function erase(string $clubId, string $personId): array
    {
        return $this->asClub($clubId, function () use ($clubId, $personId): array {
            $this->disableTenantFilters($this->entityManager);
            $person = $this->entityManager->find(Person::class, $personId);
            if (!$person instanceof Person || $person->getClubId() !== $clubId) {
                throw new InvalidArgumentException(\sprintf('Aucune fiche %s dans le club %s.', $personId, $clubId));
            }

            /** @var array{removedCoachIds: list<string>, blankedWishes: int, rewrittenSchedules: int, rewrittenFeedbacks: int, rewrittenMailboxMessages: int, scheduleIds: list<string>} $result */
            $result = $this->entityManager->wrapInTransaction(fn (): array => $this->eraseInTransaction($clubId, $person));

            // Hors base : après la validation, jamais avant (un fichier supprimé ne revient pas).
            foreach ($result['scheduleIds'] as $scheduleId) {
                PdfGenerator::deleteRendersOf($scheduleId);
            }
            $this->scheduleCache->invalidateTags([ScheduleConstraintBuilder::cacheTag($clubId)]);
            unset($result['scheduleIds']);

            return $result;
        });
    }

    /** @return array{removedCoachIds: list<string>, blankedWishes: int, rewrittenSchedules: int, rewrittenFeedbacks: int, rewrittenMailboxMessages: int, scheduleIds: list<string>} */
    private function eraseInTransaction(string $clubId, Person $person): array
    {
        $coaches = $this->coachesOf($clubId, $person->getId());
        $coachIds = array_map(static fn (Coach $coach): string => $coach->getId(), $coaches);
        $byId = array_fill_keys($coachIds, true);
        $namePattern = $this->namePattern($person->getFirstName(), $person->getLastName());
        $email = $person->getEmail();

        // 1. Le commentaire des doléances dont elle est l'auteur (la cascade va les dé-attribuer).
        $blankedWishes = [] === $coachIds ? 0 : (int) $this->entityManager->createQuery(
            'UPDATE App\Entity\CoachWish w SET w.comment = NULL WHERE w.clubId = :c AND w.coachId IN (:ids) AND w.comment IS NOT NULL',
        )->setParameter('c', $clubId)->setParameter('ids', $coachIds)->execute();

        // 2-3. Ses rôles, saison par saison : la cascade de suppression d'un coach, la ligne, puis la
        // règle du destinataire dans les collectes ouvertes (les jetons partent par clé étrangère).
        $seasonIds = [];
        foreach ($coaches as $coach) {
            $this->cascadeDeleter->purgeChildrenOfCoach($coach);
            $seasonIds[$coach->getSeasonId()] = true;
            $this->entityManager->remove($coach);
        }
        $this->entityManager->flush();
        foreach (array_keys($seasonIds) as $seasonId) {
            $this->tokenSync->syncSeason($clubId, (string) $seasonId);
        }

        // 8. La fiche, plus désignée par aucun rôle.
        $this->entityManager->remove($person);
        $this->entityManager->flush();

        // 4. Demandes figées (empreinte réalignée) ; 7. PDF stockés : adresses remises à vide.
        $rewrittenSchedules = 0;
        $scheduleIds = [];
        foreach ($this->entityManager->getRepository(Schedule::class)->findBy(['clubId' => $clubId]) as $schedule) {
            $scheduleIds[] = $schedule->getId();
            /** @var array<string, mixed> $snapshot */
            $snapshot = $this->scrub($schedule->getSnapshotData(), $byId, $namePattern);
            $graft = $schedule->getPayloadGraft();
            /** @var array<string, mixed>|null $newGraft */
            $newGraft = null === $graft ? null : $this->scrub($graft, $byId, $namePattern);
            if ($snapshot !== $schedule->getSnapshotData() || $newGraft !== $graft) {
                $schedule->setSnapshotData($snapshot)->setPayloadGraft($newGraft)->setSnapshotHash(ScheduleConstraintBuilder::structureHash($snapshot));
                ++$rewrittenSchedules;
            }
            if (null !== $schedule->getPdfExportUrl() || 'completed' === $schedule->getPdfExportStatus()) {
                $schedule->setPdfExportUrl(null)->setPdfExportStatus(null);
            }
        }
        foreach ($this->entityManager->getRepository(Season::class)->findBy(['clubId' => $clubId]) as $season) {
            if (null !== $season->getExportPdfUrl()) {
                $season->setExportPdfUrl(null);
            }
        }

        // 4. Les diagnostics qui restent (ceux qui portent l'identifiant d'un de ses rôles sont partis par la cascade).
        foreach ($this->entityManager->getRepository(ScheduleDiagnostic::class)->findBy(['clubId' => $clubId]) as $diagnostic) {
            $diagnostic->setMessage($this->replaceName($diagnostic->getMessage(), $namePattern));
            /** @var array<string, mixed> $suggestions */
            $suggestions = $this->scrub($diagnostic->getSuggestions(), $byId, $namePattern);
            /** @var list<array<string, mixed>> $causes */
            $causes = $this->scrub($diagnostic->getCauses(), $byId, $namePattern);
            $diagnostic->setSuggestions($suggestions)->setCauses($causes);
        }

        // 4. Les signalements du club : demande et diagnostics embarqués, branche « génération en échec » comprise.
        $rewrittenFeedbacks = 0;
        foreach ($this->entityManager->getRepository(Feedback::class)->findBy(['clubId' => $clubId]) as $feedback) {
            $context = $feedback->getContext();
            if (null === $context) {
                continue;
            }
            /** @var array<string, mixed> $scrubbed */
            $scrubbed = $this->scrub($context, $byId, $namePattern);
            if ($scrubbed !== $context) {
                $feedback->setContext($scrubbed);
                ++$rewrittenFeedbacks;
            }
        }

        // 5. Les photos des versions : sa ligne, ses liens d'équipe et de joueur, ses règles.
        foreach ($this->entityManager->getRepository(ScheduleStructureSnapshot::class)->findBy(['clubId' => $clubId]) as $photo) {
            $data = $photo->getData();
            $stripped = $this->withoutHerRows($data, $byId);
            if ($stripped !== $data) {
                $photo->setData($stripped);
            }
        }

        // 6. La boîte d'un club de démonstration : ce qui lui est adressé part, le reste perd son nom.
        $rewrittenMailboxMessages = 0;
        $htmlPattern = $this->namePattern(htmlspecialchars($person->getFirstName()), htmlspecialchars($person->getLastName()));
        foreach ($this->entityManager->getRepository(ClubMailboxMessage::class)->findBy(['clubId' => $clubId]) as $message) {
            if (null !== $email && $this->isAddressedTo($message->getToAddress(), $email)) {
                $this->entityManager->remove($message);
                continue;
            }
            $subject = $this->replaceName($message->getSubject(), $namePattern);
            $text = null === $message->getBodyText() ? null : $this->replaceName($message->getBodyText(), $namePattern);
            $html = null === $message->getBodyHtml() ? null : $this->replaceName($this->replaceName($message->getBodyHtml(), $namePattern), $htmlPattern);
            if ($subject !== $message->getSubject() || $text !== $message->getBodyText() || $html !== $message->getBodyHtml()) {
                $message->setSubject($subject)->setBodyText($text)->setBodyHtml($html);
                ++$rewrittenMailboxMessages;
            }
        }
        $this->entityManager->flush();

        $result = [
            'removedCoachIds' => $coachIds,
            'blankedWishes' => $blankedWishes,
            'rewrittenSchedules' => $rewrittenSchedules,
            'rewrittenFeedbacks' => $rewrittenFeedbacks,
            'rewrittenMailboxMessages' => $rewrittenMailboxMessages,
        ];
        // 8. Trace d'audit : identifiants et comptes, jamais un nom ni une adresse.
        $this->auditTrail->record(AuditAction::PERSON_ERASED, null, $clubId, 'Person', $person->getId(), ['personId' => $person->getId()] + $result);

        return $result + ['scheduleIds' => $scheduleIds];
    }

    /** @return list<Coach> */
    private function coachesOf(string $clubId, string $personId): array
    {
        /** @var list<Coach> $coaches */
        $coaches = $this->entityManager->createQuery('SELECT c FROM App\Entity\Coach c WHERE c.clubId = :c AND c.personId = :p ORDER BY c.id')
            ->setParameter('c', $clubId)
            ->setParameter('p', $personId)
            ->getResult();

        return $coaches;
    }

    /**
     * La règle de remplacement sur une valeur JSON : par identifiant, la ligne d'un de ses rôles et le
     * nom de ses règles ; par texte, son nom composé dans chaque chaîne.
     *
     * @param array<string, true> $coachIds
     */
    private function scrub(mixed $value, array $coachIds, ?string $namePattern): mixed
    {
        if (\is_string($value)) {
            return $this->replaceName($value, $namePattern);
        }
        if (!\is_array($value)) {
            return $value;
        }
        if (\is_string($value['id'] ?? null) && isset($coachIds[$value['id']]) && (\array_key_exists('firstName', $value) || \array_key_exists('lastName', $value))) {
            $value['firstName'] = self::PLACEHOLDER;
            $value['lastName'] = '';
        }
        if (ConstraintScope::COACH->value === ($value['scope'] ?? null) && \is_string($value['scopeTargetId'] ?? null) && isset($coachIds[$value['scopeTargetId']]) && \is_string($value['name'] ?? null)) {
            // Sa règle, désignée par identifiant : son nom y est remplacé ; un nom qui ne le contient
            // pas tel quel (prénom seul, autre graphie) est remplacé en entier.
            $value['name'] = null !== $namePattern && 1 === preg_match($namePattern, $value['name'])
                ? $this->replaceName($value['name'], $namePattern)
                : self::PLACEHOLDER;
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->scrub($item, $coachIds, $namePattern);
        }

        return $value;
    }

    private function replaceName(string $text, ?string $namePattern): string
    {
        return null === $namePattern ? $text : (string) preg_replace($namePattern, self::PLACEHOLDER, $text);
    }

    /**
     * Le motif du nom composé « prénom nom », mot entier (ni lettre, ni chiffre, ni trait d'union
     * autour), casse ignorée. Null pour un nom d'un seul mot : jamais remplacé par texte.
     */
    private function namePattern(string $firstName, string $lastName): ?string
    {
        $firstName = trim($firstName);
        $lastName = trim($lastName);
        if ('' === $firstName || '' === $lastName) {
            return null;
        }
        $words = preg_split('/\s+/u', $firstName . ' ' . $lastName, -1, \PREG_SPLIT_NO_EMPTY) ?: [];

        return '/(?<![\p{L}\p{N}_-])' . implode('\s+', array_map(static fn (string $word): string => preg_quote($word, '/'), $words)) . '(?![\p{L}\p{N}_-])/iu';
    }

    /**
     * @param array<string, list<array<string, mixed>>> $data
     * @param array<string, true>                       $coachIds
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function withoutHerRows(array $data, array $coachIds): array
    {
        $keep = [
            'Coach' => static fn (array $row): bool => !isset($coachIds[(string) ($row['id'] ?? '')]),
            'TeamCoach' => static fn (array $row): bool => !isset($coachIds[(string) ($row['coachId'] ?? '')]),
            'CoachPlayerMembership' => static fn (array $row): bool => !isset($coachIds[(string) ($row['coachId'] ?? '')]),
            'Constraint' => static fn (array $row): bool => ConstraintScope::COACH->value !== ($row['scope'] ?? null) || !isset($coachIds[(string) ($row['scopeTargetId'] ?? '')]),
        ];
        foreach ($keep as $family => $filter) {
            if (isset($data[$family])) {
                $data[$family] = array_values(array_filter($data[$family], $filter));
            }
        }

        return $data;
    }

    /** Destinataires joints par « , » ; l'adresse comparée sans la casse. */
    private function isAddressedTo(string $toAddress, string $email): bool
    {
        foreach (explode(',', $toAddress) as $recipient) {
            if (0 === strcasecmp(trim($recipient), trim($email))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Joue `$work` sous le contexte du club, puis rend au GUC sa valeur d'avant (vide s'il l'était).
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    private function asClub(string $clubId, callable $work): mixed
    {
        $previous = (string) $this->entityManager->getConnection()->fetchOne('SELECT COALESCE(current_setting(\'app.club_id\', true), \'\')');
        $this->tenantConnectionContext->setClubId($clubId);

        try {
            return $work();
        } finally {
            '' === $previous ? $this->tenantConnectionContext->clear() : $this->tenantConnectionContext->setClubId($previous);
        }
    }
}
