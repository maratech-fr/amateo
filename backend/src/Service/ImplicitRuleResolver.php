<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ImplicitRuleSetting;
use App\Enum\ImplicitRuleIntensity;
use App\Enum\ImplicitRuleKey;
use App\Repository\ImplicitRuleSettingRepository;

/**
 * RÉSOUT les 4 règles implicites « bien-être » d'un club+saison — stocké OU défaut.
 *
 * Source UNIQUE de « ce que valent les règles » : le bloc `implicitRules` du payload solveur
 * ET la collection GET de l'API en dérivent tous deux, ce qui rend « stocké == émis » vrai par
 * construction (gardé par `ImplicitRulePayloadParityTest`). Une clé sans ligne stockée retombe
 * sur le défaut du contrat (`HARD`, seuils historiques) — d'où « absence = défaut, aucun
 * seeding ».
 *
 * La forme rendue par `resolve()` EST celle du bloc payload : `{ruleKey: {intensity, <seuil?>}}`
 * — c'est ce que le moteur lit (aliases Pydantic), donc aucun remappage entre stockage et
 * émission.
 */
final class ImplicitRuleResolver
{
    public function __construct(
        private readonly ImplicitRuleSettingRepository $repository,
    ) {}

    /**
     * Le bloc RÉSOLU tout au défaut — sans base, pour le chemin `build()` en mémoire (tests de
     * contrat cross-stack) où aucun club+saison n'est en jeu.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function defaults(): array
    {
        $resolved = [];
        foreach (ImplicitRuleKey::cases() as $ruleKey) {
            // Une règle opt-in ABSENTE du bloc vaut « non appliquée » : l'émettre avec un
            // défaut dur reviendrait à l'activer chez tout le monde en silence (P2-42).
            if ($ruleKey->isOptIn()) {
                continue;
            }
            $resolved[$ruleKey->value] = self::defaultEntry($ruleKey);
        }

        return $resolved;
    }

    /**
     * L'entrée d'AFFICHAGE d'une règle opt-in que le club n'a pas réglée.
     *
     * ⚑ P2-42 — deux besoins opposés qu'il faut distinguer, sans quoi la règle est
     * inutilisable : le PAYLOAD doit l'omettre (le moteur lit l'absence comme « inactive »),
     * mais l'ÉCRAN doit la montrer, sinon le gestionnaire ne peut jamais l'activer. D'où
     * cette entrée `OFF` servie à l'API et jamais au moteur.
     *
     * @return array<string, mixed>
     */
    public static function inactiveEntry(ImplicitRuleKey $ruleKey): array
    {
        $entry = ['intensity' => 'OFF'];
        $paramKey = $ruleKey->paramKey();
        if (null !== $paramKey) {
            $entry[$paramKey] = $ruleKey->paramDefault();
        }

        return $entry;
    }

    /** @return array<string, mixed> */
    private static function defaultEntry(ImplicitRuleKey $ruleKey): array
    {
        return self::entryFrom($ruleKey, ImplicitRuleIntensity::HARD, null);
    }

    /**
     * @param array<string, mixed>|null $params
     *
     * @return array<string, mixed>
     */
    private static function entryFrom(ImplicitRuleKey $ruleKey, ImplicitRuleIntensity $intensity, ?array $params): array
    {
        $entry = ['intensity' => $intensity->value];

        $paramKey = $ruleKey->paramKey();
        if (null !== $paramKey) {
            $stored = $params[$paramKey] ?? null;
            $entry[$paramKey] = \is_int($stored) ? $stored : $ruleKey->paramDefault();
        }

        return $entry;
    }

    /**
     * Le bloc RÉSOLU des 4 règles pour un club+saison : réglages stockés par-dessus les défauts.
     *
     * @return array<string, array<string, mixed>>
     */
    public function resolve(string $clubId, string $seasonId): array
    {
        return $this->resolveFromIndexed($this->repository->findByClubSeasonIndexed($clubId, $seasonId));
    }

    /**
     * Le bloc RÉSOLU des 4 règles pour un PLAN de période (ADR-0002 inv. 5).
     *
     * Un plan né après la fonctionnalité porte ses 4 lignes (copie prise à la naissance) : on
     * les résout. Un plan LEGACY (aucune ligne) retombe sur la portée SAISON — repli VIVANT,
     * byte-identique au comportement d'avant la fonctionnalité (décisif : rien ne change sous
     * les pieds d'une reprise déjà en cours de travail).
     *
     * @return array<string, array<string, mixed>>
     */
    public function resolveForPlan(string $clubId, string $seasonId, string $schedulePlanId): array
    {
        $stored = $this->repository->findByPlanIndexed($clubId, $seasonId, $schedulePlanId);
        if ([] === $stored) {
            return $this->resolve($clubId, $seasonId);
        }

        return $this->resolveFromIndexed($stored);
    }

    /**
     * @param array<string, ImplicitRuleSetting> $stored
     *
     * @return array<string, array<string, mixed>>
     */
    private function resolveFromIndexed(array $stored): array
    {
        $resolved = [];
        foreach (ImplicitRuleKey::cases() as $ruleKey) {
            $setting = $stored[$ruleKey->value] ?? null;
            if (null === $setting) {
                // Opt-in non réglée : elle n'entre PAS dans le payload (P2-42). Le moteur
                // lit l'absence comme « règle inactive », pas comme « défaut dur ».
                if ($ruleKey->isOptIn()) {
                    continue;
                }
                $resolved[$ruleKey->value] = self::defaultEntry($ruleKey);
                continue;
            }
            // P4-340 — une opt-in RÉSOLUE OFF (copie de plan matérialisée OFF pour un club neuf,
            // ou opt-in stockée OFF en saison) est SAUTÉE, tout comme une opt-in absente : la
            // sérialiser émettrait `intensity:"OFF"`, que le moteur refuse (422 Pydantic — il
            // n'accepte que HARD/PREFERRED et lit l'absence comme « inactive »). Le PUT garde
            // l'affichage inactif via le repli `inactiveEntry()`.
            if ($ruleKey->isOptIn() && ImplicitRuleIntensity::OFF === $setting->getIntensity()) {
                continue;
            }
            $resolved[$ruleKey->value] = self::entryFrom($ruleKey, $setting->getIntensity(), $setting->getParams());
        }

        return $resolved;
    }
}
