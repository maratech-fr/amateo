<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\Schedule;
use App\Entity\ScheduleDiagnostic;
use App\Enum\ScheduleDiagnosticSeverity;
use App\Enum\ScheduleStatus;
use App\Tests\Integration\Command\FeedbackPurgeCommandTest;
use App\Tests\Security\FeedbackContextIsolationTest;
use App\Tests\StartsFreshBrowserSession;
use App\Tests\TenantGucTrait;
use App\Tests\VerifiesRegistration;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * P4-355 (amendement) — la RAISON d'un échec de génération, jointe au CONTEXTE
 * AUTOMATIQUE d'un signalement. « On donne l'erreur, mais c'est le contexte qui
 * permet de se resituer » : quand la DERNIÈRE génération de la saison a ÉCHOUÉ
 * RÉCEMMENT, le support lit un résumé — sans que l'auteur ait à joindre le planning
 * fautif, sans copier le JSON — directement dans `context.auto.generationFailure`.
 *
 * NEO implémente (option A — ces tests sont ROUGES tant que l'enrichisseur ne pose
 * pas la clé) :
 *   - `FeedbackContextEnricher` résout CÔTÉ SERVEUR la dernière version de la saison
 *     authentifiée (jamais un id client) ; la référence de datage est
 *     COALESCE(solveStartedAt, createdAt) — un échec AVANT GENERATING (club_mismatch,
 *     overlay_entry_missing) n'a pas de solveStartedAt et retombe sur createdAt.
 *   - `auto.generationFailure` est joint SEULEMENT si cette dernière version est
 *     FAILED ET que son échec a MOINS DE 12 H (horloge applicative `ClockInterface`).
 *     Une COMPLETED postérieure l'efface (le gestionnaire a re-généré avec succès).
 *   - Résumé borné : {status, kind, type, failedAt, version:{number,name},
 *     firstError (≤300), countsByType}. `kind` = « technical » pour les diagnostics
 *     d'infrastructure émis par le backend (engine_timeout/engine_rejected/
 *     engine_error/internal_error/engine_status/engine_failed/club_mismatch/
 *     overlay_entry_missing/stuck_timeout), « business » pour les diagnostics
 *     d'INFÉASIBILITÉ du solveur (type `conflict`, `session_below_effective_min`,
 *     `implicit_rule_not_honored`…).
 *
 * L'étanchéité tenant de ce résumé (un échec FRAIS du club B ne fuit jamais dans le
 * signalement de A) est prouvée BLOQUANTE à part — point sensible, doublon d'angle
 * assumé : {@see FeedbackContextIsolationTest::testAForeignClubsFreshGenerationFailureNeverLeaksIntoTheContext}.
 * La purge RGPD du résumé (il vit dans `context`, détruit 30 j après « traité ») est
 * prouvée par {@see FeedbackPurgeCommandTest::testTreatedGenerationFailureSummaryIsPurgedWithTheContext}.
 */
#[Group('integration')]
final class FeedbackGenerationFailureTest extends WebTestCase
{
    use StartsFreshBrowserSession;
    use TenantGucTrait;
    use VerifiesRegistration;

    private KernelBrowser $client;

    /**
     * PROMESSE : un échec FRAIS (< 12 h) de la dernière génération de la saison est
     * joint, avec son résumé borné (statut, nature, type, horodatage, version).
     *
     * Falsifiable : sans l'enrichisseur, `auto.generationFailure` est absent → ROUGE.
     */
    public function testAFreshGenerationFailureIsAttachedToTheContext(): void
    {
        [$token, , $clubId] = $this->registerVerifiedMember('FBGF1');
        $seasonId = $this->currentSeasonId($clubId);
        $this->seedSchedule($clubId, $seasonId, ScheduleStatus::FAILED, $this->ago('11 hours'), null, 'Planning v4', 4, [
            ['engine_timeout', ScheduleDiagnosticSeverity::ERROR, 'Schedule generation timed out.'],
        ]);

        $failure = $this->generationFailureOf($token, $clubId);
        self::assertIsArray($failure, 'un échec frais (< 12 h) est joint au contexte');
        self::assertSame('failed', $failure['status'] ?? null, 'le statut de la version');
        self::assertSame('technical', $failure['kind'] ?? null, 'un timeout moteur est de nature technique');
        self::assertSame('engine_timeout', $failure['type'] ?? null, 'le type du diagnostic d\'erreur');
        self::assertSame(4, $failure['version']['number'] ?? null, 'le numéro de la version fautive');
        self::assertSame('Planning v4', $failure['version']['name'] ?? null, 'le nom de la version fautive');
        self::assertIsString($failure['failedAt'] ?? null, 'l\'horodatage de l\'échec est joint');
        self::assertSame('Schedule generation timed out.', $failure['firstError'] ?? null, 'le premier message d\'erreur');
    }

    /**
     * Témoin (borne des 12 h) : un échec de 13 h N'EST PAS joint — au-delà, il embrouille
     * le support (pensé pour ~20 clubs). Sans cette borne, « l'échec est joint » serait vrai
     * d'un planning cassé il y a des semaines, hors sujet du signalement du jour.
     *
     * Falsifiable : retirer la borne des 12 h rendrait `generationFailure` présent ici → ROUGE.
     */
    public function testAGenerationFailureOlderThanTwelveHoursIsNotAttached(): void
    {
        [$token, , $clubId] = $this->registerVerifiedMember('FBGF2');
        $seasonId = $this->currentSeasonId($clubId);
        $this->seedSchedule($clubId, $seasonId, ScheduleStatus::FAILED, $this->ago('13 hours'), null, 'Planning v2', 2, [
            ['engine_timeout', ScheduleDiagnosticSeverity::ERROR, 'Trop vieux pour embrouiller le support.'],
        ]);

        self::assertNull($this->generationFailureOf($token, $clubId), 'un échec de 13 h n\'est plus joint (borne 12 h)');
    }

    /**
     * PROMESSE : une COMPLETED POSTÉRIEURE efface l'échec — le gestionnaire a re-généré
     * avec succès, l'échec d'avant n'est plus le problème. La référence de datage de la
     * « dernière » génération est COALESCE(solveStartedAt, createdAt).
     *
     * Falsifiable : si l'enrichisseur ne regardait que « existe-t-il un FAILED frais ? »
     * sans vérifier qu'il est la DERNIÈRE version, le résumé serait joint → ROUGE.
     */
    public function testACompletedGenerationAfterTheFailureClearsTheSummary(): void
    {
        [$token, , $clubId] = $this->registerVerifiedMember('FBGF3');
        $seasonId = $this->currentSeasonId($clubId);
        $plan = Uuid::v4()->toRfc4122();
        $this->seedSchedule($clubId, $seasonId, ScheduleStatus::FAILED, $this->ago('3 hours'), $plan, 'Planning v5', 5, [
            ['conflict', ScheduleDiagnosticSeverity::ERROR, 'Infaisable.'],
        ]);
        $this->seedSchedule($clubId, $seasonId, ScheduleStatus::COMPLETED, $this->ago('1 hour'), $plan, 'Planning v6', 6, []);

        self::assertNull($this->generationFailureOf($token, $clubId), 'une réussite postérieure efface l\'échec d\'avant');
    }

    /**
     * PROMESSE : un échec AVANT le passage en GENERATING (donc SANS `solveStartedAt` —
     * club_mismatch / overlay_entry_missing) est tout de même détecté, daté par
     * COALESCE sur `createdAt`.
     *
     * Falsifiable : un datage sur `solveStartedAt` SEUL (sans COALESCE) laisserait cet
     * échec sans date → écarté de la fenêtre 12 h → absent (ROUGE).
     */
    public function testAFailureWithoutSolveStartedAtIsDatedFromCreatedAt(): void
    {
        [$token, , $clubId] = $this->registerVerifiedMember('FBGF4');
        $seasonId = $this->currentSeasonId($clubId);
        $this->seedSchedule($clubId, $seasonId, ScheduleStatus::FAILED, null, null, 'Planning v1', 1, [
            ['overlay_entry_missing', ScheduleDiagnosticSeverity::ERROR, 'Entrée de calendrier manquante.'],
        ], createdAt: $this->ago('2 hours'));

        $failure = $this->generationFailureOf($token, $clubId);
        self::assertIsArray($failure, 'un échec sans solveStartedAt est détecté via createdAt (COALESCE)');
        self::assertSame('technical', $failure['kind'] ?? null, 'overlay_entry_missing est une erreur technique');
    }

    /**
     * PROMESSE : la nature est « business » pour une INFÉASIBILITÉ du solveur (type
     * `conflict`) — le gestionnaire a posé des contraintes incompatibles, pas une panne.
     *
     * Falsifiable : classer tout échec « technical » rendrait ce test ROUGE.
     */
    public function testAnInfeasibleSolveIsClassifiedBusiness(): void
    {
        [$token, , $clubId] = $this->registerVerifiedMember('FBGF5');
        $seasonId = $this->currentSeasonId($clubId);
        $this->seedSchedule($clubId, $seasonId, ScheduleStatus::FAILED, $this->ago('2 hours'), null, 'Planning v3', 3, [
            ['conflict', ScheduleDiagnosticSeverity::ERROR, 'Deux verrous HARD s\'excluent.'],
        ]);

        $failure = $this->generationFailureOf($token, $clubId);
        self::assertIsArray($failure);
        self::assertSame('business', $failure['kind'] ?? null, 'une infaisabilité du solveur est de nature métier');
        self::assertSame('conflict', $failure['type'] ?? null);
    }

    /**
     * PROMESSE : le 1ᵉʳ message d'erreur est BORNÉ (~300) — un message moteur de 10 000
     * caractères (il peut porter une URL interne, du bruit) ne gonfle pas le contexte —
     * et `countsByType` agrège les diagnostics de l'échec par type.
     *
     * Falsifiable : sans troncature, `firstError` ferait 10 000 caractères → ROUGE ;
     * sans agrégat, `countsByType` serait absent → ROUGE.
     */
    public function testFirstErrorIsBoundedAndCountsByTypeAggregates(): void
    {
        [$token, , $clubId] = $this->registerVerifiedMember('FBGF6');
        $seasonId = $this->currentSeasonId($clubId);
        $this->seedSchedule($clubId, $seasonId, ScheduleStatus::FAILED, $this->ago('1 hour'), null, 'Planning v7', 7, [
            ['engine_error', ScheduleDiagnosticSeverity::ERROR, str_repeat('x', 10_000)],
            ['conflict', ScheduleDiagnosticSeverity::WARNING, 'Un avertissement.'],
            ['conflict', ScheduleDiagnosticSeverity::WARNING, 'Un autre avertissement.'],
        ]);

        $failure = $this->generationFailureOf($token, $clubId);
        self::assertIsArray($failure);
        self::assertLessThanOrEqual(300, mb_strlen((string) ($failure['firstError'] ?? '')), 'le 1ᵉʳ message est tronqué à ~300');
        self::assertNotSame('', $failure['firstError'] ?? '', 'un échec porte toujours un 1ᵉʳ message');
        $counts = $failure['countsByType'] ?? null;
        self::assertIsArray($counts, 'les diagnostics sont agrégés par type');
        self::assertSame(1, $counts['engine_error'] ?? null, 'un diagnostic engine_error');
        self::assertSame(2, $counts['conflict'] ?? null, 'deux diagnostics conflict');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    /**
     * Le résumé d'échec de génération du contexte automatique d'UN signalement SANS
     * planning nommé (on éprouve la résolution SERVEUR, pas la copie d'un planning joint).
     *
     * @return array<string, mixed>|null
     */
    private function generationFailureOf(string $token, string $clubId): ?array
    {
        $this->client->request('POST', '/api/feedback', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], json_encode(['topic' => 'bug', 'message' => 'La génération a échoué.'], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);

        $body = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        \assert(\is_array($body));
        $context = $this->contextOf($clubId, $body['id']);
        $failure = $context['auto']['generationFailure'] ?? null;

        return \is_array($failure) ? $failure : null;
    }

    /**
     * Sème une version (planning) à un statut et une horloge donnés, avec ses diagnostics,
     * directement sous RLS (patron FeedbackTest::seedCompletedSchedule).
     *
     * @param list<array{0: string, 1: ScheduleDiagnosticSeverity, 2: string}> $diagnostics
     */
    private function seedSchedule(
        string $clubId,
        string $seasonId,
        ScheduleStatus $status,
        ?DateTimeImmutable $solveStartedAt,
        ?string $schedulePlanId,
        string $name,
        int $versionNumber,
        array $diagnostics,
        ?DateTimeImmutable $createdAt = null,
    ): string {
        $this->scopeGucToClub($clubId);
        $em = $this->em();

        $schedule = new Schedule;
        $schedule->setClubId($clubId);
        $schedule->setSeasonId($seasonId);
        $schedule->setSchedulePlanId($schedulePlanId ?? Uuid::v4()->toRfc4122());
        $schedule->setName($name);
        $schedule->setVersionNumber($versionNumber);
        $schedule->setStatus($status);
        $schedule->setSolveStartedAt($solveStartedAt);
        if ($createdAt instanceof DateTimeImmutable) {
            $schedule->setCreatedAt($createdAt);
        } elseif ($solveStartedAt instanceof DateTimeImmutable) {
            // Sans createdAt explicite, l'aligner sur l'échec pour que COALESCE soit cohérent.
            $schedule->setCreatedAt($solveStartedAt);
        }
        $em->persist($schedule);

        foreach ($diagnostics as [$type, $severity, $message]) {
            $diagnostic = new ScheduleDiagnostic;
            $diagnostic->setClubId($clubId);
            $diagnostic->setSeasonId($seasonId);
            $diagnostic->setScheduleId($schedule->getId());
            $diagnostic->setType($type);
            $diagnostic->setSeverity($severity);
            $diagnostic->setMessage($message);
            $diagnostic->setSuggestions([]);
            $em->persist($diagnostic);
        }

        $em->flush();
        $id = $schedule->getId();
        $em->clear();
        $this->clearGuc();

        return $id;
    }

    private function ago(string $interval): DateTimeImmutable
    {
        return new DateTimeImmutable('now')->modify('-' . $interval);
    }

    /**
     * @return array{0: string, 1: string, 2: string} [token, userId, clubId]
     */
    private function registerVerifiedMember(string $ara): array
    {
        [$token, $userId, $clubId] = $this->registerVerified($ara);

        $this->scopeGucToClub($clubId);
        $this->em()->getConnection()->executeStatement(
            'UPDATE club_user SET role = :role WHERE club_id = :cid AND user_id = :uid',
            ['role' => 'member', 'cid' => $clubId, 'uid' => $userId],
        );
        $this->clearGuc();

        return [$token, $userId, $clubId];
    }

    /**
     * @return array{0: string, 1: string, 2: string} [token, userId, clubId]
     */
    private function registerVerified(string $ara): array
    {
        $ip = \sprintf('10.%d.%d.%d', random_int(1, 254), random_int(0, 254), random_int(1, 254));
        $suffix = strtolower($ara) . substr(md5(uniqid('', true)), 0, 6);
        $email = $suffix . '@test.fr';
        $this->client->request('POST', '/api/register', [], [], [
            'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $ip,
        ], json_encode([
            'email' => $email, 'password' => 'Password123!',
            'firstName' => 'Mem', 'lastName' => 'Bre', 'ara' => strtoupper($suffix), 'club_name' => 'Club ' . $ara, 'consent' => true,
        ], \JSON_THROW_ON_ERROR));

        $token = $this->verifyRegistration($this->client, $email);
        self::assertNotSame('', $token);

        $this->client->request('GET', '/api/me', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        $me = json_decode((string) $this->client->getResponse()->getContent(), true);
        \assert(\is_array($me));
        $userId = $me['id'];
        self::assertIsString($userId);

        $clubId = $this->em()->getConnection()->fetchOne(
            'SELECT club_id FROM club_user WHERE user_id = :uid LIMIT 1',
            ['uid' => $userId],
        );
        self::assertIsString($clubId);

        return [$token, $userId, $clubId];
    }

    private function currentSeasonId(string $clubId): string
    {
        $this->scopeGucToClub($clubId);
        $seasonId = $this->em()->getConnection()->fetchOne(
            'SELECT id FROM season WHERE club_id = :cid LIMIT 1',
            ['cid' => $clubId],
        );
        $this->clearGuc();
        self::assertIsString($seasonId, 'le register/verify matérialise une saison');

        return $seasonId;
    }

    /** @return array<string, mixed> */
    private function contextOf(string $clubId, string $id): array
    {
        $this->scopeGucToClub($clubId);
        $raw = $this->em()->getConnection()->fetchOne('SELECT context FROM feedback WHERE id = :id', ['id' => $id]);
        $this->clearGuc();
        self::assertIsString($raw, 'le contexte doit être stocké');
        $context = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($context);

        return $context;
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
