<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\Club;
use App\Entity\PriorityTier;
use App\Entity\Season;
use App\Entity\Sport;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Enum\Gender;
use App\Enum\SeasonStatus;
use App\Service\Basketball\FfbbApiClient;
use App\Service\Basketball\FfbbEngagementReader;
use App\Service\Basketball\FfbbTeamImporter;
use App\Service\PlanEntitlements;
use App\Service\SeasonResolver;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * P2-21 lot A — la création automatique des équipes engagées à l'onboarding.
 * FFBB simulée en MockHttpClient (patron FfbbClubPopulatorTest) : un jeu
 * BCCL-like — senior structuré, jeune « Brassage » à décoder, coupe à sauter.
 *
 * Refonte import IA PR2 (D9, décision #3) — l'import FFBB par API abandonne son barème LEVEL_TIER
 * (PN→rang 1 d'office) pour la RÈGLE FBI unique ({@see \App\Service\Basketball\TeamCreationSuggester},
 * rang par catégorie + numéro). Promesses re-exprimées ici (option A, TANK ROUGE → NEO) :
 *  - le RANG suit la règle FBI, résolu PAR LABEL via le repo `PriorityTier` : un engagement PN ne
 *    donne PLUS le rang 1 d'office (c'est le NUMÉRO dans la catégorie qui décide — SM1/SF1 = n°1 → S,
 *    U13M1 = jeune n°1 → A) ;
 *  - le GENRE STRUCTURÉ fédéral (« Masculin »/« Féminin ») reste PRIORITAIRE sur le genre lu au code/nom ;
 *  - le `level` décodé est TOUJOURS stocké (NATIONAL/REGIONAL/DEPARTEMENTAL).
 *
 * ⚠ Assertions par NOM (jamais par position) : le rang change l'ordre de tri — un couplage positionnel
 * casserait pour la mauvaise raison. L'exact numérotage des seniors par flux de genre (SM1 & SF1 tous
 * deux « n°1 ») est le barème FBI LITTÉRAL (décision #1) ; flaggé au rapport TANK comme point à
 * confirmer fondateur/NEO.
 */
#[Group('integration')]
final class FfbbTeamImporterTest extends KernelTestCase
{
    use TenantGucTrait;

    private const CLUB_CODE = 'ARA0069036';

    private EntityManagerInterface $em;

    private Club $club;

    public function testCreatesTeamsWithFbiRankStructuredGenderAndStoredLevel(): void
    {
        // Refonte PR2 (#3) — rang par la RÈGLE FBI (catégorie + numéro), résolu PAR LABEL ; genre
        // STRUCTURÉ prioritaire ; `level` toujours stocké. Assertions par NOM (le rang change l'ordre).
        $this->seedPriorityTiers();
        $importer = $this->buildImporter();

        self::assertSame(3, $importer->importEngagedTeams($this->club));
        self::assertCount(3, $this->em->getRepository(Team::class)->findBy(['clubId' => $this->club->getId()]));

        // SM1 — senior masculin (structuré « Masculin »), PN. La règle FBI le classe « senior n°1 → S »
        // (le NUMÉRO, pas le niveau PN) : un engagement PN ne donne PLUS le rang 1 d'office — ici S
        // coïncide avec n°1, mais c'est le numéro qui le dit. Niveau NATIONAL stocké.
        $sm1 = $this->teamNamed('SM1');
        self::assertSame($this->tierIdForLabel('S'), $sm1->getPriorityTierId(), 'senior n°1 → rang S (règle FBI, jamais le niveau PN d\'office)');
        self::assertSame('NATIONAL', $sm1->getLevel()?->value, 'le level décodé est stocké');
        self::assertSame(Gender::M, $sm1->getGender(), 'genre structuré « Masculin » → M');
        self::assertSame(2, $sm1->getSessionsPerWeek(), 'décision fondateur : 2 séances par défaut');

        // U13M1 — jeune « RMU13 Brassage », structuré VIDE : tout vient du code (R/M/U13). Jeune n°1 → A.
        $u13 = $this->teamNamed('U13M1');
        self::assertSame($this->tierIdForLabel('A'), $u13->getPriorityTierId(), 'jeune n°1 → rang A');
        self::assertSame('REGIONAL', $u13->getLevel()?->value, 'le level décodé est stocké');
        self::assertSame(Gender::M, $u13->getGender(), 'genre lu au code (structuré vide) → M');

        // SF1 — senior féminine (structuré « Féminin »), départementale. La règle FBI la classe
        // « senior n°1 → S » (le numéro décide), là où LEVEL_TIER la mettait rang C/D d'office : c'est
        // la preuve que le NIVEAU ne pilote PLUS le rang. Niveau DEPARTEMENTAL stocké.
        $sf1 = $this->teamNamed('SF1');
        self::assertSame($this->tierIdForLabel('S'), $sf1->getPriorityTierId(), 'senior n°1 → rang S (jamais le niveau départemental d\'office)');
        self::assertSame('DEPARTEMENTAL', $sf1->getLevel()?->value, 'le level décodé est TOUJOURS stocké');
        self::assertSame(Gender::F, $sf1->getGender(), 'genre structuré « Féminin » → F');

        // Vérité serveur : le marqueur qui autorise la modale « équipes importées » côté wizard.
        self::assertNotNull($this->club->getFfbbTeamsImportedAt());
    }

    public function testStructuredFederalGenderBeatsTheGenderReadFromTheCode(): void
    {
        // #3 — le genre STRUCTURÉ fédéral est PRIORITAIRE sur le genre lu au code/nom. Un engagement
        // dont le code dit « M » (RMU13 → masculin) mais dont le champ fédéral dit « Féminin » doit
        // créer une équipe FÉMININE : le structuré l'emporte (jamais le code qui le contredit).
        $this->seedPriorityTiers();
        $importer = $this->buildImporter([
            ['codeClub' => self::CLUB_CODE, 'sexe' => 'Féminin', 'categorie' => null,
                'idCompetition' => ['id' => 'C-RMU13', 'code' => 'RMU13', 'nom' => 'RMU13 Brassage'], 'idPoule' => ['id' => 'P-RMU13', 'nom' => 'Poule A']],
        ]);

        self::assertSame(1, $importer->importEngagedTeams($this->club));

        $team = $this->em->getRepository(Team::class)->findOneBy(['clubId' => $this->club->getId()]);
        self::assertInstanceOf(Team::class, $team);
        self::assertSame(Gender::F, $team->getGender(), 'le genre structuré « Féminin » l\'emporte sur le « M » lu dans le code RMU13');
    }

    public function testNonEmptyClubIsNeverTouched(): void
    {
        // La borne du cadrage : la moindre équipe existante → no-op total.
        $existing = (new Team)->setClubId($this->club->getId())->setSeasonId($this->seasonId())
            ->setSportCategoryId($this->categoryId('Senior'))->setName('Ma saisie')->setPriorityTierId(1);
        $this->em->persist($existing);
        $this->em->flush();

        self::assertSame(0, $this->buildImporter()->importEngagedTeams($this->club));
        self::assertCount(1, $this->em->getRepository(Team::class)->findBy(['clubId' => $this->club->getId()]));
        self::assertNull($this->club->getFfbbTeamsImportedAt(), 'aucun import → le marqueur ne ment jamais');
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $uid = uniqid('', true);

        $this->club = (new Club)->setName('Import ' . $uid)->setSlug('imp-' . $uid)
            ->setTimezone('Europe/Paris')->setLocale('fr')->setOnboardingCompleted(false)
            ->setFfbbClubCode(self::CLUB_CODE);
        $this->em->persist($this->club);
        $this->em->flush();
        $this->scopeGucToClub($this->club->getId());
        $year = SeasonResolver::seasonYear(new DateTimeImmutable);
        $season = (new Season)->setClubId($this->club->getId())->setName($year . '-' . ($year + 1))
            ->setStartDate(new DateTimeImmutable($year . '-07-15'))->setEndDate(new DateTimeImmutable(($year + 1) . '-07-14'))
            ->setStatus(SeasonStatus::ACTIVE)->setTransitionData([]);
        $this->em->persist($season);
        $this->em->flush();

        $sport = (new Sport)->setName('Basket ' . $uid)->setSlug('basket-' . $uid)->setIsActive(true);
        $this->em->persist($sport);
        $this->em->flush();
        foreach ([['Senior', 22, 99], ['U13', 12, 13]] as [$name, $min, $max]) {
            $category = (new SportCategory)->setClubId($this->club->getId())->setSportId($sport->getId())->setName($name)->setAgeMin($min)->setAgeMax($max);
            $this->em->persist($category);
        }
        $this->em->flush();
    }

    private function seasonId(): string
    {
        return (string) $this->em->getRepository(Season::class)->findOneBy(['clubId' => $this->club->getId()])?->getId();
    }

    private function categoryId(string $name): string
    {
        return (string) $this->em->getRepository(SportCategory::class)->findOneBy(['clubId' => $this->club->getId(), 'name' => $name])?->getId();
    }

    /**
     * @param list<array<string, mixed>>|null $engagements null = le jeu BCCL-like par défaut
     */
    private function buildImporter(?array $engagements = null): FfbbTeamImporter
    {
        $seasonCode = \sprintf('%02d-%02d', SeasonResolver::seasonYear(new DateTimeImmutable) % 100, (SeasonResolver::seasonYear(new DateTimeImmutable) + 1) % 100);
        $engagements ??= [
            // Sénior structuré (les champs FFBB remplis — le cas 7/14).
            ['codeClub' => self::CLUB_CODE, 'sexe' => 'Masculin', 'categorie' => ['libelle' => 'Seniors'],
                'idCompetition' => ['id' => 'C-PNM', 'code' => 'PNM', 'nom' => 'Pré nationale masculine'], 'idPoule' => ['id' => 'P-PNM', 'nom' => 'Poule D']],
            // Jeune « Brassage » : structuré VIDE — tout vient du code (décision fondateur).
            ['codeClub' => self::CLUB_CODE, 'sexe' => null, 'categorie' => null,
                'idCompetition' => ['id' => 'C-RMU13', 'code' => 'RMU13', 'nom' => 'RMU13 Brassage'], 'idPoule' => ['id' => 'P-RMU13', 'nom' => 'Poule A']],
            // Sénior féminine départementale.
            ['codeClub' => self::CLUB_CODE, 'sexe' => 'Féminin', 'categorie' => ['libelle' => 'Seniors'],
                'idCompetition' => ['id' => 'C-DF2', 'code' => 'DF2', 'nom' => 'Départementale féminine seniors'], 'idPoule' => ['id' => 'P-DF2', 'nom' => 'Poule A']],
            // Coupe : code indéchiffrable ET structuré vide → SAUTÉE, jamais devinée.
            ['codeClub' => self::CLUB_CODE, 'sexe' => null, 'categorie' => null,
                'idCompetition' => ['id' => 'C-CUP', 'code' => 'CRMLU17M', 'nom' => 'Coupe U17M'], 'idPoule' => ['id' => 'P-CUP', 'nom' => '1/8']],
        ];
        $competitions = [];
        foreach ($engagements as $e) {
            $competitions[$e['idCompetition']['code']] = [
                'id' => $e['idCompetition']['id'], 'code' => $e['idCompetition']['code'], 'nom' => $e['idCompetition']['nom'],
                'saison' => ['code' => $seasonCode],
                'poules' => [['id' => $e['idPoule']['id'], 'nom' => $e['idPoule']['nom'], 'engagements' => [['nom' => 'ADVERSAIRE UN'], ['nom' => 'ADVERSAIRE DEUX']]]],
            ];
        }

        $http = new MockHttpClient(function (string $method, string $url, array $options) use ($engagements, $competitions): MockResponse {
            if (str_contains($url, 'configuration')) {
                return new MockResponse((string) json_encode(['data' => ['key_ms' => 't']]));
            }
            $body = \is_string($options['body'] ?? null) ? $options['body'] : '';
            if (str_contains($body, 'ffbbserver_engagements')) {
                return new MockResponse((string) json_encode(['results' => [['hits' => $engagements]]]));
            }
            if (str_contains($body, 'ffbbserver_competitions')) {
                foreach ($competitions as $code => $row) {
                    if (str_contains($body, (string) $code)) {
                        return new MockResponse((string) json_encode(['results' => [['hits' => [$row]]]]));
                    }
                }
            }

            return new MockResponse((string) json_encode(['results' => [['hits' => []]]]));
        });

        $api = new FfbbApiClient($http);
        $reader = new FfbbEngagementReader($api);

        return new FfbbTeamImporter($this->em, $reader, self::getContainer()->get(SeasonResolver::class), new NullLogger, self::getContainer()->get(PlanEntitlements::class));
    }

    private function teamNamed(string $name): Team
    {
        $team = $this->em->getRepository(Team::class)->findOneBy(['clubId' => $this->club->getId(), 'name' => $name]);
        self::assertInstanceOf(Team::class, $team, \sprintf('l\'équipe « %s » doit être créée', $name));

        return $team;
    }

    /**
     * Les 5 rangs de référence (mapping canonique label→id), idempotents : `priority_tier` est GLOBAL.
     * Patron {@see FfbbExcelImporterTest::seedPriorityTiers}.
     */
    private function seedPriorityTiers(): void
    {
        foreach ([[1, 'S', 'Elite'], [2, 'A', 'Régional+'], [3, 'B', 'Régional'], [4, 'C', 'Départemental'], [5, 'D', 'Loisir']] as [$id, $label, $name]) {
            if ($this->em->getRepository(PriorityTier::class)->find($id) instanceof PriorityTier) {
                continue;
            }
            $tier = (new PriorityTier)->setId($id)->setLabel($label)->setName($name)
                ->setColor('#000000')->setOrToolsWeight(1)->setDefaultMinSessions(1);
            $this->em->persist($tier);
        }
        $this->em->flush();
    }

    private function tierIdForLabel(string $label): int
    {
        $tier = $this->em->getRepository(PriorityTier::class)->findOneBy(['label' => $label]);
        self::assertInstanceOf(PriorityTier::class, $tier, \sprintf('le rang « %s » doit être seedé', $label));

        return $tier->getId();
    }
}
