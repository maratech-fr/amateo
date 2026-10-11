<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\VenueTrainingSlot;
use App\Service\SchedulePlanProvisioner;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * D-16 — la copie des créneaux d'une période énumère ses colonnes À LA MAIN.
 *
 * `SchedulePlanProvisioner::copySeasonalSlotRows()` fait un `INSERT … SELECT` qui liste douze
 * colonnes. Une période « possède » sa grille : ces lignes sont une COPIE du modèle de saison
 * prise à la naissance du plan (#8). Ajouter demain une colonne **nullable** à
 * `venue_training_slot` — un libellé, un drapeau, une durée d'échauffement — et l'oublier ici :
 * la copie part sans elle, **sans erreur**. La grille de période diverge alors du modèle de
 * saison qu'elle prétend copier, et personne ne le voit avant qu'un gestionnaire ne compare
 * deux écrans.
 *
 * Une colonne NOT NULL, elle, ferait échouer l'INSERT (23502) — bruyant, donc sans risque.
 * C'est **le cas nullable** que ce test couvre, celui qui passe.
 *
 * ⚠ Il lit les colonnes DANS LE SQL exécuté, pas dans une liste recopiée ici : c'est la leçon
 * de la revue #356 sur `TeamTagScopeTest`, dont la première version comparait deux copies et
 * restait verte quand on retirait une colonne — exactement le défaut qu'elle devait fermer.
 */
#[Group('phase1')]
final class PeriodSlotCopyKeepsEveryColumnTest extends KernelTestCase
{
    private const string PROVISIONER_SOURCE = __DIR__ . '/../../src/Service/SchedulePlanProvisioner.php';

    /**
     * Colonnes volontairement absentes de la copie, avec leur raison.
     *
     * @var array<string, string>
     */
    private const array NOT_COPIED = [
        'id' => 'régénérée (gen_random_uuid) — une copie n\'hérite pas de l\'identité de sa source',
        'version' => 'repart à 1 : la ligne copiée est neuve, son verrou optimiste aussi',
        'created_at' => 'horodatage de la COPIE (now()), pas celui du modèle',
        'updated_at' => 'idem',
        'schedule_plan_id' => 'c\'est le sens même de la copie : la ligne rejoint le plan de période',
    ];

    /**
     * Colonnes présentes dans l'INSERT mais dont le SELECT émet NULL plutôt que la valeur du
     * modèle — la copie en est AMPUTÉE à dessein. Différent de NOT_COPIED : la colonne EST écrite,
     * mais toujours à NULL. Lire le source garde l'intention honnête : rétablir la copie verbatim
     * (remplacer NULL par le nom de colonne dans le SELECT) rougit {@see testColumnsCopiedAsNullAreEmittedAsNull}.
     *
     * @var array<string, string>
     */
    private const array NULLED_COPY = [
        'group_label' => 'le libellé de groupe appartient au planning de saison (décision fondateur 2026-10-11) — une période naît sans libellé, jamais la copie du modèle',
    ];

    /**
     * Les colonnes déclarées NULLED_COPY sont bien émises NULL par le SELECT, à leur place — pas
     * recopiées depuis le modèle. C'est la garde de l'intention « copiée à NULL, voulue » : elle
     * tombe si quelqu'un rétablit la copie verbatim du libellé dans le SELECT.
     */
    public function testColumnsCopiedAsNullAreEmittedAsNull(): void
    {
        $exprByColumn = $this->selectExpressionByColumn();

        foreach (self::NULLED_COPY as $column => $reason) {
            self::assertArrayHasKey($column, $exprByColumn, \sprintf(
                'La colonne « %s » est déclarée NULLED_COPY mais n\'est plus dans l\'INSERT de copie (%s).',
                $column,
                $reason,
            ));
            self::assertSame('NULL', strtoupper($exprByColumn[$column]), \sprintf(
                "Le SELECT de copie émet « %s » pour « %s », alors qu'elle doit valoir NULL : %s.\n"
                . 'Si le libellé doit de nouveau être copié, retirer la colonne de NULLED_COPY.',
                $exprByColumn[$column],
                $column,
                $reason,
            ));
        }
    }

    public function testEveryColumnOfTheEntityIsCarriedOverOrExcludedOnPurpose(): void
    {
        $inserted = $this->insertedColumns();
        $mapped = $this->mappedColumns();

        $forgotten = array_values(array_diff($mapped, $inserted, array_keys(self::NOT_COPIED)));

        self::assertSame([], $forgotten, \sprintf(
            "Ces colonnes de venue_training_slot ne sont pas reportées dans la copie de période : %s.\n"
            . "Si elles sont NULLABLE, la copie part sans elles EN SILENCE : la grille de la période\n"
            . "diverge du modèle de saison qu'elle prétend copier, et rien ne le signale.\n"
            . 'Les ajouter à l\'INSERT, ou les déclarer dans self::NOT_COPIED avec leur raison.',
            implode(', ', $forgotten),
        ));
    }

    public function testTheCopyDoesNotInsertColumnsThatNoLongerExist(): void
    {
        $ghosts = array_values(array_diff($this->insertedColumns(), $this->mappedColumns()));

        self::assertSame([], $ghosts, \sprintf(
            'La copie insère des colonnes absentes du mapping : %s. L\'INSERT échouerait à la première période créée.',
            implode(', ', $ghosts),
        ));
    }

    public function testTheProvisionerStillOwnsTheCopy(): void
    {
        self::assertTrue(
            class_exists(SchedulePlanProvisioner::class),
            'SchedulePlanProvisioner a disparu — ce test surveille sa requête de copie.',
        );
    }

    /**
     * Les colonnes réellement écrites par l'`INSERT … SELECT`, lues dans le source : la
     * requête EST la liste — la recopier ici en ferait une seconde source de vérité.
     *
     * @return list<string>
     */
    private function insertedColumns(): array
    {
        $source = file_get_contents(self::PROVISIONER_SOURCE);
        self::assertIsString($source, 'SchedulePlanProvisioner est illisible.');

        $found = preg_match('/INSERT INTO venue_training_slot \(([^)]+)\)/', $source, $matches);
        self::assertSame(1, $found, 'L\'INSERT de copie a disparu ou changé de forme — ce test doit suivre, pas se taire.');

        $columns = array_map(trim(...), explode(',', $matches[1]));
        sort($columns);

        self::assertNotEmpty($columns, 'L\'INSERT de copie ne cite plus aucune colonne.');

        return $columns;
    }

    /**
     * L'INSERT et le SELECT de la copie, appariés colonne → expression dans l'ORDRE du SQL (pas
     * trié : l'appariement positionnel EST le sens). Lu dans le source, jamais recopié ici.
     *
     * @return array<string, string>
     */
    private function selectExpressionByColumn(): array
    {
        $source = file_get_contents(self::PROVISIONER_SOURCE);
        self::assertIsString($source, 'SchedulePlanProvisioner est illisible.');

        // Un seul motif, ancré sur l'INSERT INTO venue_training_slot, pour apparier la liste de
        // colonnes et les expressions du SELECT de CE bloc — le provisioner contient d'autres
        // SELECT (sur schedule_plan, calendar_entry) qu'il ne faut pas confondre avec celui-ci.
        $found = preg_match(
            '/INSERT INTO venue_training_slot \(([^)]+)\)\s*\'\s*\.\s*\'SELECT (.+?)\s*\'\s*\.\s*\'FROM venue_training_slot/s',
            $source,
            $match,
        );
        self::assertSame(1, $found, 'L\'INSERT … SELECT de copie a disparu ou changé de forme — ce test doit suivre, pas se taire.');

        $columns = array_map(trim(...), explode(',', $match[1]));
        $expressions = array_map(trim(...), explode(',', trim($match[2])));

        self::assertCount(
            \count($columns),
            $expressions,
            \sprintf('INSERT (%d colonnes) et SELECT (%d expressions) ne s\'apparient plus un pour un.', \count($columns), \count($expressions)),
        );

        return array_combine($columns, $expressions);
    }

    /** @return list<string> */
    private function mappedColumns(): array
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $columns = $em->getClassMetadata(VenueTrainingSlot::class)->getColumnNames();
        sort($columns);

        return $columns;
    }
}
