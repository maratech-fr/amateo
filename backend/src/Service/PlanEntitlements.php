<?php

declare(strict_types=1);

namespace App\Service;

use App\Access\DemoRegime;
use App\Entity\Club;
use App\Entity\ClubSubscription;
use App\Entity\Season;
use App\Entity\SubscriptionPlan;
use App\Entity\Team;
use App\Repository\ClubSubscriptionRepository;
use App\Repository\SubscriptionPlanRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * P1-3 PR A — l'offre EFFECTIVE d'un club pour une saison, calculée à la LECTURE (pas de
 * cron). Grille des offres : specs/courantes/etat-des-lieux.md §1.11 (décision fermée
 * 2026-11-11 : Essentiel ≤ 20, Club ≤ 34, Grand club illimité). Règles :
 *   - l'offre d'une saison ne dépend que de SA ligne d'abonnement ({@see ClubSubscription},
 *     clé club + année de la saison lue) : aucune ligne, ligne Découverte ou ligne non réglée
 *     → Découverte ; régler une saison ne couvre aucune autre ;
 *   - club `isDemo` → droits pleins TOUJOURS (exempt de tout gate).
 *
 * Rend le socle de droits (DTO tableau) consommé par /api/me — l'ENFORCEMENT
 * (débit des crédits, cap d'équipes) est la PR B, PAS ici : ce service ne fait que
 * DIRE ce que l'offre autorise.
 */
final readonly class PlanEntitlements
{
    private const string DECOUVERTE_CODE = 'decouverte';

    /** L'échelle des offres PAYANTES, par cap d'équipes croissant — pour nommer le palier supérieur. */
    private const array PAID_LADDER = ['essentiel', 'club', 'grand-club'];

    public function __construct(
        private SubscriptionPlanRepository $plans,
        private EntityManagerInterface $entityManager,
        private DemoRegime $demoRegime,
        private ClubSubscriptionRepository $subscriptions,
    ) {}

    /**
     * La règle PURE de l'offre effective d'une saison (code seul, sur primitives) — MAISON
     * UNIQUE, partagée avec `AdminMonitoringService` (badge de la console SA) et la bascule.
     * Découverte si la saison n'a pas de ligne, si sa ligne est Découverte ou si elle n'est pas
     * réglée ; sinon l'offre de la ligne.
     */
    public static function effectivePlanCode(?string $lineCode, bool $paid): string
    {
        if (null === $lineCode || self::DECOUVERTE_CODE === $lineCode || !$paid) {
            return self::DECOUVERTE_CODE;
        }

        return $lineCode;
    }

    /**
     * La saison d'année $seasonYear est-elle réglée d'une offre autre que Découverte ? C'est ce
     * qu'exige la bascule vers elle : une ligne Découverte, même réglée, ne l'ouvre jamais.
     */
    public function isSeasonPaid(string $clubId, int $seasonYear): bool
    {
        return self::DECOUVERTE_CODE !== $this->effectiveCodeOf($this->subscriptions->forSeasonYear($clubId, $seasonYear));
    }

    /**
     * @return array{
     *     planCode: string,
     *     planName: string,
     *     maxTeams: int|null,
     *     teamsUsed: int,
     *     creditsMax: int|null,
     *     creditsUsed: int,
     *     canGenerate: bool,
     *     canPlaceMatches: bool,
     *     canExportPdf: bool,
     *     seasonTransition: bool
     * }
     */
    public function forClub(Club $club, Season $season): array
    {
        $decouverte = $this->plans->findOneBy(['code' => self::DECOUVERTE_CODE]);
        $effective = $this->effectivePlan($club, $season, $decouverte);

        // Découverte effective = le seul régime bridé. Un club démo n'est JAMAIS
        // bridé, quelle que soit son offre effective.
        $restricted = !$this->demoRegime->isUnlimited($club) && self::DECOUVERTE_CODE === $effective?->getCode();

        // $restricted implique une offre effective non-nulle (code === decouverte),
        // narrowing que PHPStan suit depuis la condition ci-dessus.
        $creditsMax = $restricted ? $this->unlimitedToNull($effective->getMaxGenerations()) : null;
        $creditsUsed = $club->getOutputCreditsUsed();
        // Solde > 0 : en Découverte bridée on n'a de sorties QUE tant qu'il reste des
        // crédits (creditsMax null = illimité => solde toujours ouvert).
        $hasOutput = !$restricted || null === $creditsMax || $creditsUsed < $creditsMax;

        return [
            'planCode' => $effective?->getCode() ?? self::DECOUVERTE_CODE,
            'planName' => $effective?->getName() ?? 'Découverte',
            'maxTeams' => $this->unlimitedToNull($effective?->getMaxTeams() ?? 0),
            'teamsUsed' => $this->teamsUsed($club, $season),
            'creditsMax' => $creditsMax,
            'creditsUsed' => $creditsUsed,
            'canGenerate' => $hasOutput,
            'canPlaceMatches' => $hasOutput,
            'canExportPdf' => $hasOutput,
            // Bascule de saison : ouverte en payant/bêta courant ou en démo, fermée
            // en Découverte effective (le seul interrupteur fermé du gratuit).
            'seasonTransition' => !$restricted,
        ];
    }

    /**
     * P1-3 PR B — le budget de SORTIE (pool de crédits) de l'offre effective, lu par le
     * contrôle central (`ClubAccessListener`) et le registre (`OutputCreditLedger`). Une seule source pour « le club est-il bridé ? » et « combien
     * de crédits ? » — aucun ne réplique la règle.
     *
     * @return array{restricted: bool, max: int, used: int}
     *                                                      restricted = régime Découverte bridé (offre effective Découverte, club NON démo, pool > 0) ;
     *                                                      max = taille du pool (convention 0 = illimité → non bridé) ; used = compteur du club
     */
    public function outputBudget(Club $club, Season $season): array
    {
        $decouverte = $this->plans->findOneBy(['code' => self::DECOUVERTE_CODE]);
        $effective = $this->effectivePlan($club, $season, $decouverte);

        // Même narrowing que forClub : $restricted vrai implique $effective non-null.
        $restricted = !$this->demoRegime->isUnlimited($club) && self::DECOUVERTE_CODE === $effective?->getCode();
        $max = $restricted ? $effective->getMaxGenerations() : 0;

        return [
            // Un pool à 0 = illimité (convention) : pas de bride, même en Découverte.
            'restricted' => $restricted && $max > 0,
            'max' => $max,
            'used' => $club->getOutputCreditsUsed(),
        ];
    }

    /**
     * Le cap d'ÉQUIPES de l'offre effective — null si aucun cap ne s'applique (Découverte,
     * Bêta, Grand club, ou club démo). Centralise la règle des caps payants.
     */
    public function teamCap(Club $club, Season $season): ?int
    {
        if ($this->demoRegime->isUnlimited($club)) {
            return null;
        }
        $decouverte = $this->plans->findOneBy(['code' => self::DECOUVERTE_CODE]);
        $effective = $this->effectivePlan($club, $season, $decouverte);

        return $this->unlimitedToNull($effective?->getMaxTeams() ?? 0);
    }

    /** Places d'équipes restantes avant le cap ; null si illimité (aucun cap). */
    public function remainingTeamSlots(Club $club, Season $season): ?int
    {
        $cap = $this->teamCap($club, $season);
        if (null === $cap) {
            return null;
        }

        return max(0, $cap - $this->teamsUsed($club, $season));
    }

    /**
     * Message de refus si créer $additional équipe(s) de plus franchit le cap payant ; null si
     * OK (ou aucun cap). Nomme l'offre courante ET le palier supérieur — « prends l'offre au
     * niveau où tu es déjà ».
     */
    public function teamCapExceededMessage(Club $club, Season $season, int $additional = 1): ?string
    {
        if ($this->demoRegime->isUnlimited($club)) {
            return null;
        }
        $decouverte = $this->plans->findOneBy(['code' => self::DECOUVERTE_CODE]);
        $effective = $this->effectivePlan($club, $season, $decouverte);
        $cap = $this->unlimitedToNull($effective?->getMaxTeams() ?? 0);
        if (null === $cap || !$effective instanceof SubscriptionPlan) {
            return null;
        }
        if ($this->teamsUsed($club, $season) + $additional <= $cap) {
            return null;
        }

        $next = $this->nextTier($effective->getCode());
        if (!$next instanceof SubscriptionPlan) {
            return \sprintf('Votre offre %s permet %d équipes. Contactez-nous pour en ajouter davantage.', $effective->getName(), $cap);
        }
        if (0 === $next->getMaxTeams()) {
            return \sprintf('Votre offre %s permet %d équipes — l\'offre %s retire la limite.', $effective->getName(), $cap, $next->getName());
        }

        return \sprintf('Votre offre %s permet %d équipes — l\'offre %s monte à %d.', $effective->getName(), $cap, $next->getName(), $next->getMaxTeams());
    }

    /** Toutes les équipes du club dans la saison — inactives comprises (elles comptent au cap). */
    public function teamsUsed(Club $club, Season $season): int
    {
        return $this->entityManager->getRepository(Team::class)
            ->count(['clubId' => $club->getId(), 'seasonId' => $season->getId()]);
    }

    /** L'offre payante immédiatement au-dessus de $code dans l'échelle ; null si déjà au sommet. */
    private function nextTier(string $code): ?SubscriptionPlan
    {
        $index = array_search($code, self::PAID_LADDER, true);
        if (!\is_int($index) || !isset(self::PAID_LADDER[$index + 1])) {
            return null;
        }

        return $this->plans->findOneBy(['code' => self::PAID_LADDER[$index + 1]]);
    }

    private function effectivePlan(Club $club, Season $season, ?SubscriptionPlan $decouverte): ?SubscriptionPlan
    {
        $line = $this->subscriptions->forSeasonYear($club->getId(), SeasonResolver::seasonYear($season->getStartDate()));
        $plan = $line instanceof ClubSubscription ? $this->plans->find($line->getPlanId()) : null;
        if (!$plan instanceof SubscriptionPlan || self::DECOUVERTE_CODE === $this->effectiveCodeOf($line)) {
            return $decouverte;
        }

        return $plan;
    }

    private function effectiveCodeOf(?ClubSubscription $line): string
    {
        if (!$line instanceof ClubSubscription) {
            return self::DECOUVERTE_CODE;
        }

        return self::effectivePlanCode($this->plans->find($line->getPlanId())?->getCode(), $line->isPaid());
    }

    /** 0 en base = illimité → null côté lecture. */
    private function unlimitedToNull(int $value): ?int
    {
        return 0 === $value ? null : $value;
    }
}
