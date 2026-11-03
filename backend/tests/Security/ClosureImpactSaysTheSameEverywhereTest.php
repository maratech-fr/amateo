<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\CalendarEntry;
use App\Entity\Constraint;
use App\Entity\User;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\CalendarEntryStatus;
use App\Enum\ConstraintFamily;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
use App\Tests\ClosurePinDecorTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * NR GATE — axe planning lifecycle (plan fermetures B1/C4) : une fermeture dit LE MÊME CHIFFRE
 * partout. L'aperçu lu dans le formulaire AVANT « Enregistrer » (`GET /api/venue-closure-impact`)
 * et l'impact de la fermeture enregistrée (`GET /api/calendar-entries/{id}/impact`, que lisent la
 * carte, le radar et l'écran du plan) nomment les mêmes équipes, le même nombre de séances, les
 * mêmes matchs — l'impact d'une entrée écarte sa PROPRE famille (`ignoredEntryId`, racine
 * `COALESCE(parent_entry_id, id)`, correction finale 4), sinon la fermeture enregistrée se
 * capturerait elle-même et dirait « aucune séance ». Et l'annonce des retraits (`toRemove`) est
 * EXACTEMENT ce que l'enregistrement retire (`removed`), puis s'éteint une fois retiré.
 *
 * Jumeaux : `Integration/Service/VenueClosureImpactTest` (le calcul, cas par cas) ;
 * `Security/ClosureRemovesPinsAtomicallyTest` (le retrait, ligne à ligne).
 */
#[Group('phase1')]
#[Group('integration')]
final class ClosureImpactSaysTheSameEverywhereTest extends WebTestCase
{
    use ClosurePinDecorTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    public function testThePreviewTheSavedClosureAndTheRemovalSayTheSameNumbers(): void
    {
        $d = $this->seedClosurePinDecor('CIS');
        $tuesday = $d['closedTuesday'];

        $preview = $this->previewImpact($d['user'], $d['mistral'], $tuesday, $tuesday);
        self::assertSame(2, $preview['trainingOccurrences'] ?? null, 'témoin anti-vide : U13 et SM1 s’entraînent à Mistral le mardi 3/3');
        $holidayBefore = $this->governingPlan($preview, $d['holidayEntryId']);
        self::assertSame(1, $holidayBefore['toRemove']['locks'] ?? null, 'l’aperçu annonce le verrou de U13');
        self::assertSame([['teamId' => $d['sm1'], 'teamName' => 'SM1', 'dayOfWeek' => 2]], $holidayBefore['toRemove']['reservations'] ?? null, 'l’aperçu annonce la réservation de SM1');
        $this->assertNeverTheSocle($preview);

        $closure = $this->declareClosure($this->client, $d['user'], $d['mistral'], $tuesday, $tuesday);
        self::assertSame(201, $closure['status']);
        self::assertSame(
            ['locks' => $holidayBefore['toRemove']['locks'], 'reservations' => \count($holidayBefore['toRemove']['reservations'])],
            $closure['body']['removed'] ?? null,
            'l’enregistrement retire EXACTEMENT ce que l’aperçu annonçait',
        );

        $saved = $this->entryImpact($d['user'], $closure['entryId']);
        self::assertSame($preview['teams'] ?? null, $saved['teams'] ?? null, 'mêmes équipes avant et après « Enregistrer »');
        self::assertSame($preview['trainingOccurrences'] ?? null, $saved['trainingOccurrences'] ?? null, 'même nombre de séances');
        self::assertSame($preview['matchesToMove'] ?? null, $saved['matchesToMove'] ?? null, 'mêmes matchs à déplacer');
        $holidayAfter = $this->governingPlan($saved, $d['holidayEntryId']);
        self::assertSame(['locks' => 0, 'reservations' => []], $holidayAfter['toRemove'] ?? null, 'une fois retirées, plus rien à annoncer');
        $this->assertNeverTheSocle($saved);
    }

    /**
     * Correction finale 4 — l'impact d'une SEMAINE-ENFANT d'une indisponibilité découpée écarte
     * toute la famille (la mère porte la contrainte) : il dit ce que l'aperçu disait avant la saisie.
     */
    public function testTheImpactOfASplitWeekIgnoresItsWholeFamily(): void
    {
        $d = $this->seedClosurePinDecor('CIT');
        $preview = $this->previewImpact($d['user'], $d['mistral'], $this->decorMonday(10), $this->decorDay(10, 7));
        self::assertSame(3, $preview['trainingOccurrences'] ?? null, 'témoin : sous le socle, mardi ×2 + jeudi ×1 à Mistral');

        $this->scopeGucToClub($d['club']->getId());
        $mother = $this->closureEntry($d, 'Mistral en travaux', $this->decorMonday(10), $this->decorDay(11, 7), null);
        $child = $this->closureEntry($d, 'Mistral en travaux — semaine 1', $this->decorMonday(10), $this->decorDay(10, 7), $mother->getId());
        $constraint = (new Constraint)->setClubId($d['club']->getId())->setSeasonId($d['season']->getId())->setName('Mistral en travaux')
            ->setScope(ConstraintScope::FACILITY)->setScopeTargetId($d['mistral'])
            ->setFamily(ConstraintFamily::FACILITY)->setRuleType(ConstraintRuleType::HARD)->setCalendarEntryId($mother->getId());
        $constraint->setConfig(['type' => 'venue_closed', 'startDate' => $this->decorMonday(10), 'endDate' => $this->decorDay(11, 7)]);
        $this->em->persist($constraint);
        $this->em->flush();

        $childImpact = $this->entryImpact($d['user'], $child->getId());

        self::assertSame($preview['teams'] ?? null, $childImpact['teams'] ?? null, 'la semaine-enfant ne se capture pas par la fermeture de sa mère');
        self::assertSame($preview['trainingOccurrences'] ?? null, $childImpact['trainingOccurrences'] ?? null);
    }

    /** Tenant (sensible) : un gymnase d'un autre club → 404 ; l'entrée d'un autre club → 403/404, jamais ses chiffres. */
    public function testAnotherClubNeverReadsTheImpact(): void
    {
        $mine = $this->seedClosurePinDecor('CIU');
        $theirs = $this->seedClosurePinDecor('CIV');
        $closure = $this->declareClosure($this->client, $theirs['user'], $theirs['mistral'], $this->decorDay(10, 2), $this->decorDay(10, 2));
        // Témoin : le propriétaire lit bien son impact — sans lui, un 404 « route absente » passerait pour l'isolation.
        $this->entryImpact($theirs['user'], $closure['entryId']);

        $this->client->request('GET', '/api/venue-closure-impact?' . http_build_query(['venueId' => $theirs['mistral'], 'startDate' => $this->decorDay(10, 2), 'endDate' => $this->decorDay(10, 2)]), [], [], $this->decorHeaders($mine['user']));
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/api/calendar-entries/' . $closure['entryId'] . '/impact', [], [], $this->decorHeaders($mine['user']));
        self::assertContains($this->client->getResponse()->getStatusCode(), [403, 404]);
        self::assertStringNotContainsString('SM1', (string) $this->client->getResponse()->getContent());
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->pinDecorClock();
    }

    protected function tearDown(): void
    {
        $this->unpinDecorClock();
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function previewImpact(User $user, string $venueId, string $start, string $end): array
    {
        $this->client->request('GET', '/api/venue-closure-impact?' . http_build_query(['venueId' => $venueId, 'startDate' => $start, 'endDate' => $end]), [], [], $this->decorHeaders($user));
        self::assertResponseIsSuccessful();

        return $this->json();
    }

    /** @return array<string, mixed> */
    private function entryImpact(User $user, string $entryId): array
    {
        $this->client->request('GET', '/api/calendar-entries/' . $entryId . '/impact', [], [], $this->decorHeaders($user));
        self::assertResponseIsSuccessful();

        return $this->json();
    }

    /**
     * @param array<string, mixed> $impact
     *
     * @return array<string, mixed>
     */
    private function governingPlan(array $impact, string $entryId): array
    {
        foreach (\is_array($impact['governingPlans'] ?? null) ? $impact['governingPlans'] : [] as $plan) {
            if (\is_array($plan) && ($plan['entryId'] ?? null) === $entryId) {
                return $plan;
            }
        }
        self::fail('le plan des vacances gouverne le mardi fermé : il doit figurer dans governingPlans');
    }

    /** @param array<string, mixed> $impact */
    private function assertNeverTheSocle(array $impact): void
    {
        foreach (\is_array($impact['governingPlans'] ?? null) ? $impact['governingPlans'] : [] as $plan) {
            self::assertIsArray($plan);
            self::assertIsString($plan['entryId'] ?? null, 'un plan gouvernant a toujours son entrée : le socle (sans entrée) n’y figure jamais');
        }
    }

    /** @param array<string, mixed> $d */
    private function closureEntry(array $d, string $title, string $start, string $end, ?string $parentId): CalendarEntry
    {
        $entry = (new CalendarEntry)->setClubId($d['club']->getId())->setSeasonId($d['season']->getId())
            ->setKind(CalendarEntryKind::PERIOD)->setPeriodType(CalendarEntryPeriodType::CLOSURE)->setTitle($title)
            ->setStartDate(new DateTimeImmutable($start))->setEndDate(new DateTimeImmutable($end))
            ->setIsDisruptive(true)->setStatus(CalendarEntryStatus::ACTIVE)->setParentEntryId($parentId);
        $this->em->persist($entry);
        $this->em->flush();

        return $entry;
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);

        return \is_array($body) ? $body : [];
    }
}
