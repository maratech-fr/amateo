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
use App\Service\ClubGenerationLock;
use App\Service\CoachWishCampaignTokenSync;
use App\Service\DisablesTenantFilters;
use App\Service\EntityCascadeDeleter;
use App\Service\MatchPlacementLock;
use App\Service\PdfGenerator;
use App\Service\ScheduleConstraintBuilder;
use App\Service\TenantConnectionContext;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\TagAwareCacheInterface;
use Throwable;

/**
 * Efface une personne du club (RGPD, art. 17) : tout ce qui vient d'elle quitte la base, dans toutes
 * les saisons du club, lecture seule comprise. Ses séances restent, sans coach ; la collecte ouverte
 * passe au coach suivant ; ce qui ne vient pas d'elle reste lisible.
 *
 * **Une seule règle de remplacement**, par {@see self::PLACEHOLDER} :
 *  - données structurées, par IDENTIFIANT seulement : la ligne de ses rôles dans une demande figée
 *    (prénom et nom), le nom de ses règles (portée coach, cible = l'un de ses rôles) et le libellé
 *    de tout tableau qui en porte l'identifiant (`constraintId`, cause d'un diagnostic) ;
 *  - textes libres (diagnostics, signalements, boîte d'un club de démonstration, demandes figées) :
 *    son nom composé « prénom nom », mot entier, casse ignorée. Un nom réduit à un seul mot n'est
 *    JAMAIS remplacé par texte : il se retrouverait dans le nom d'une autre personne ;
 *  - dans les diagnostics et les signalements, le nom EXACT de chacune de ses règles, relevé par
 *    identifiant avant la cascade (« Indispo Camille mardi » ne porte pas son nom composé). Une règle
 *    d'équipe ou de club nommée à la main avec son prénom n'est pas à elle : elle reste.
 * Une homonyme exacte du même club garde donc sa fiche, son rôle, ses règles et sa ligne dans les
 * demandes ; un autre club n'est jamais lu (club en clause explicite, et RLS sous le contexte du club).
 * Une erreur du moteur d'expressions lève : la transaction est annulée, jamais un texte vidé.
 *
 * Joué sous les verrous de génération et de placement du club (refus si l'un est tenu, rendus après
 * succès comme après échec), sous le contexte de club (GUC posé, puis rendu à sa valeur d'avant),
 * dans une transaction. Les fichiers PDF et le cache des demandes, hors base, sont traités après la
 * validation ; un arrêt entre les deux se rattrape par {@see self::sweepRenderFiles()}.
 *
 * Appelée par la seule commande de support `app:people:erase` : les filtres de tenant désactivés par
 * {@see DisablesTenantFilters} ne sont JAMAIS restaurés (la commande vit le temps d'un effacement).
 * La trace d'audit ne nomme pas d'acteur : une commande de support n'a pas d'utilisateur connecté.
 */
final class PersonEraser
{
    use DisablesTenantFilters;

    public const string PLACEHOLDER = 'Personne supprimée';

    /** Durée de vie des verrous du club pendant l'effacement (secondes) : large devant une transaction. */
    private const int LOCK_TTL_SECONDS = 600;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TenantConnectionContext $tenantConnectionContext,
        private readonly EntityCascadeDeleter $cascadeDeleter,
        private readonly CoachWishCampaignTokenSync $tokenSync,
        private readonly AuditTrail $auditTrail,
        #[Autowire(service: 'cache.schedule')]
        private readonly TagAwareCacheInterface $scheduleCache,
        private readonly ClubGenerationLock $generationLock,
        private readonly MatchPlacementLock $placementLock,
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
     * @throws InvalidArgumentException      la fiche n'existe pas dans ce club
     * @throws PersonErasureBlockedException une génération ou un placement de matchs est en cours dans le club
     *
     * @return array{removedCoachIds: list<string>, blankedWishes: int, rewrittenSchedules: int, rewrittenFeedbacks: int, rewrittenMailboxMessages: int}
     */
    public function erase(string $clubId, string $personId): array
    {
        $generationToken = $this->generationLock->acquire($clubId, self::LOCK_TTL_SECONDS);
        if (null === $generationToken) {
            throw new PersonErasureBlockedException(\sprintf('A schedule generation is in progress in club %s.', $clubId));
        }

        $placementToken = null;

        try {
            $placementToken = $this->placementLock->acquire($clubId, self::LOCK_TTL_SECONDS);
            if (null === $placementToken) {
                throw new PersonErasureBlockedException(\sprintf('A match placement is in progress in club %s.', $clubId));
            }

            return $this->asClub($clubId, function () use ($clubId, $personId): array {
                $this->disableTenantFilters($this->entityManager);
                $person = $this->entityManager->find(Person::class, $personId);
                if (!$person instanceof Person || $person->getClubId() !== $clubId) {
                    throw new InvalidArgumentException(\sprintf('Aucune fiche %s dans le club %s.', $personId, $clubId));
                }

                // Transaction tenue à la main, pas `wrapInTransaction` : un échec annule et vide l'unité
                // de travail sans fermer le gestionnaire d'entités (un nouvel essai reste possible).
                $connection = $this->entityManager->getConnection();
                $connection->beginTransaction();

                try {
                    $result = $this->eraseInTransaction($clubId, $person);
                    $this->entityManager->flush();
                    $connection->commit();
                } catch (Throwable $failure) {
                    if ($connection->isTransactionActive()) {
                        $connection->rollBack();
                    }
                    $this->entityManager->clear();

                    throw $failure;
                }

                // Hors base : après la validation, jamais avant (un fichier supprimé ne revient pas).
                $this->deleteRendersAndCache($clubId, $result['scheduleIds']);
                unset($result['scheduleIds']);

                return $result;
            });
        } finally {
            if (null !== $placementToken) {
                $this->placementLock->release($clubId, $placementToken);
            }
            $this->generationLock->release($clubId, $generationToken);
        }
    }

    /**
     * Reprise de ce qui suit la validation (arrêt entre le commit et la suppression des fichiers) :
     * les rendus `schedule-<id>-*` de chaque planning du club, et le cache de ses demandes. Aucun
     * fichier d'un autre club : seuls les plannings du club sont lus (club en clause, RLS).
     *
     * @return int le nombre de plannings balayés
     */
    public function sweepRenderFiles(string $clubId): int
    {
        return $this->asClub($clubId, function () use ($clubId): int {
            /** @var list<string> $scheduleIds */
            $scheduleIds = $this->entityManager->getConnection()->fetchFirstColumn('SELECT id FROM schedule WHERE club_id = ?', [$clubId]);
            $this->deleteRendersAndCache($clubId, $scheduleIds);

            return \count($scheduleIds);
        });
    }

    /** @param list<string> $scheduleIds */
    private function deleteRendersAndCache(string $clubId, array $scheduleIds): void
    {
        foreach ($scheduleIds as $scheduleId) {
            PdfGenerator::deleteRendersOf($scheduleId);
        }
        $this->scheduleCache->invalidateTags([ScheduleConstraintBuilder::cacheTag($clubId)]);
    }

    /** @return array{removedCoachIds: list<string>, blankedWishes: int, rewrittenSchedules: int, rewrittenFeedbacks: int, rewrittenMailboxMessages: int, scheduleIds: list<string>} */
    private function eraseInTransaction(string $clubId, Person $person): array
    {
        $coaches = $this->coachesOf($clubId, $person->getId());
        $coachIds = array_map(static fn (Coach $coach): string => $coach->getId(), $coaches);
        $byId = array_fill_keys($coachIds, true);
        $namePattern = $this->namePattern($person->getFirstName(), $person->getLastName());
        $email = $person->getEmail();
        // Ses règles, relevées par identifiant AVANT que la cascade ne les supprime : les diagnostics
        // et signalements qui restent en citent l'identifiant et le nom.
        $herRules = $this->rulesOf($clubId, $coachIds);

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
        $ruleNames = $this->ruleNamesToReplace($herRules, $namePattern);

        // 4. Demandes figées (empreinte réalignée) ; 7. PDF stockés : adresses remises à vide.
        $rewrittenSchedules = 0;
        $scheduleIds = [];
        foreach ($this->entityManager->getRepository(Schedule::class)->findBy(['clubId' => $clubId]) as $schedule) {
            $scheduleIds[] = $schedule->getId();
            /** @var array<string, mixed> $snapshot */
            $snapshot = $this->scrub($schedule->getSnapshotData(), $byId, $namePattern, $herRules, []);
            $graft = $schedule->getPayloadGraft();
            /** @var array<string, mixed>|null $newGraft */
            $newGraft = null === $graft ? null : $this->scrub($graft, $byId, $namePattern, $herRules, []);
            if ($snapshot !== $schedule->getSnapshotData() || $newGraft !== $graft) {
                $schedule->setSnapshotData($snapshot)->setPayloadGraft($newGraft)->setSnapshotHash(ScheduleConstraintBuilder::structureHash($snapshot));
                ++$rewrittenSchedules;
            }
            if (null !== $schedule->getPdfExportUrl() || 'completed' === $schedule->getPdfExportStatus() || null !== $schedule->getPdfExportRequestId()) {
                // L'identifiant de la demande livrée part avec l'adresse : sinon la demande relue serait
                // tenue pour déjà rendue et le PDF jamais refabriqué.
                $schedule->setPdfExportUrl(null)->setPdfExportStatus(null)->setPdfExportRequestId(null);
            }
        }
        foreach ($this->entityManager->getRepository(Season::class)->findBy(['clubId' => $clubId]) as $season) {
            if (null !== $season->getExportPdfUrl()) {
                $season->setExportPdfUrl(null);
            }
        }

        // 4. Les diagnostics qui restent (ceux qui portent l'identifiant d'un de ses rôles sont partis par la cascade).
        foreach ($this->entityManager->getRepository(ScheduleDiagnostic::class)->findBy(['clubId' => $clubId]) as $diagnostic) {
            $diagnostic->setMessage($this->replaceText($diagnostic->getMessage(), $namePattern, $ruleNames));
            /** @var array<string, mixed> $suggestions */
            $suggestions = $this->scrub($diagnostic->getSuggestions(), $byId, $namePattern, $herRules, $ruleNames);
            /** @var list<array<string, mixed>> $causes */
            $causes = $this->scrub($diagnostic->getCauses(), $byId, $namePattern, $herRules, $ruleNames);
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
            $scrubbed = $this->scrub($context, $byId, $namePattern, $herRules, $ruleNames);
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
        // 8. Trace d'audit : identifiants et comptes, jamais un nom ni une adresse. Acteur non tracé
        // (null) : la commande de support n'a pas d'utilisateur connecté.
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
     * Ses règles (portée coach, cible = l'un de ses rôles), toutes saisons du club : identifiant => nom.
     *
     * @param list<string> $coachIds
     *
     * @return array<string, string>
     */
    private function rulesOf(string $clubId, array $coachIds): array
    {
        if ([] === $coachIds) {
            return [];
        }
        /** @var list<array{id: string, name: string}> $rows */
        $rows = $this->entityManager->createQuery(
            'SELECT r.id, r.name FROM App\Entity\Constraint r WHERE r.clubId = :c AND r.scope = :scope AND r.scopeTargetId IN (:ids)',
        )->setParameter('c', $clubId)->setParameter('scope', ConstraintScope::COACH)->setParameter('ids', $coachIds)->getArrayResult();

        $rules = [];
        foreach ($rows as $row) {
            $rules[(string) $row['id']] = $row['name'];
        }

        return $rules;
    }

    /**
     * Les noms exacts de ses règles à remplacer par texte dans les diagnostics et les signalements :
     * ceux qui ne portent pas son nom composé (celui-là se remplace par le motif, le reste du nom de la
     * règle restant lisible).
     *
     * @param array<string, string> $herRules
     *
     * @return array<string, string> nom => {@see self::PLACEHOLDER}
     */
    private function ruleNamesToReplace(array $herRules, ?string $namePattern): array
    {
        $names = [];
        foreach ($herRules as $name) {
            if ('' !== $name && !$this->containsName($name, $namePattern)) {
                $names[$name] = self::PLACEHOLDER;
            }
        }

        return $names;
    }

    /**
     * La règle de remplacement sur une valeur JSON : par identifiant, la ligne d'un de ses rôles, le
     * nom de ses règles et le libellé de ce qui en porte l'identifiant ; par texte, le nom exact de ses
     * règles (`$ruleNames`) puis son nom composé dans chaque chaîne.
     *
     * @param array<string, true>   $coachIds
     * @param array<string, string> $herRules  identifiant => nom
     * @param array<string, string> $ruleNames nom => remplacement
     */
    private function scrub(mixed $value, array $coachIds, ?string $namePattern, array $herRules, array $ruleNames): mixed
    {
        if (\is_string($value)) {
            return $this->replaceText($value, $namePattern, $ruleNames);
        }
        if (!\is_array($value)) {
            return $value;
        }
        if (\is_string($value['id'] ?? null) && isset($coachIds[$value['id']]) && (\array_key_exists('firstName', $value) || \array_key_exists('lastName', $value))) {
            $value['firstName'] = self::PLACEHOLDER;
            $value['lastName'] = '';
        }
        if (ConstraintScope::COACH->value === ($value['scope'] ?? null) && \is_string($value['scopeTargetId'] ?? null) && isset($coachIds[$value['scopeTargetId']]) && \is_string($value['name'] ?? null)) {
            $value['name'] = $this->herRuleLabel($value['name'], $namePattern);
        }
        if (\is_string($value['constraintId'] ?? null) && isset($herRules[$value['constraintId']])) {
            foreach (['label', 'name'] as $key) {
                if (\is_string($value[$key] ?? null)) {
                    $value[$key] = $this->herRuleLabel($value[$key], $namePattern);
                }
            }
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->scrub($item, $coachIds, $namePattern, $herRules, $ruleNames);
        }

        return $value;
    }

    /**
     * Le nom d'une de ses règles, désignée par identifiant : son nom composé y est remplacé ; un nom
     * qui ne le contient pas tel quel (prénom seul, initiale, autre graphie) est remplacé en entier.
     */
    private function herRuleLabel(string $label, ?string $namePattern): string
    {
        return $this->containsName($label, $namePattern) ? $this->replaceName($label, $namePattern) : self::PLACEHOLDER;
    }

    /** @param array<string, string> $ruleNames nom exact => remplacement */
    private function replaceText(string $text, ?string $namePattern, array $ruleNames): string
    {
        return $this->replaceName([] === $ruleNames ? $text : strtr($text, $ruleNames), $namePattern);
    }

    private function containsName(string $text, ?string $namePattern): bool
    {
        if (null === $namePattern) {
            return false;
        }
        $matched = preg_match($namePattern, $text);
        if (false === $matched) {
            throw new RuntimeException('Name pattern failed: ' . preg_last_error_msg());
        }

        return 1 === $matched;
    }

    /** Une erreur du moteur d'expressions lève (transaction annulée) : jamais un texte vidé en silence. */
    private function replaceName(string $text, ?string $namePattern): string
    {
        if (null === $namePattern) {
            return $text;
        }
        $replaced = preg_replace($namePattern, self::PLACEHOLDER, $text);
        if (null === $replaced) {
            throw new RuntimeException('Name replacement failed: ' . preg_last_error_msg());
        }

        return $replaced;
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
