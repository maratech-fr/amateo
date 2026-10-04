<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Schedule;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\ScheduleStatus;
use App\Enum\SeasonStatus;
use App\Service\SchedulePlanProvisioner;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * MESURE, PAS RAISONNEMENT — un plan VALIDÉ verrouille-t-il l'écriture des contraintes ?
 *
 * L'objection : « pour ajouter une contrainte alors que le planning est validé, tu es obligé
 * de rouvrir, donc il n'est plus validé ». Si c'est vrai, marquer les plannings validés serait
 * du code mort. On tranche par le CHEMIN RÉEL du gestionnaire : POST /api/schedules/{id}/validate
 * (le plan pointe la version = validé), puis POST /api/constraints avec le même JWT — les routes
 * exactes que le front appelle, à travers le firewall et les state processors, pas un service nu.
 */
#[Group('integration')]
final class ConstraintWriteOnValidatedPlanTest extends WebTestCase
{
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    public function testAConstraintCanBeWrittenWhileTheSeasonPlanIsValidated(): void
    {
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $passwordHasher = $container->get('security.user_password_hasher');

        $uid = uniqid('', true);
        $club = (new Club)->setName('C ' . $uid)->setSlug('c-' . $uid)->setTimezone('Europe/Paris')->setLocale('fr')->setOnboardingCompleted(true);
        $this->em->persist($club);
        $this->em->flush();
        $this->scopeGucToClub($club->getId());

        $user = (new User)->setEmail('m-' . $uid . '@example.com')->setFirstName('M')->setLastName('G');
        $user->setPasswordHash($passwordHasher->hashPassword($user, 'Password123!'));
        $this->em->persist($user);
        $this->em->flush();

        $clubUser = (new ClubUser)->setClubId($club->getId())->setUserId($user->getId())->setRole('admin')->setIsActive(true);
        $this->em->persist($clubUser);

        $season = (new Season)->setClubId($club->getId())->setName('2025-2026')
            ->setStartDate(new DateTimeImmutable('2025-09-01'))->setEndDate(new DateTimeImmutable('2026-06-30'))
            ->setStatus(SeasonStatus::ACTIVE)->setTransitionData([]);
        $this->em->persist($season);
        $this->em->flush();

        // Une version COMPLETED liée au plan SEASON, prête à être validée.
        $provisioner = $container->get(SchedulePlanProvisioner::class);
        $schedule = (new Schedule)->setClubId($club->getId())->setSeasonId($season->getId())->setName('S')->setStatus(ScheduleStatus::COMPLETED);
        $schedule->setSchedulePlanId($provisioner->ensureSeasonPlanId($season->getId()));
        $this->em->persist($schedule);
        $provisioner->linkSchedule($schedule);
        $this->em->flush();

        // JWT Bearer du gestionnaire — l'API est stateless (le front l'envoie en cookie ;
        // Bearer accepté pour scripts/tests), donc on l'attache à CHAQUE requête.
        $token = $container->get(JWTTokenManagerInterface::class)->create($user);
        $auth = ['HTTP_AUTHORIZATION' => 'Bearer ' . $token];

        // (1) VALIDER via la route réelle.
        $this->client->request('POST', '/api/schedules/' . $schedule->getId() . '/validate', [], [], [
            ...$auth,
            'CONTENT_TYPE' => 'application/json',
        ]);
        self::assertResponseStatusCodeSame(200, 'La validation par le gestionnaire doit réussir.');

        // Le plan pointe bien cette version (= validé / en vigueur, lecture seule côté UI).
        self::assertSame(
            $schedule->getId(),
            $provisioner->chosenOfSeasonPlan($season->getId()),
            'Après validate, le plan de saison pointe la version : elle est « validée ».',
        );

        // (2) Écrire une contrainte, PLAN TOUJOURS VALIDÉ, par la route du gestionnaire.
        $this->client->request('POST', '/api/constraints', [], [], [
            ...$auth,
            'CONTENT_TYPE' => 'application/ld+json',
        ], json_encode([
            'name' => 'SM2 au moins 1 séance à Matéo',
            'scope' => 'CLUB',
            'family' => 'DAY',
            'ruleType' => 'HARD',
            'config' => ['forbiddenDays' => [6]],
            'isActive' => true,
        ], \JSON_THROW_ON_ERROR));

        $status = $this->client->getResponse()->getStatusCode();
        $body = (string) $this->client->getResponse()->getContent();

        // Le verdict MESURÉ : la contrainte s'écrit (201), plan validé INCLUS. Le cas
        // « validé périmé » est donc RÉEL — et c'est le plus grave (planning distribué aux
        // coachs). Aucune garde (SeasonAccessGuard/ManagementAccessGuard seulement) ne lie
        // l'écriture d'une contrainte à l'état du plan : les contraintes sont club+saison,
        // pas plan. Si ce verdict passe un jour à 409/403, ce test rougit et rouvre la question.
        self::assertSame(201, $status, 'Contrainte refusée sur plan validé — corps: ' . $body);

        // Et le plan est TOUJOURS validé après l'écriture : on n'a rien eu à rouvrir.
        self::assertSame(
            $schedule->getId(),
            $provisioner->chosenOfSeasonPlan($season->getId()),
            'Écrire une contrainte n\'a pas dé-validé le plan : l\'objection « il faut rouvrir » est réfutée.',
        );
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }
}
