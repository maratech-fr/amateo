<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\DeployRunVerdict;

/**
 * Le VERDICT d'un run `deploy.yml` terminé, lu dans ses jobs et dans les steps du job de bascule
 * (`GET /actions/runs/{id}/jobs`) — jamais dans la seule conclusion du run : un run `failure` peut
 * avoir mis la prod à jour (seul le recalage de `release` a raté), un run `success` peut n'avoir
 * rien déployé (job de bascule sauté, déploiement non armé).
 *
 * Les noms ci-dessous sont les SEULS identifiants que l'API GitHub expose : ils doivent coller à
 * `deploy.yml` (gardé par `DeployWorkflowDispatchContractTest`). Le job de bascule a un nom évalué
 * (`Roll the VM to v1.3.3`) : seul son préfixe compte.
 *
 * Règles, dans l'ordre :
 *  - aucun job (run annulé avant tout job) → l'application est restée en service ;
 *  - bascule `success` → prod à jour, avec réserve si `release` n'est pas `success` ;
 *  - run `success`, bascule sautée ou absente → images publiées seulement ;
 *  - bascule ≠ `success` : `SSH deploy` `success` → prod à jour, page de maintenance restée ;
 *    `Raise` `success` → échec sous maintenance ; `Raise` sauté/absent → échec, application en
 *    service ; tout autre état de `Raise` (failure…) → indéterminé (le fichier a pu être écrit).
 */
final readonly class DeployRunOutcome
{
    public const string JOB_BUILD = 'Build & push prod images';

    public const string JOB_DEPLOY_PREFIX = 'Roll the VM to ';

    public const string JOB_RELEASE = 'Fast-forward `release` to the deployed commit';

    public const string STEP_RAISE_MAINTENANCE = 'Raise the maintenance window';

    public const string STEP_SSH_DEPLOY = 'SSH deploy';

    public const string STEP_CLEAR_MAINTENANCE = 'Clear the maintenance window';

    private const string SUCCESS = 'success';

    private const string SKIPPED = 'skipped';

    private function __construct(public DeployRunVerdict $verdict) {}

    /**
     * @param array<mixed> $jobs les jobs du run (`name`, `conclusion`, `steps` : `name`, `conclusion`)
     */
    public static function fromJobs(array $jobs, string $runConclusion): self
    {
        return new self(self::verdictOf($jobs, $runConclusion));
    }

    /** @param array<mixed> $jobs */
    private static function verdictOf(array $jobs, string $runConclusion): DeployRunVerdict
    {
        if ([] === $jobs) {
            return DeployRunVerdict::FAILED_APP_IN_SERVICE;
        }

        $roll = self::findJob($jobs, static fn (string $name): bool => str_starts_with($name, self::JOB_DEPLOY_PREFIX));
        $rollConclusion = null === $roll ? null : self::conclusionOf($roll);

        if (self::SUCCESS === $rollConclusion) {
            $release = self::findJob($jobs, static fn (string $name): bool => self::JOB_RELEASE === $name);
            $releaseConclusion = null === $release ? null : self::conclusionOf($release);

            return self::SUCCESS === $releaseConclusion
                ? DeployRunVerdict::PROD_UPDATED
                : DeployRunVerdict::PROD_UPDATED_RELEASE_NOT_MOVED;
        }

        if (self::SUCCESS === $runConclusion && (null === $roll || self::SKIPPED === $rollConclusion)) {
            return DeployRunVerdict::BUILD_ONLY;
        }

        $steps = null === $roll ? [] : self::stepConclusions($roll);
        if (self::SUCCESS === ($steps[self::STEP_SSH_DEPLOY] ?? null)) {
            return DeployRunVerdict::PROD_UPDATED_MAINTENANCE_LEFT;
        }

        $raise = $steps[self::STEP_RAISE_MAINTENANCE] ?? null;
        if (self::SUCCESS === $raise) {
            return DeployRunVerdict::FAILED_UNDER_MAINTENANCE;
        }
        if (null === $raise || self::SKIPPED === $raise) {
            return DeployRunVerdict::FAILED_APP_IN_SERVICE;
        }

        return DeployRunVerdict::UNDETERMINED;
    }

    /**
     * @param array<mixed>           $jobs
     * @param callable(string): bool $matches
     *
     * @return array<mixed>|null
     */
    private static function findJob(array $jobs, callable $matches): ?array
    {
        foreach ($jobs as $job) {
            if (\is_array($job) && \is_string($job['name'] ?? null) && $matches($job['name'])) {
                return $job;
            }
        }

        return null;
    }

    /** @param array<mixed> $job */
    private static function conclusionOf(array $job): ?string
    {
        $conclusion = $job['conclusion'] ?? null;

        return \is_string($conclusion) ? $conclusion : null;
    }

    /**
     * Conclusion de chaque step du job, par nom (absent = le step n'existe pas dans le run).
     *
     * @param array<mixed> $job
     *
     * @return array<string, ?string>
     */
    private static function stepConclusions(array $job): array
    {
        $steps = $job['steps'] ?? null;
        if (!\is_array($steps)) {
            return [];
        }

        $conclusions = [];
        foreach ($steps as $step) {
            if (\is_array($step) && \is_string($step['name'] ?? null)) {
                $conclusions[$step['name']] = self::conclusionOf($step);
            }
        }

        return $conclusions;
    }
}
