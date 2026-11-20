<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\EngineRequestFingerprint;
use App\Service\EngineRequestRecorder;
use App\Service\TenantConnectionContext;
use Doctrine\DBAL\Connection;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Enregistre sur disque les demandes au moteur de chaque club ({@see EngineRequestRecorder}), au
 * format d'empreintes ({@see EngineRequestFingerprint}) : enregistrer avant une migration, migrer,
 * enregistrer après, `diff -r <avant>/requests <après>/requests`.
 *
 * Arbre : `<out>/requests/club-<id>/<clé>` (un fichier par demande) et `<out>/manifest.json`, hors de
 * l'arbre comparé : `{"clubs": [{"clubId", "requests": [clés], "skipped": [{"scope", "reason"}]}]}`.
 *
 * Sorties : 0 ; 1 une demande atteignable n'a pas pu être composée (nommée) ; 2 `--club` n'est pas un
 * UUID, ou `--out` n'est pas un dossier absent ou vide, ou est un lien symbolique ; 3 la sécurité par club n'est pas effective sur la
 * connexion ; 4 environnement de production. Les refus précèdent toute écriture.
 *
 * Limite : le refus de production ne protège pas d'un processus de développement branché par
 * `DATABASE_URL` sur la base de production.
 */
#[AsCommand(
    name: 'app:engine-requests:record',
    description: 'Record every engine request of the given clubs (all clubs by default) as diffable, name-free fingerprints.',
)]
final class EngineRequestsRecordCommand extends Command
{
    public const int EXIT_NOT_COMPOSED = 1;
    public const int EXIT_INVALID_OPTION = 2;
    public const int EXIT_CLUB_SECURITY_NOT_EFFECTIVE = 3;
    public const int EXIT_PRODUCTION = 4;

    private const string UUID_PATTERN = '/\A[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}\z/';

    public function __construct(
        private readonly EngineRequestRecorder $recorder,
        private readonly TenantConnectionContext $tenantConnectionContext,
        private readonly Connection $connection,
        #[Autowire(param: 'kernel.environment')]
        private readonly string $environment,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('out', null, InputOption::VALUE_REQUIRED, 'Output folder (absent or empty).');
        $this->addOption('club', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Club id (UUID), repeatable. Default: every club.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ('prod' === $this->environment) {
            $io->error('Refusé : jamais en environnement de production.');

            return self::EXIT_PRODUCTION;
        }

        $out = $input->getOption('out');
        if (!\is_string($out) || '' === $out) {
            $io->error('--out=<dossier> est requis.');

            return self::EXIT_INVALID_OPTION;
        }
        if (is_link($out)) {
            $io->error('--out ne doit pas être un lien symbolique : l\'outil n\'écrit jamais là où un lien le mènerait.');

            return self::EXIT_INVALID_OPTION;
        }
        if (file_exists($out) && (!is_dir($out) || [] !== array_diff((array) scandir($out), ['.', '..']))) {
            $io->error('--out doit désigner un dossier absent ou vide : un reste d\'enregistrement fausserait la comparaison.');

            return self::EXIT_INVALID_OPTION;
        }

        /** @var list<mixed> $clubOptions */
        $clubOptions = $input->getOption('club');
        $clubIds = [];
        foreach ($clubOptions as $clubId) {
            if (!\is_string($clubId) || 1 !== preg_match(self::UUID_PATTERN, $clubId)) {
                $io->error('--club doit être un identifiant de club (UUID).');

                return self::EXIT_INVALID_OPTION;
            }
            $clubIds[] = strtolower($clubId);
        }

        // Le club éventuellement posé sur la connexion est relâché le temps de l'outil (la sonde se
        // lit sans club), puis rendu.
        return $this->tenantConnectionContext->runWithoutTenant(function () use ($io, $out, $clubIds): int {
            $refusal = $this->recorder->connectionRefusal();
            if (null !== $refusal) {
                $io->error('Refusé : ' . $refusal . '.');

                return self::EXIT_CLUB_SECURITY_NOT_EFFECTIVE;
            }

            return $this->record($io, $out, [] === $clubIds ? $this->allClubIds() : $clubIds);
        });
    }

    /** @param list<string> $clubIds */
    private function record(SymfonyStyle $io, string $out, array $clubIds): int
    {
        // La racine naît lisible du seul propriétaire, quel que soit l'umask (les empreintes de prénoms
        // se retrouvent par dictionnaire) ; un dossier vide choisi par l'opérateur garde ses droits.
        if (!is_dir($out)) {
            if (!mkdir($out, 0o700, true) && !is_dir($out)) {
                throw new RuntimeException(\sprintf('Dossier impossible à créer : %s', $out));
            }
            chmod($out, 0o700);
        }
        $manifest = [];
        $failed = [];
        foreach ($clubIds as $clubId) {
            $recorded = $this->recorder->requestsOfClub($clubId);
            $clubDir = $out . '/requests/club-' . $clubId;
            foreach ($recorded['requests'] as $key => $request) {
                $this->write($clubDir . '/' . $key, EngineRequestFingerprint::render($request));
            }
            $keys = array_keys($recorded['requests']);
            sort($keys, \SORT_STRING);
            $manifest[] = ['clubId' => $clubId, 'requests' => $keys, 'skipped' => $recorded['skipped']];
            foreach ($recorded['failed'] as $failure) {
                $failed[] = \sprintf('club-%s/%s (%s)', $clubId, $failure['request'], $failure['reason']);
            }
            $io->writeln(\sprintf('club-%s : %d demande(s), %d sautée(s), %d en échec', $clubId, \count($keys), \count($recorded['skipped']), \count($recorded['failed'])));
        }
        $this->write($out . '/manifest.json', json_encode(['clubs' => $manifest], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR) . "\n");

        if ([] !== $failed) {
            $io->error(array_merge(['Demande(s) atteignable(s) non composée(s) :'], $failed));

            return self::EXIT_NOT_COMPOSED;
        }
        $io->success(\sprintf('Enregistré sous %s.', $out));

        return Command::SUCCESS;
    }

    /** @return list<string> */
    private function allClubIds(): array
    {
        // `club` est hors RLS : la liste se lit sans club posé.
        $ids = [];
        foreach ($this->connection->fetchFirstColumn('SELECT id FROM club ORDER BY id') as $id) {
            if (\is_string($id)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    private function write(string $path, string $content): void
    {
        $dir = \dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new RuntimeException(\sprintf('Dossier impossible à créer : %s', $dir));
        }
        if (false === file_put_contents($path, $content)) {
            throw new RuntimeException(\sprintf('Fichier impossible à écrire : %s', $path));
        }
    }
}
