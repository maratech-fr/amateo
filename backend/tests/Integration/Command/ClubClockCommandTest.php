<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;

/**
 * La commande support qui pose/relâche l'« aujourd'hui » simulé d'un club.
 *
 * L'horloge est une capacité GÉNÉRIQUE : la commande vise n'importe quel club, par id OU
 * par code FFBB. Garde-fou pour un club RÉEL (non démo) : poser l'horloge le coupe de ses
 * e-mails réels, d'où le `--yes` obligatoire — un club démo n'en a jamais besoin. Plus les
 * deux refus qui protègent d'une fausse manip en rendez-vous : date irréelle (2026-02-31
 * « parse » en reportant au 3 mars) et l'ambiguïté --date + --clear.
 *
 * Action support cross-tenant : la commande (comme la console) agit sur la connexion ADMIN.
 * Le test seede/lit donc TOUT sur cette même connexion (committée, hors DAMA → nettoyée en
 * tearDown), pour que seeds, commande et vidage de boîte partagent la même vue.
 */
#[Group('integration')]
final class ClubClockCommandTest extends KernelTestCase
{
    private CommandTester $tester;

    /** @var list<string> clubs seedés sur la connexion admin (committés), nettoyés en tearDown */
    private array $clubIds = [];

    public function testSetsThenClearsTheSimulatedTodayOnADemoClub(): void
    {
        $clubId = $this->seedClub(isDemo: true);

        self::assertSame(0, $this->tester->execute(['--club' => $clubId, '--date' => '2026-12-15']), $this->tester->getDisplay());
        self::assertSame('2026-12-15', $this->simulatedToday($clubId));

        self::assertSame(0, $this->tester->execute(['--club' => $clubId, '--clear' => true]), $this->tester->getDisplay());
        self::assertNull($this->simulatedToday($clubId), 'l\'horloge est relâchée — retour au temps réel');
    }

    public function testUnrealDateIsRefused(): void
    {
        self::assertSame(1, $this->tester->execute(['--club' => $this->seedClub(isDemo: true), '--date' => '2026-02-31']));
        self::assertStringContainsString('not a real', $this->tester->getDisplay());
    }

    public function testDateAndClearTogetherAreAmbiguousAndRefused(): void
    {
        self::assertSame(1, $this->tester->execute(['--club' => $this->seedClub(isDemo: true), '--date' => '2026-12-15', '--clear' => true]));
    }

    public function testUnknownClubFails(): void
    {
        self::assertSame(1, $this->tester->execute(['--club' => '00000000-0000-4000-8000-000000000000', '--date' => '2026-12-15']));
        self::assertStringContainsString('not found', $this->tester->getDisplay());
    }

    // Capacité générique : un VRAI club est datable, mais seulement avec --yes — sans lui,
    // RÉSERVÉ aux clubs de démonstration : un VRAI club est refusé franc, rien écrit (décaler
    // son horloge donnerait la main sur des actions qui ne le concernent pas — décision fondateur).
    public function testRealClubIsRefused(): void
    {
        $realClubId = $this->seedClub(isDemo: false);

        self::assertSame(1, $this->tester->execute(['--club' => $realClubId, '--date' => '2026-12-15']));
        self::assertStringContainsString('demo-only', $this->tester->getDisplay());
        self::assertNull($this->simulatedToday($realClubId), 'le vrai club reste à l\'heure réelle');
    }

    public function testResolvesByFfbbClubCode(): void
    {
        $code = 'ARA' . str_pad((string) random_int(0, 9_999_999), 7, '0', \STR_PAD_LEFT);
        $clubId = $this->seedClub(isDemo: true, ffbbClubCode: $code);

        self::assertSame(0, $this->tester->execute(['--club' => $code, '--date' => '2026-12-15']), $this->tester->getDisplay());
        self::assertSame('2026-12-15', $this->simulatedToday($clubId));
    }

    // --clear vide AUSSI la boîte aux lettres (maison unique ClubMailboxPurger, partagée avec la
    // console). Poser une date ne la touche jamais.
    public function testClearEmptiesTheMailboxButSettingADateDoesNot(): void
    {
        $clubId = $this->seedClub(isDemo: true);
        $this->seedMailboxRow($clubId);
        self::assertSame(1, $this->mailboxCount($clubId), 'témoin : une ligne en boîte avant le clear');

        self::assertSame(0, $this->tester->execute(['--club' => $clubId, '--date' => '2026-03-01']), $this->tester->getDisplay());
        self::assertSame(1, $this->mailboxCount($clubId), 'poser une date ne vide pas la boîte');

        self::assertSame(0, $this->tester->execute(['--club' => $clubId, '--clear' => true]), $this->tester->getDisplay());
        self::assertSame(0, $this->mailboxCount($clubId), 'le clear a vidé la boîte');
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $application = new Application(self::$kernel);
        $this->tester = new CommandTester($application->find('app:club:clock'));
    }

    protected function tearDown(): void
    {
        // Seeds sur la connexion admin = committés (hors DAMA) : cascade sur la boîte.
        foreach ($this->clubIds as $id) {
            $this->admin()->executeStatement('DELETE FROM club WHERE id = :id', ['id' => $id]);
        }
        $this->clubIds = [];
        parent::tearDown();
    }

    private function seedClub(bool $isDemo, ?string $ffbbClubCode = null): string
    {
        $clubId = Uuid::v4()->toRfc4122();
        $this->clubIds[] = $clubId;
        $this->admin()->executeStatement(
            'INSERT INTO club (id, version, created_at, updated_at, name, slug, generation_count_season, timezone, locale, onboarding_completed, is_demo, ffbb_club_code)'
            . ' VALUES (:id, 1, NOW(), NOW(), :name, :slug, 0, :tz, :locale, FALSE, :demo, :ffbb)',
            ['id' => $clubId, 'name' => 'Demo ' . substr($clubId, 0, 8), 'slug' => 'demo-cmd-' . substr($clubId, 0, 8), 'tz' => 'Europe/Paris', 'locale' => 'fr', 'demo' => $isDemo, 'ffbb' => $ffbbClubCode],
            ['demo' => ParameterType::BOOLEAN],
        );

        return $clubId;
    }

    private function seedMailboxRow(string $clubId): void
    {
        $this->admin()->executeStatement(
            'INSERT INTO club_mailbox_message (id, club_id, created_at, simulated_date, from_address, to_address, subject, body_text)'
            . ' VALUES (:id, :club, NOW(), :d, :f, :t, :s, :b)',
            ['id' => Uuid::v4()->toRfc4122(), 'club' => $clubId, 'd' => '2026-03-01', 'f' => 'noreply@amateo.test', 't' => 'coach@club.fr', 's' => 'Relance', 'b' => 'Corps'],
        );
    }

    private function simulatedToday(string $clubId): ?string
    {
        $value = $this->admin()->fetchOne('SELECT simulated_today FROM club WHERE id = :id', ['id' => $clubId]);

        return \is_string($value) ? $value : null;
    }

    private function mailboxCount(string $clubId): int
    {
        return (int) $this->admin()->fetchOne('SELECT count(*) FROM club_mailbox_message WHERE club_id = :id', ['id' => $clubId]);
    }

    private function admin(): Connection
    {
        $connection = self::getContainer()->get(ManagerRegistry::class)->getConnection('admin');
        \assert($connection instanceof Connection);

        return $connection;
    }
}
