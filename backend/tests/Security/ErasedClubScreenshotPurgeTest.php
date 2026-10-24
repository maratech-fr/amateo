<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Feedback;
use App\Entity\User;
use App\Enum\ClubRole;
use App\Service\ErasedClubPurger;
use App\Storage\FeedbackScreenshotStorage;
use App\Tests\TenantGucTrait;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Chantier F PR4 (RGPD art. 17) — l'effacement d'un club emporte AUSSI les captures d'écran
 * de ses signalements. Le blob vit HORS table (FeedbackScreenshotStorage) : la suppression des
 * LIGNES `feedback` par `ErasedClubPurger` ne le toucherait pas sans un geste explicite. Un club
 * effacé ne doit garder AUCUNE donnée personnelle, image comprise.
 *
 * `PurgeCompletenessTest` garde la COMPLÉTUDE table par table (la ligne `feedback` est dans
 * `PURGED_BY_CLUB`) ; ce test-ci garde le BLOB, invisible à ce balayage de tables.
 *
 * Falsifiable : sans suppression du blob par le purger, la capture reste lisible après
 * l'effacement (ROUGE).
 */
#[Group('integration')]
final class ErasedClubScreenshotPurgeTest extends KernelTestCase
{
    use TenantGucTrait;

    private EntityManagerInterface $em;

    public function testErasingAClubDeletesItsFeedbackScreenshotBlobs(): void
    {
        $ownerId = $this->seedUser('erase-shot-' . bin2hex(random_bytes(3)) . '@test.local');
        $clubId = $this->seedClub($ownerId);
        $feedbackId = $this->seedFeedbackWithScreenshot($clubId, $ownerId);

        $storage = $this->storage();
        $storage->store($feedbackId, 'octets-de-capture');
        self::assertSame('octets-de-capture', $storage->read($feedbackId), 'la capture est bien stockée avant l\'effacement');

        $this->scopeGucToClub($clubId);
        $club = $this->em->getRepository(Club::class)->find($clubId);
        self::assertInstanceOf(Club::class, $club);
        self::getContainer()->get(ErasedClubPurger::class)->purge($club);
        $this->clearGuc();

        self::assertNull($storage->read($feedbackId), 'l\'effacement du club détruit la capture (hors table, hors RLS)');
        self::assertSame(
            0,
            (int) $this->admin()->fetchOne('SELECT count(*) FROM feedback WHERE club_id = :cid', ['cid' => $clubId]),
            'et la ligne de signalement est effacée (identité personnelle)',
        );
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $this->em = $em;
    }

    private function storage(): FeedbackScreenshotStorage
    {
        $storage = self::getContainer()->get(FeedbackScreenshotStorage::class);
        \assert($storage instanceof FeedbackScreenshotStorage);

        return $storage;
    }

    private function seedUser(string $email): string
    {
        $user = new User;
        $user->setEmail($email);
        $user->setFirstName('Ef');
        $user->setLastName('Facé');
        $user->setPasswordHash('x');
        $this->em->persist($user);
        $this->em->flush();

        return $user->getId();
    }

    private function seedClub(string $ownerId): string
    {
        $suffix = bin2hex(random_bytes(4));
        $club = new Club;
        $club->setName('Club ' . $suffix)->setSlug('erase-' . $suffix)->setTimezone('Europe/Paris')->setLocale('fr');
        $this->em->persist($club);
        $this->em->flush();
        $clubId = $club->getId();

        $this->scopeGucToClub($clubId);
        $membership = new ClubUser()->setClubId($clubId)->setUserId($ownerId)->setIsActive(true);
        $membership->setRole(ClubRole::MANAGER->value);
        $this->em->persist($membership);
        $this->em->flush();
        $this->clearGuc();

        return $clubId;
    }

    private function seedFeedbackWithScreenshot(string $clubId, string $userId): string
    {
        $this->scopeGucToClub($clubId);
        $feedback = new Feedback($clubId, $userId, 'bug', 'un signalement avec capture');
        $feedback->setHasScreenshot(true);
        $this->em->persist($feedback);
        $this->em->flush();
        $id = $feedback->getId();
        $this->em->clear();
        $this->clearGuc();

        return $id;
    }

    private function admin(): Connection
    {
        $connection = self::getContainer()->get(ManagerRegistry::class)->getConnection('admin');
        \assert($connection instanceof Connection);

        return $connection;
    }
}
