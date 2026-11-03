<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\User;
use App\Tests\ClosurePinDecorTrait;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Plan fermetures C3 + correction finale 3 — UNE seule règle hors saison : une fermeture RACINE
 * dont la fenêtre ne touche AUCUN jour de la saison est refusée (422 « Les dates doivent tenir dans
 * la saison. ») ; à cheval sur la fin de saison, elle est ACCEPTÉE (seules les dates en saison
 * comptent — le formulaire le dit, `VenueClosureForm.test.tsx`, miroir de ce refus).
 */
#[Group('phase1')]
#[Group('integration')]
final class ClosureSeasonWindowApiTest extends WebTestCase
{
    use ClosurePinDecorTrait;

    private KernelBrowser $client;

    public function testAClosureEntirelyOutsideTheSeasonIsRefused(): void
    {
        $d = $this->seedClosurePinDecor('CSW');

        $this->postClosureEntry($d['user'], '2026-08-03', '2026-08-09'); // la saison finit le 2026-07-15

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Les dates doivent tenir dans la saison.', (string) $this->client->getResponse()->getContent());
    }

    /** Témoin : à cheval sur la fin de saison, la fermeture s'enregistre. */
    public function testAClosureStraddlingTheSeasonEndIsAccepted(): void
    {
        $d = $this->seedClosurePinDecor('CSX');

        $this->postClosureEntry($d['user'], '2026-07-13', '2026-07-19');

        self::assertResponseStatusCodeSame(201);
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->pinDecorClock();
    }

    protected function tearDown(): void
    {
        $this->unpinDecorClock();
        parent::tearDown();
    }

    private function postClosureEntry(User $user, string $start, string $end): void
    {
        $this->client->request('POST', '/api/calendar_entries', [], [], $this->decorHeaders($user), json_encode([
            'kind' => 'period', 'periodType' => 'closure', 'title' => 'Mistral indisponible', 'startDate' => $start, 'endDate' => $end,
        ], \JSON_THROW_ON_ERROR));
    }
}
