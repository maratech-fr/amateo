<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\TenantOwnedInterface;
use App\Service\RgpdExportService;
use PHPUnit\Framework\Attributes\Group;
use ReflectionClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * D-01 — l'export de portabilité (art. 20) doit couvrir TOUT le workspace du club.
 *
 * La liste des tables exportées vivait en dur et avait dérivé : au 2026-08-08, **9 tables
 * tenant** n'en faisaient plus partie, dont `coach_wish` — qui porte le commentaire libre du
 * coach et ses indisponibilités, donnée personnelle nominative. Et l'omission était
 * INVISIBLE : `GET /api/club/export` rendait 200, un JSON parfaitement valide, la clé
 * simplement absente. Rien, dans une réponse réussie, ne dit ce qui manque.
 *
 * Ce test ne compare pas deux listes écrites à la main — ce serait en créer une troisième.
 * Il exige que **toute entité tenant soit dans l'un des trois ensembles** : exportée en bloc,
 * traitée à part (avec sa raison), ou exclue nommément (avec sa raison). Une entité nouvelle
 * tombe donc en échec par défaut, ce qui est le bon défaut pour un export légal.
 *
 * ⚠ Une exclusion est une DÉCISION, pas un constat. Les deux actuelles se défendent :
 *  - `coach_wish_token` porte un **secret en clair** : l'exporter transformerait la
 *    portabilité en fuite de credentials (on écrirait des souhaits au nom d'un coach) ;
 *  - `audit_log` relève de l'**accountability art. 5.2**, base légale distincte du contrat —
 *    l'art. 20 ne couvre que les données fournies par la personne.
 * Toute exclusion future doit tenir le même niveau de justification.
 */
#[Group('phase1')]
final class RgpdExportCompletenessTest extends KernelTestCase
{
    public function testEveryTenantOwnedEntityIsExportedOrExcludedOnPurpose(): void
    {
        $service = new ReflectionClass(RgpdExportService::class);
        /** @var array<string, string> $handledApart */
        $handledApart = $service->getConstant('HANDLED_APART');
        /** @var array<string, string> $excluded */
        $excluded = $service->getConstant('EXCLUDED_FROM_EXPORT');

        $exported = array_flip($this->exportService()->clubScopedTables());

        $unaccounted = [];
        foreach ($this->tenantOwnedTables() as $table) {
            if (isset($exported[$table]) || isset($handledApart[$table]) || isset($excluded[$table])) {
                continue;
            }
            $unaccounted[] = $table;
        }

        self::assertSame([], $unaccounted, \sprintf(
            "Ces tables tenant ne sont NI exportées NI exclues nommément :\n  - %s\n"
            . "L'export de portabilité (art. 20) les omettrait EN SILENCE — la réponse reste 200 et valide.\n"
            . "Deux issues, jamais l'oubli : les laisser entrer dans la boucle générique, ou les ajouter à\n"
            . 'RgpdExportService::EXCLUDED_FROM_EXPORT AVEC la raison juridique ou de sécurité qui le justifie.',
            implode("\n  - ", $unaccounted),
        ));
    }

    /** Une exclusion sans raison écrite n'est pas une décision — c'est un oubli déguisé. */
    public function testEveryExclusionCarriesAReason(): void
    {
        $service = new ReflectionClass(RgpdExportService::class);
        /** @var array<string, string> $excluded */
        $excluded = $service->getConstant('EXCLUDED_FROM_EXPORT');

        foreach ($excluded as $table => $reason) {
            self::assertNotSame('', trim($reason), \sprintf('L\'exclusion de « %s » doit porter sa raison.', $table));
        }
    }

    /**
     * Le sens inverse : une table exportée qui n'est plus tenant (renommée, supprimée)
     * ferait exploser l'export en SQL error au pire moment — pendant l'exercice d'un droit.
     */
    public function testNothingIsExportedThatIsNoLongerTenantOwned(): void
    {
        $tenant = array_flip($this->tenantOwnedTables());

        $stale = array_values(array_filter(
            $this->exportService()->clubScopedTables(),
            static fn (string $table): bool => !isset($tenant[$table]),
        ));

        self::assertSame([], $stale, \sprintf(
            'Ces tables sont exportées mais ne portent plus TenantOwnedInterface : %s.',
            implode(', ', $stale),
        ));
    }

    /**
     * Envoi des conflits aux coachs — la mémoire du dernier envoi (qui a été informé, qui a été
     * sauté, quand, par qui) est une donnée du club : elle est EXPORTÉE, pas exclue.
     */
    public function testTheCoachMailingMemoryIsExportedAsClubData(): void
    {
        self::assertContains('conflict_mailing', $this->exportService()->clubScopedTables());
    }

    private function exportService(): RgpdExportService
    {
        self::bootKernel();
        $service = self::getContainer()->get(RgpdExportService::class);
        self::assertInstanceOf(RgpdExportService::class, $service);

        return $service;
    }

    /** @return list<string> */
    private function tenantOwnedTables(): array
    {
        $tables = [];

        self::bootKernel();
        $factory = self::getContainer()->get('doctrine')->getManager()->getMetadataFactory();
        foreach ($factory->getAllMetadata() as $metadata) {
            if (is_a($metadata->getName(), TenantOwnedInterface::class, true)) {
                $tables[] = $metadata->getTableName();
            }
        }

        self::assertNotEmpty($tables, 'Aucune entité tenant trouvée — le marqueur a-t-il changé de nom ?');

        return $tables;
    }
}
