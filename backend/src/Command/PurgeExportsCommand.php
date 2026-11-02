<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\PdfGenerator;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * P4-52 — la rétention des rendus d'export.
 *
 * Les PDF s'écrivent dans le répertoire de `PdfGenerator::outputDir()` (`var/exports`, hors
 * docroot depuis P4-357) et **ne repartaient jamais** : une saison de régénérations laisse autant
 * de fichiers, indéfiniment.
 *
 * ⚑ Le rendu ne se télécharge que par la route AUTHENTIFIÉE confinée au club
 * (`GET /api/schedules/{id}/export-pdf/{filename}`, P4-357) — l'URL porte l'UUID du planning, elle
 * ne se périme pas d'elle-même. La rétention borne donc deux choses d'un coup : le disque, et la
 * durée pendant laquelle un vieux rendu reste atteignable.
 *
 * **Deux motifs de suppression, et un seul filet.**
 *  1. **Orphelin** — le planning n'existe plus. Pure hygiène, aucune décision produit.
 *  2. **Périmé** — plus vieux que la rétention (90 jours), SAUF s'il est pointé par
 *     `Season.exportPdfUrl`.
 *
 * ⚠ Ce second point EST le `is_pinned` du croquis v3, réalisé avec ce qui existe déjà : une
 * saison qui pointe son export l'épingle de fait. Une colonne `is_pinned` dédiée aurait
 * supposé un GESTE d'épinglage — et aucun écran ne l'offre. On n'ajoute pas une capacité que
 * personne ne peut atteindre ; le jour où un gestionnaire demande à figer un rendu précis, la
 * colonne se pose avec son écran.
 *
 * Manuelle comme ses sept sœurs `app:purge-*`, inscrite au catalogue des jobs d'exploitation.
 * Un rendu supprimé se régénère depuis l'écran : la perte est nulle, seul le délai revient.
 */
#[AsCommand(
    name: 'app:exports:purge',
    description: 'Delete orphaned export renders and those older than the retention window (pinned season exports are kept).',
)]
final class PurgeExportsCommand extends Command
{
    /** Jours au-delà desquels un rendu non épinglé part. Un planning se consulte dans les jours qui suivent ; une saison dure dix mois. */
    private const int RETENTION_DAYS = 90;

    /**
     * `schedule-{uuid}-{scope}.{ext}` — le nom EST la seule jointure entre le fichier et sa
     * ligne. Le scope vaut `all` ou les 8 premiers caractères d'un uuid de gymnase
     * (`PdfGenerator`), d'où la classe volontairement étroite : **ni `/` ni `.`** ne peuvent
     * entrer dans un nom accepté. Un `.+` aurait laissé passer `../../etc/passwd.pdf`.
     */
    private const string RENDER_PATTERN = '/^schedule-([0-9a-f-]{36})-[a-z0-9-]+\.pdf$/i';

    /**
     * `matchs-{clubId}-{uuid}.pdf` — la liste des matchs pour les parents. Le fichier part dès la
     * réponse : un survivant est le reste d'un crash, il ne se rattache à aucun planning.
     */
    private const string MATCHES_RENDER_PATTERN = '/^matchs-[0-9a-f-]{36}-[0-9a-f-]{36}\.pdf$/i';

    private const int MATCHES_RENDER_MAX_AGE_SECONDS = 3600;

    public function __construct(
        private readonly ManagerRegistry $managerRegistry,
    ) {
        parent::__construct();
    }

    /**
     * La décision, en une fonction PURE — extraite du parcours de répertoire à dessein.
     *
     * ⚑ Montée dans la commande, elle n'était éprouvable qu'à travers une base : or la
     * commande lit par la connexion `admin`, que la transaction d'un test ne traverse pas.
     * Le test devenait un combat contre le harnais au lieu d'une preuve de la règle. Sortie
     * d'ici, elle se falsifie cas par cas (cf. `retryTarget.ts`, même journée, même leçon).
     *
     * `null` = ce fichier n'est pas un rendu, on passe. `$matchesCutoff` borne les seuls `matchs-*`.
     *
     * @param array<string, true> $knownScheduleIds
     * @param array<string, true> $pinnedUrls
     *
     * @return 'keep'|'orphan'|'expired'|null
     */
    public static function verdictFor(string $name, array $knownScheduleIds, array $pinnedUrls, int $modifiedAt, int $cutoff, int $matchesCutoff = 0): ?string
    {
        if (1 === preg_match(self::MATCHES_RENDER_PATTERN, $name)) {
            return $modifiedAt < $matchesCutoff ? 'expired' : 'keep';
        }

        if (1 !== preg_match(self::RENDER_PATTERN, $name, $matches)) {
            return null;
        }

        // Épinglé : la saison le pointe. Il ne part JAMAIS, quel que soit son âge — c'est le
        // seul rendu qu'un gestionnaire ait une raison de rouvrir des mois plus tard.
        //
        // P4-357 — on compare le BASENAME de chaque URL épinglée, pas l'URL entière : l'URL de
        // production a basculé de la forme statique `/exports/<nom>` à la route authentifiée
        // `/api/schedules/{id}/export-pdf/<nom>`, mais les deux pointent le MÊME fichier `<nom>`.
        // Sans ça, le basculement ferait repartir à la purge un rendu pourtant épinglé.
        foreach ($pinnedUrls as $url => $_) {
            if (basename((string) $url) === $name) {
                return 'keep';
            }
        }

        if (!isset($knownScheduleIds[strtolower($matches[1])])) {
            return 'orphan';
        }

        return $modifiedAt < $cutoff ? 'expired' : 'keep';
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'List what would be purged without deleting anything.');
        $this->addOption('days', null, InputOption::VALUE_REQUIRED, 'Override the retention window (days).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = true === $input->getOption('dry-run');
        $days = $this->retentionDays($input);

        $directory = PdfGenerator::outputDir();
        if (!is_dir($directory)) {
            $io->success('Aucun répertoire de rendus : rien à purger.');

            return Command::SUCCESS;
        }

        // ⚑ La purge est TRANSVERSE : elle balaie les rendus de TOUS les clubs. Elle lit donc
        // par la connexion `admin`, la seule qui contourne RLS — comme les migrations et les
        // autres purges d'exploitation (`PurgeAuditLogCommand`).
        //
        // ⚠ Mesuré, pas supposé : j'avais d'abord tenté `runWithoutTenant()`. C'est l'inverse
        // de ce qu'il fallait — les policies sont en FORCE, donc SANS contexte de club une
        // requête ne rend AUCUNE ligne. `knownScheduleIds()` revenait vide et la purge classait
        // TOUS les rendus en orphelins : elle aurait effacé les exports vivants de tous les
        // clubs en annonçant un succès. C'est le test qui l'a montré, pas la relecture.
        $connection = $this->managerRegistry->getConnection('admin');
        \assert($connection instanceof Connection);

        /** @var list<string> $scheduleRows */
        $scheduleRows = $connection->fetchFirstColumn('SELECT id FROM schedule');
        $knownScheduleIds = array_fill_keys(array_map(strtolower(...), $scheduleRows), true);

        /** @var list<string> $pinnedRows */
        $pinnedRows = $connection->fetchFirstColumn('SELECT export_pdf_url FROM season WHERE export_pdf_url IS NOT NULL');
        $pinnedUrls = array_fill_keys($pinnedRows, true);

        $cutoff = new DateTimeImmutable(\sprintf('-%d days', $days))->getTimestamp();
        $matchesCutoff = time() - self::MATCHES_RENDER_MAX_AGE_SECONDS;
        $orphans = 0;
        $expired = 0;
        $kept = 0;

        foreach ((array) scandir($directory) as $entry) {
            $name = (string) $entry;
            $verdict = self::verdictFor($name, $knownScheduleIds, $pinnedUrls, (int) @filemtime($directory . '/' . $name), $cutoff, $matchesCutoff);
            if (null === $verdict) {
                continue;
            }
            if ('keep' === $verdict) {
                ++$kept;
                continue;
            }

            'orphan' === $verdict ? ++$orphans : ++$expired;
            $io->writeln(\sprintf('  %s %s', $dryRun ? '[dry-run]' : 'supprimé', $name));
            if (!$dryRun) {
                $this->deleteInside($directory, $name);
            }
        }

        $io->success(\sprintf(
            '%s : %d orphelin(s), %d périmé(s) au-delà de %d jours, %d conservé(s).',
            $dryRun ? 'Simulation' : 'Purge',
            $orphans,
            $expired,
            $days,
            $kept,
        ));

        return Command::SUCCESS;
    }

    private function retentionDays(InputInterface $input): int
    {
        $raw = $input->getOption('days');

        return \is_string($raw) && ctype_digit($raw) && (int) $raw > 0 ? (int) $raw : self::RETENTION_DAYS;
    }

    /**
     * Supprime un rendu, en PROUVANT d'abord qu'il est bien dans le répertoire d'exports.
     *
     * ⚑ Semgrep signale tout `unlink()` dont le chemin n'est pas littéral
     * (`php.lang.security.unlink-use`), et il a raison de le faire : une suppression pilotée
     * par une chaîne est le vecteur classique de traversée. Ici l'alerte est un FAUX POSITIF,
     * mais on ne le déclare pas sur parole — trois bornes le rendent vrai :
     *
     *  1. `$name` vient de `scandir()`, qui rend des entrées NUES : jamais de séparateur ;
     *  2. il a passé `RENDER_PATTERN`, dont la classe de caractères exclut `/` et `.` ;
     *  3. et surtout, on RÉSOUT le chemin et on vérifie qu'il reste sous le répertoire —
     *     seule borne qui tienne encore si quelqu'un relâche le motif un jour.
     *
     * La règle du projet est de ne jamais élargir `.semgrepignore` pour faire passer la CI :
     * l'exemption est locale, nommée, et adossée à un contrôle réel.
     */
    private function deleteInside(string $directory, string $name): void
    {
        $base = realpath($directory);
        $target = realpath($directory . '/' . basename($name));
        if (false === $base || false === $target || !str_starts_with($target, $base . '/')) {
            return;
        }

        @unlink($target); // nosemgrep: php.lang.security.unlink-use.unlink-use
    }
}
