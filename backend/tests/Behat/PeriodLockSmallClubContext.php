<?php

declare(strict_types=1);

namespace App\Tests\Behat;

use Behat\Hook\AfterScenario;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use RuntimeException;

/**
 * P1/P3 — un verrou HARD de PLAN DE PÉRIODE est souverain à la régénération (axe planning
 * lifecycle, ADR-0002), sur le PETIT CLUB réaliste ({@see SmallClubFixtureTrait}, palier 60 s).
 *
 * Trois promesses distinctes du cycle de vie des plans de période, chacune de bout en bout contre
 * la vraie API et le vrai moteur (rail async Messenger → worker → moteur → import) :
 *   - une version overlay se génère sur la grille PROPRE de la période et ses verrous HARD
 *     survivent à « Régénérer » : la version nouvelle repart des DONNÉES EN COURS et les réépingle ;
 *   - « Régénérer » repart TOUJOURS de la dernière version terminée — même à la deuxième
 *     régénération, les verrous ne se perdent pas d'une génération à l'autre ;
 *   - « Charger » fait des verrous d'une version plus ANCIENNE les données en cours : la
 *     régénération qui suit repart de SES verrous, non de la version la plus récente.
 *
 * Remplace, sur un décor léger (palier 60 s au lieu de ~600 s sur le BCCL complet), les trois
 * scénarios de période de `un-verrou-est-souverain.feature` (régénération d'un plan de période)
 * ET le smoke « une génération overlay aboutit » de `plan-de-periode-en-overlay.feature:9` —
 * l'aboutissement overlay est prouvé par le décor de chaque scénario (la version source COMPLETED).
 *
 * Tout est bâti et jeté PAR L'API : le teardown du petit club ({@see SmallClubFixtureTrait}) purge
 * le workspace entier (plan de période, entrée, versions, verrous compris), aucune restauration de
 * pointeur de seed n'est nécessaire — contrairement aux contexts BCCL qu'il remplace.
 */
final class PeriodLockSmallClubContext extends BaseContext
{
    use SmallClubFixtureTrait;

    private string $periodPlanId = '';

    /** Version dont on verrouille des séances libres (overlay source, ou v1 ancienne pour « Charger »). */
    private string $sourceVersionId = '';

    /** @var list<array{teamId: string, venueId: string, day: int, start: string}> */
    private array $lockedCells = [];

    private string $lastRegeneratedId = '';

    private string $finalStatus = '';

    #[Given('un petit club réaliste dont le planning de saison est en vigueur, avec un plan de période portant une version overlay terminée')]
    public function unPetitClubAvecPlanDePeriodeOverlay(): void
    {
        $this->buildSmallClub();
        // Règle de bien-être active (soft) AVANT la naissance du plan : sans elle, le plan matérialise
        // maxConsecutiveDays à OFF et le moteur refuse l'overlay (422) — cf. le docblock du trait.
        $this->configureSmallClubMaxConsecutiveDaysRule();
        $this->generateAndValidateSmallClubSocle();

        $start = $this->smallClubFutureMonday(49);
        $end = date('Y-m-d', (int) strtotime($start . ' +4 days'));
        $entryId = $this->createSmallClubClosureEntry($start, $end, 'Fermeture — verrou de période (petit club)');
        $this->periodPlanId = $this->openSmallClubPeriodPlan($entryId);

        // La génération overlay doit aboutir sur la grille PROPRE de la période (remplace
        // `plan-de-periode-en-overlay.feature:9`) : la version source COMPLETED l'atteste.
        $overlay = $this->generateSmallClubPeriodVersion($this->periodPlanId);
        if ('COMPLETED' !== $overlay['status']) {
            throw new RuntimeException(\sprintf('la génération overlay de période aurait dû aboutir (COMPLETED), obtenu « %s »', $overlay['status']));
        }
        $this->sourceVersionId = $overlay['id'];
    }

    #[Given('un petit club réaliste dont le planning de saison est en vigueur, avec un plan de période jetable portant une version transcrite plus ancienne que les données en cours')]
    public function unPetitClubAvecPlanDePeriodeJetableAncienne(): void
    {
        $this->buildSmallClub();
        $this->configureSmallClubMaxConsecutiveDaysRule();
        $this->generateAndValidateSmallClubSocle();

        $start = $this->smallClubFutureMonday(49);
        $end = date('Y-m-d', (int) strtotime($start . ' +4 days'));
        $entryId = $this->createSmallClubClosureEntry($start, $end, 'Fermeture — Charger les verrous (petit club)');
        $this->periodPlanId = $this->openSmallClubPeriodPlan($entryId);

        // v1 = transcription du socle (COMPLETED sans solve), la version plus ANCIENNE que « Charger »
        // désignera. On verrouille sur ELLE.
        $this->sourceVersionId = $this->transcribeSmallClubSocleToPlan($this->periodPlanId);

        // La transcription verrouille HARD (origine MANUAL) TOUTE séance copiée : pour éprouver
        // « verrouiller des séances LIBRES », on en DÉVERROUILLE deux (→ NONE sur v1).
        $toFree = $this->dbalScalar(
            \sprintf(
                'SELECT string_agg(id::text, \',\') AS behatval FROM ('
                . ' SELECT id FROM schedule_slot_template WHERE schedule_id=\'%s\' AND lock_level=\'HARD\' AND lock_origin=\'MANUAL\' ORDER BY id LIMIT 2'
                . ') q',
                $this->sourceVersionId,
            ),
            admin: true,
        );
        $freeIds = '' === $toFree ? [] : array_values(array_filter(explode(',', $toFree), static fn (string $v): bool => 1 === preg_match('/^[0-9a-f-]{36}$/i', $v)));
        if (2 !== \count($freeIds)) {
            throw new RuntimeException('la transcription du socle aurait dû porter au moins deux séances HARD (MANUAL) à déverrouiller');
        }
        foreach ($freeIds as $slotId) {
            $unlocked = $this->apiPost(\sprintf('schedule-slots/%s/manual-edit/lock', $slotId), ['lockLevel' => 'NONE'], $this->smallClubToken);
            if (200 !== $unlocked['status']) {
                throw new RuntimeException(\sprintf('le déverrouillage d\'une séance transcrite a répondu %d (200 attendu)', $unlocked['status']));
            }
        }

        // Une régénération → v2, qui devient les DONNÉES EN COURS. Sans « Charger », la régénération
        // finale repartirait de v2 et perdrait les verrous de v1 : c'est ce que le scénario éprouve.
        $v2 = $this->generateSmallClubPeriodVersion($this->periodPlanId);
        if ('COMPLETED' !== $v2['status']) {
            throw new RuntimeException(\sprintf('la version « données en cours » aurait dû aboutir (COMPLETED), obtenu « %s »', $v2['status']));
        }
    }

    #[Given('deux séances libres de cette version du petit club verrouillées en dur')]
    public function deuxSeancesLibresVerrouillees(): void
    {
        $this->lockedCells = $this->lockFreeSmallClubSessions($this->sourceVersionId, 2);
    }

    #[When('je charge les verrous de cette version du petit club')]
    public function jeChargeLesVerrous(): void
    {
        $loaded = $this->apiPost(\sprintf('schedules/%s/load-locks', $this->sourceVersionId), [], $this->smallClubToken);
        if (200 !== $loaded['status']) {
            throw new RuntimeException(\sprintf('« Charger » les verrous a répondu %d (200 attendu)', $loaded['status']));
        }
    }

    #[When('je régénère le plan de période du petit club')]
    #[When('je régénère encore le plan de période du petit club')]
    public function jeRegenereLePlanDePeriode(): void
    {
        $overlay = $this->generateSmallClubPeriodVersion($this->periodPlanId);
        $this->lastRegeneratedId = $overlay['id'];
        $this->finalStatus = $overlay['status'];
    }

    #[Then('la régénération aboutit et les deux séances reviennent verrouillées, aux mêmes cases')]
    public function lesDeuxSeancesReviennentVerrouillees(): void
    {
        if ('COMPLETED' !== $this->finalStatus) {
            throw new RuntimeException(\sprintf('la régénération du plan de période aurait dû aboutir (COMPLETED), obtenu « %s »', $this->finalStatus));
        }
        if ([] === $this->lockedCells) {
            throw new RuntimeException('aucune séance verrouillée à vérifier — le décor a-t-il bien verrouillé ?');
        }
        if (!$this->smallClubSessionsStillLockedAt($this->lastRegeneratedId, $this->lockedCells)) {
            throw new RuntimeException('une séance verrouillée d\'un plan de période aurait dû revenir VERROUILLÉE (HARD) à la même case après régénération');
        }
    }

    #[AfterScenario]
    public function nettoyer(): void
    {
        $this->teardownSmallClub();
    }
}
