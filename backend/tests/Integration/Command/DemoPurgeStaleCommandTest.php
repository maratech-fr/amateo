<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\User;
use App\Enum\ClubRole;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * NR (axe *tenant isolation*) : la purge NOCTURNE des démos prospect (`app:demo:purge-stale`,
 * cron-runner). Décision fondateur — un club démo prospect disparaît le LENDEMAIN, libérant le
 * code FFBB. Les propriétés de sûreté, chacune falsifiée par un cas voisin qui NE doit PAS bouger :
 *  - un club démo de l'animateur créé la VEILLE est détruit (ligne club supprimée, FFBB libéré) ;
 *  - créé le JOUR MÊME → gardé (réactiver la fenêtre le même jour réutilise le club) ;
 *  - un club démo PARTAGÉ (autre membre) → jamais détruit ;
 *  - un club NON démo de l'animateur → jamais détruit (un vrai club) ;
 *  - la démo BCCL permanente (autre compte, demo-bccl@) → hors scope, jamais concernée.
 */
#[Group('phase1')]
#[Group('integration')]
final class DemoPurgeStaleCommandTest extends KernelTestCase
{
    use TenantGucTrait;

    private const string ANIMATOR_EMAIL = 'demo@amateo.fr';

    private EntityManagerInterface $em;

    private CommandTester $tester;

    public function testDestroysYesterdayProspectDemosWhileSparingEverythingElse(): void
    {
        $animatorId = $this->seedUser(self::ANIMATOR_EMAIL);
        $yesterday = new DateTimeImmutable('now')->modify('-2 days');
        $today = new DateTimeImmutable('now');

        // À détruire : club démo de l'animateur, créé la veille.
        $staleId = $this->seedClub($animatorId, isDemo: true, createdAt: $yesterday, ffbb: $this->ara());
        // À garder : créé aujourd'hui (réactivation même jour → réutilise ce club).
        $freshId = $this->seedClub($animatorId, isDemo: true, createdAt: $today);
        // À garder : démo PARTAGÉ (un autre membre) créé la veille.
        $sharedId = $this->seedClub($animatorId, isDemo: true, createdAt: $yesterday, otherMemberId: $this->seedUser('co-' . bin2hex(random_bytes(3)) . '@test.local'));
        // À garder : club NON démo de l'animateur, créé la veille (un vrai club).
        $realId = $this->seedClub($animatorId, isDemo: false, createdAt: $yesterday);
        // À garder : démo BCCL permanente, tenue par un AUTRE compte (hors scope de l'animateur).
        $bcclOwner = $this->seedUser('demo-bccl@amateo.fr');
        $bcclId = $this->seedClub($bcclOwner, isDemo: true, createdAt: $yesterday, ffbb: 'ARA9999999');

        self::assertSame(Command::SUCCESS, $this->tester->execute([]), $this->tester->getDisplay());

        $this->em->clear();
        self::assertNull($this->em->getRepository(Club::class)->find($staleId), 'le club démo prospect de la veille est détruit — FFBB libéré');
        self::assertNotNull($this->em->getRepository(Club::class)->find($freshId), 'le club démo du jour est gardé');
        self::assertNotNull($this->em->getRepository(Club::class)->find($sharedId), 'un club démo partagé n\'est jamais détruit');
        $real = $this->em->getRepository(Club::class)->find($realId);
        self::assertInstanceOf(Club::class, $real);
        self::assertFalse($real->isDemo(), 'un vrai club n\'est jamais détruit');
        self::assertNotNull($this->em->getRepository(Club::class)->find($bcclId), 'la démo BCCL permanente est hors scope de l\'animateur prospect');
    }

    public function testNoAnimatorAccountIsANoOp(): void
    {
        self::assertSame(Command::SUCCESS, $this->tester->execute([]), $this->tester->getDisplay());
        self::assertStringContainsString('No demo animator account', $this->tester->getDisplay());
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->tester = new CommandTester(new Application(self::$kernel)->find('app:demo:purge-stale'));
    }

    private function seedUser(string $email): string
    {
        $user = new User;
        $user->setEmail($email);
        $user->setFirstName('Démo');
        $user->setLastName('Amateo');
        $user->setPasswordHash('x');
        $this->em->persist($user);
        $this->em->flush();

        return $user->getId();
    }

    private function seedClub(string $ownerId, bool $isDemo, DateTimeImmutable $createdAt, ?string $ffbb = null, ?string $otherMemberId = null): string
    {
        $suffix = bin2hex(random_bytes(4));
        $club = new Club;
        $club->setName('Club ' . $suffix)->setSlug('club-' . $suffix)->setTimezone('Europe/Paris')->setLocale('fr');
        if (null !== $ffbb) {
            $club->setFfbbClubCode($ffbb);
        }
        $club->setIsDemo($isDemo);
        $this->em->persist($club);
        $this->em->flush();
        // createdAt est posé « maintenant » par le constructeur : on le REDATE après persist
        // pour simuler un club de la veille (le cutoff de la purge est le début du jour courant).
        $club->setCreatedAt($createdAt);
        $this->em->flush();

        $clubId = $club->getId();
        $this->scopeGucToClub($clubId);
        $membership = new ClubUser()->setClubId($clubId)->setUserId($ownerId)->setIsActive(true);
        $membership->setRole(ClubRole::MANAGER->value);
        $this->em->persist($membership);
        $this->em->flush();
        if (null !== $otherMemberId) {
            $other = new ClubUser()->setClubId($clubId)->setUserId($otherMemberId)->setIsActive(true);
            $other->setRole(ClubRole::MEMBER->value);
            $this->em->persist($other);
            $this->em->flush();
        }
        $this->clearGuc();

        return $clubId;
    }

    /** Un code FFBB syntaxiquement valide et unique par test (3 lettres + 7 chiffres). */
    private function ara(): string
    {
        return 'ZZZ' . str_pad((string) random_int(0, 9_999_999), 7, '0', \STR_PAD_LEFT);
    }
}
