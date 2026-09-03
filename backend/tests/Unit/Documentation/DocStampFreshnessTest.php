<?php

declare(strict_types=1);

namespace App\Tests\Unit\Documentation;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * AUD-DOC-04 — un « Last verified » qui ment est PIRE que pas de stamp du tout.
 *
 * Le motif a survécu à SIX éditions d'audit (2026-07-03 → 2026-08-08) parce qu'il ne portait
 * pas sur le fond : au 2026-08-08, 9 fichiers sur 16 portaient un stamp antérieur à leur
 * dernière édition, et pourtant AUCUN n'avait un contenu périmé — chacun avait été recalé par
 * la PR qui livrait sa feature. Seul le stamp restait figé. Personne ne pouvait le voir en
 * lisant le fichier : il fallait comparer une date à l'historique git.
 *
 * D'où ce garde. La propriété est mécanique et ne juge PAS la qualité du contenu :
 *
 *     un fichier édité APRÈS la date de son stamp a un stamp qui ment.
 *
 * Il ne prouve donc pas qu'un fichier est exact — il prouve que sa date n'est pas déjà
 * contredite par git. C'est le seul volet automatisable ; la relecture, elle, reste humaine
 * (cf. `specs/README.md` §« La règle du stamp », qui distingue « re-vérifié contre le code »
 * de « recalé par les livraisons »).
 *
 * ⚠ Quand ce test rougit, le réflexe N'EST PAS de bumper la date : c'est de regarder ce que
 * les commits ont changé (`git log --since=<stamp> -- <fichier>`), de vérifier ce point,
 * PUIS de dater en disant laquelle des deux formes s'applique. Bumper sans vérifier
 * transforme une promesse vague en fausse garantie — le mensonge devient crédible.
 */
#[Group('phase1')]
final class DocStampFreshnessTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../../..';

    /**
     * Les documents dont la fraîcheur engage un agent qui les lit sans relire le code.
     *
     * ⚑ AUD-DOC-31 (2026-08-19) — les `<zone>/docs/` sont entrés ici APRÈS coup, et c'est la
     * leçon : la migration doc du 2026-08-18 a sorti six documents de `specs/courantes/` vers
     * leur zone, et ils ont quitté ce garde EN SILENCE. Dont `backend/docs/backend-inventory.md`,
     * le récidiviste cinq fois de DOC-04 — la protection durement acquise contre lui s'était
     * rétractée sur lui, sans que rien ne le dise. Déplacer un fichier peut le faire sortir
     * d'un filet : le filet doit suivre.
     *
     * Des GLOBS de zone, et non une liste : un doc de zone créé demain est surveillé d'office.
     * Les fichiers encore sans stamp sont exemptés NOMMÉMENT ci-dessous — visibles, comptés,
     * et à résorber (P4-120), plutôt qu'invisibles.
     */
    private const array WATCHED = [
        'specs/courantes/*.md',
        'specs/README.md',
        'docs/project-map.md',
        'docs/testing/testing-strategy.md',
        'backend/docs/*.md',
        'engine/docs/*.md',
        'frontend/docs/*.md',
    ];

    /**
     * Exemptions NOMINATIVES, avec leur raison — jamais une famille de fichiers.
     *
     * @var array<string, string>
     */
    private const array EXEMPT = [
        // Journal : chaque LIGNE porte déjà sa date de livraison. Un stamp global y serait
        // redondant, et il mentirait à chaque nouvelle trace ajoutée.
        'specs/courantes/etat-des-lieux.md' => 'journal de traces datées ligne par ligne',
        // Snapshot généré : sa fraîcheur se prouve par le snapshot lui-même, pas par une date.
        'specs/courantes/openapi-snapshot.json' => 'artefact généré, pas de la prose',
        // ── P4-120 SOLDÉE le 2026-08-22 : les 12 docs de zone « jamais stampés » ont tous
        // gagné leur stamp par une vérification contre le code (19 faits faux corrigés en
        // chemin — trace état des lieux). Aucune ligne ne s'AJOUTE ici : un doc de zone
        // neuf naît avec son stamp, et une exemption nouvelle exige sa raison structurelle.
    ];

    public function testEveryWatchedDocumentCarriesAStamp(): void
    {
        $missing = [];
        foreach ($this->watchedFiles() as $relative => $absolute) {
            if (null === $this->stampOf($absolute)) {
                $missing[] = $relative;
            }
        }

        self::assertSame([], $missing, \sprintf(
            "Ces documents n'ont pas de ligne « Last verified @ YYYY-MM-DD » :\n  - %s\n"
            . 'Ajoutez-la sous le titre, ou exemptez le fichier NOMMÉMENT dans self::EXEMPT avec sa raison.',
            implode("\n  - ", $missing),
        ));
    }

    public function testNoStampIsOlderThanTheLastEditOfItsFile(): void
    {
        $this->skipIfHistoryIsUnusable();

        $liars = [];
        foreach ($this->watchedFiles() as $relative => $absolute) {
            $stamp = $this->stampOf($absolute);
            if (null === $stamp) {
                continue; // couvert par l'autre test — un seul échec par cause.
            }

            $lastEdit = $this->lastCommitDate($relative);
            if (null !== $lastEdit && !$this->stampCoversEdit($stamp, $lastEdit)) {
                $liars[] = \sprintf('%s (stamp=%s, dernière édition=%s)', $relative, $stamp, $lastEdit);
            }
        }

        self::assertSame([], $liars, \sprintf(
            "Ces stamps sont antérieurs à la dernière édition de leur fichier :\n  - %s\n"
            . "NE PAS se contenter de bumper la date. Pour chacun : `git log --since=<stamp> -- <fichier>`,\n"
            . "vérifier ce que ces commits ont changé, PUIS dater en nommant ce qui a été recalé\n"
            . '(cf. specs/README.md §« La règle du stamp »).',
            implode("\n  - ", $liars),
        ));
    }

    public function testAStampCoversAnEditOfTheSameDayOrTheNextDayOnly(): void
    {
        self::assertTrue($this->stampCoversEdit('2026-09-03', '2026-09-03'), 'même jour');
        self::assertTrue($this->stampCoversEdit('2026-09-03', '2026-09-04'), 'squash-merge après minuit (#840)');
        self::assertTrue($this->stampCoversEdit('2026-09-03', '2026-09-01'), 'stamp postérieur à l\'édition');
        self::assertFalse($this->stampCoversEdit('2026-09-03', '2026-09-05'), 'deux jours : le stamp ment');
        self::assertFalse($this->stampCoversEdit('2026-08-31', '2026-09-02'), 'changement de mois, deux jours');
        self::assertTrue($this->stampCoversEdit('2026-08-31', '2026-09-01'), 'changement de mois, un jour');
    }

    /** @return array<string, string> chemin relatif au dépôt => chemin absolu */
    private function watchedFiles(): array
    {
        $files = [];
        foreach (self::WATCHED as $pattern) {
            foreach (glob(self::ROOT . '/' . $pattern) ?: [] as $absolute) {
                $relative = $this->relativePath($absolute);
                if (!isset(self::EXEMPT[$relative])) {
                    $files[$relative] = $absolute;
                }
            }
        }
        ksort($files);

        self::assertNotEmpty($files, 'Aucun document surveillé trouvé — self::WATCHED pointe-t-il encore quelque part ?');

        return $files;
    }

    /**
     * Un stamp couvre une édition si elle n'est pas postérieure au LENDEMAIN du stamp.
     *
     * Tolérance J+1 (P4-170, 2026-09-04) : la date git d'un fichier est celle du commit qui
     * l'a posé sur `main`, et sur une squash-merge c'est l'heure du merge, pas celle de
     * l'édition. La PR #840 a été mergée le 2026-09-04 à 00:23 : quatre fichiers stampés
     * « 2026-09-03 » (vérifiés et datés le 03, à raison) sont devenus des « menteurs » à
     * minuit, et `unit-tests` a rougi sur `main` sans qu'aucun contenu n'ait bougé. Un jour
     * de tolérance ne cache rien de ce que ce garde vise (des stamps en retard de semaines) ;
     * il retire seulement le faux rouge de bord de journée.
     */
    private function stampCoversEdit(string $stamp, string $lastEdit): bool
    {
        $stampDay = new DateTimeImmutable($stamp);
        $editDay = new DateTimeImmutable($lastEdit);

        return $editDay <= $stampDay->modify('+1 day');
    }

    private function relativePath(string $absolute): string
    {
        $root = realpath(self::ROOT);
        self::assertIsString($root);

        return ltrim(str_replace($root, '', (string) realpath($absolute)), '/');
    }

    /** La date du stamp au format Y-m-d, ou null si le fichier n'en porte pas. */
    private function stampOf(string $absolute): ?string
    {
        $contents = file_get_contents($absolute);
        self::assertIsString($contents, \sprintf('Illisible : %s', $absolute));

        return 1 === preg_match('/Last verified @ (\d{4}-\d{2}-\d{2})/', $contents, $m)
            ? $m[1]
            : null;
    }

    /** La date du dernier commit ayant touché le fichier, ou null si l'historique n'en sait rien. */
    private function lastCommitDate(string $relative): ?string
    {
        $git = new Process(['git', 'log', '-1', '--format=%ad', '--date=short', '--', $relative], self::ROOT);
        $git->run();

        $date = trim($git->getOutput());

        return 1 === preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : null;
    }

    /**
     * Un clone superficiel ne connaît qu'un commit : `git log -- <fichier>` y est muet pour
     * presque tout, et le test passerait au vert en ne regardant rien. Mieux vaut un skip
     * bruyant qu'un faux vert — le job qui lance ce test doit poser `fetch-depth: 0`.
     */
    private function skipIfHistoryIsUnusable(): void
    {
        $inRepo = new Process(['git', 'rev-parse', '--is-inside-work-tree'], self::ROOT);
        $inRepo->run();
        if (!$inRepo->isSuccessful()) {
            self::markTestSkipped('Pas de dépôt git exploitable ici — la fraîcheur des stamps ne peut pas être mesurée.');
        }

        $shallow = new Process(['git', 'rev-parse', '--is-shallow-repository'], self::ROOT);
        $shallow->run();
        if ('true' === trim($shallow->getOutput())) {
            self::markTestSkipped(
                'Clone superficiel : ajoutez `fetch-depth: 0` au checkout du job qui lance ce test, '
                . 'sinon ce garde ne voit rien.',
            );
        }
    }
}
