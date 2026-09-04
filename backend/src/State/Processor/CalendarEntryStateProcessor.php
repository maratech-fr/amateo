<?php

declare(strict_types=1);

namespace App\State\Processor;

use App\ApiResource\CalendarEntryResource;
use App\Dto\CalendarEntryInput;
use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\CoachWish;
use App\Entity\CoachWishCampaign;
use App\Entity\Constraint;
use App\Entity\PeriodReminderLog;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\CalendarEntryStatus;
use App\Repository\SchoolHolidayPeriodRepository;
use App\Service\CalendarEntryRedatability;
use App\Service\HolidayWorkweekRule;
use App\Service\ManagementAccessGuard;
use App\Service\OverlayManager;
use App\Service\PeriodWindowUniquenessGuard;
use App\Service\SchedulePlanProvisioner;
use App\Service\SeasonAccessGuard;
use App\Service\SeasonResolver;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * @extends AbstractStateProcessor<CalendarEntry, CalendarEntryInput, CalendarEntryResource>
 *
 * Palier A: writing a dated Constraint (Constraint.calendarEntryId) never touches
 * the BASE generation payload — dated constraints are excluded by
 * ConstraintRepository::findPermanentByClubSeason. Palier B: overlay generation
 * reads dated constraints via ScheduleConstraintBuilder::buildForOverlay, which
 * BYPASSES the schedule-input cache entirely, so no invalidation is needed here.
 */
class CalendarEntryStateProcessor extends AbstractStateProcessor
{
    public function __construct(
        EntityManagerInterface $entityManager,
        RequestStack $requestStack,
        SeasonResolver $seasonResolver,
        SeasonAccessGuard $seasonAccessGuard,
        ManagementAccessGuard $managementAccessGuard,
        private readonly OverlayManager $overlayManager,
        private readonly SchedulePlanProvisioner $schedulePlanProvisioner,
        private readonly PeriodWindowUniquenessGuard $windowUniquenessGuard,
        private readonly SchoolHolidayPeriodRepository $schoolHolidayRepository,
        private readonly CalendarEntryRedatability $redatability,
    ) {
        parent::__construct($entityManager, $requestStack, $seasonResolver, $seasonAccessGuard, $managementAccessGuard);
    }

    protected function getEntityClass(): string
    {
        return CalendarEntry::class;
    }

    /**
     * @param CalendarEntryInput $input
     */
    protected function createEntityFromInput(object $input): CalendarEntry
    {
        $entity = new CalendarEntry;
        $entity->setKind($this->parseKind($input->kind));
        $entity->setTitle($input->title ?? '');
        $entity->setStartDate($this->parseDate($input->startDate));
        $entity->setEndDate($this->parseDate($input->endDate));
        $entity->setIsDisruptive($input->isDisruptive ?? false);
        $entity->setPeriodType($this->parsePeriodType($input->periodType));
        // Un lien explicite est respecté ; sinon on l'auto-résout depuis les vacances
        // scolaires de la zone du club (une seule vérité serveur — le front n'arbitre pas).
        $entity->setSchoolHolidayId($input->schoolHolidayId ?? $this->autoLinkedHolidayId($input));
        $entity->setParentEntryId($input->parentEntryId);
        $entity->setStatus($this->parseStatus($input->status));
        $entity->setCreatedBy($input->createdBy);

        return $entity;
    }

    /**
     * @param CalendarEntry      $entity
     * @param CalendarEntryInput $input
     */
    protected function updateEntityFromInput(object $entity, object $input): void
    {
        // A period carrying a generated overlay cannot change identity: the overlay
        // was built for THIS kind/periodType/window. Mutating any of them would leave
        // the overlay semantically wrong (or crash the next regeneration in
        // buildForOverlay). Title/status/isDisruptive edits stay allowed.
        //
        // La garde porte sur « cette période a-t-elle un PLAN, ou des SEMAINES ? »
        // (amendement ADR-0002 2026-07-24 : le plan naît du geste d'ADAPTER, plus de la
        // matérialisation — une période peut donc exister sans plan). Deux gels :
        // - un plan existe → l'identité de la période se choisit à la création et se
        //   corrige en supprimant/recréant (l'UI n'expose aucun PUT) ;
        // - des semaines-enfants existent → même gel SANS plan : re-dater/re-typer la
        //   mère déplacerait la couverture sous les semaines, et un PUT ne doit jamais
        //   re-minter un plan-bloc sur une mère découpée (anti-résurrection).
        //
        // Ce choix rend inatteignables, PAR CONSTRUCTION, deux défauts que les rounds 1
        // et 2 du code-review avaient trouvés : la rétrogradation qui détruit un plan, et
        // la fenêtre du plan qui se périme quand on corrige les dates de sa période. Une
        // machinerie de synchronisation les réparait ; ne pas les laisser exister est plus
        // sûr. Un cutoff/mutualisation (sans plan) reste librement promouvable.
        if ($this->schedulePlanProvisioner->periodPlanExists($entity->getId()) || $this->hasWeekChildren($entity->getId())) {
            $kindChanged = null !== $input->kind && $this->parseKind($input->kind) !== $entity->getKind();
            $periodTypeChanged = null !== $input->periodType && $this->parsePeriodType($input->periodType) !== $entity->getPeriodType();
            $startChanged = null !== $input->startDate && $this->parseDate($input->startDate)->format('Y-m-d') !== $entity->getStartDate()->format('Y-m-d');
            $endChanged = null !== $input->endDate && $this->parseDate($input->endDate)->format('Y-m-d') !== $entity->getEndDate()->format('Y-m-d');
            // schoolHolidayId fait partie de l'identité : il dit QUELLES vacances la
            // période adapte, et le cockpit apparie ses cartes dessus (RadarPanel,
            // DayDialog). Le remapper laisserait la carte Toussaint proposer « Adapter »
            // sur le plan bâti pour février.
            $holidayChanged = null !== $input->schoolHolidayId && $input->schoolHolidayId !== $entity->getSchoolHolidayId();

            // D3 v1 (décision fondateur 2026-09-04) — une racine CLOSURE à plan « d'un bloc »
            // DÉGÈLE sa fenêtre : le plan est un gabarit hebdo SANS dates, re-dater un incident
            // « d'un bloc » déplace deux dates sans rien orpheliner (le déplacement du plan, des
            // contraintes appariées et du titre est orchestré par processPut). Le reste de son
            // identité (kind, type, vacances) reste figé, et TOUS les autres cas (racine HOLIDAY
            // liée au référentiel, mère découpée, semaine-enfant) gardent aussi leur fenêtre gelée.
            // LE prédicat de re-databilité vit dans un seul foyer, partagé avec le mapping de sortie.
            $redatableClosureRoot = $this->redatability->isRedatable($entity);
            $windowFrozen = ($startChanged || $endChanged) && !$redatableClosureRoot;

            if ($kindChanged || $periodTypeChanged || $windowFrozen || $holidayChanged) {
                throw new UnprocessableEntityHttpException($redatableClosureRoot ? 'Cette période porte un planning : son type et les vacances qu’elle adapte sont figés (ses dates, elles, restent modifiables). Supprimez la période (son planning et ses versions partent avec) puis recréez-la pour en changer le type.' : 'Cette période porte un planning : son type, sa fenêtre et les vacances qu’elle adapte sont figés. Supprimez la période (son planning et ses versions partent avec) puis recréez-la.');
            }
        }

        // Le rattachement à une mère fait partie de l'identité (P2-5 E1) : il se
        // choisit à la création, jamais au PUT — re-parenter déplacerait les datées
        // héritées et la couverture sous les pieds du plan déjà bâti.
        if (null !== $input->parentEntryId && $input->parentEntryId !== $entity->getParentEntryId()) {
            throw new UnprocessableEntityHttpException('Le rattachement d’une semaine à sa période se choisit à la création.');
        }

        if (null !== $input->kind) {
            $kind = $this->parseKind($input->kind);
            $entity->setKind($kind);
            // Converting to an event clears period-only fields so the row can
            // never persist an inconsistent shape (kind=event + periodType set).
            if (CalendarEntryKind::EVENT === $kind) {
                $entity->setPeriodType(null);
                $entity->setSchoolHolidayId(null);
            }
        }
        if (null !== $input->title) {
            $entity->setTitle($input->title);
        }
        if (null !== $input->startDate) {
            $entity->setStartDate($this->parseDate($input->startDate));
        }
        if (null !== $input->endDate) {
            $entity->setEndDate($this->parseDate($input->endDate));
        }
        if (null !== $input->isDisruptive) {
            $entity->setIsDisruptive($input->isDisruptive);
        }
        if (null !== $input->periodType) {
            $entity->setPeriodType($this->parsePeriodType($input->periodType));
        }
        if (null !== $input->schoolHolidayId) {
            $entity->setSchoolHolidayId($input->schoolHolidayId);
        }
        if (null !== $input->status) {
            $entity->setStatus($this->parseStatus($input->status));
        }
        if (null !== $input->createdBy) {
            $entity->setCreatedBy($input->createdBy);
        }
    }

    /**
     * Deleting a period removes its overlay schedule (palier B) AND its dated
     * constraints — otherwise the overlay's slots/diagnostics and the dated
     * constraints orphan (invisible to generation and to the user).
     *
     * @param array<string, mixed> $uriVariables
     */
    protected function processDelete(array $uriVariables, ?string $clubId): void
    {
        // Atomique : la suppression du plan de période est un DELETE brut qui
        // s'auto-commit ; sans transaction, un échec plus bas (cascade, audit)
        // laisserait le plan détruit alors que la période survit — et une
        // ré-adaptation repartirait à V1 (le compteur monotone serait perdu).
        $this->entityManager->wrapInTransaction(function () use ($uriVariables, $clubId): void {
            $this->deleteEntryAndCascade($uriVariables, $clubId);
        });
    }

    /**
     * ADR-0002, amendement 2026-07-24 — LE PLAN NAÎT DU GESTE D'ADAPTER, pas de la
     * matérialisation. Créer une entrée closure/holiday RACINE ne provisionne plus
     * rien (signaler une indisponibilité / matérialiser une vacance = un ancrage,
     * pas une adaptation ; le plan naîtra au POST /schedule_plans du clic Adapter).
     * SEULE l'entrée-SEMAINE (parentEntryId) naît avec son plan : cocher la semaine
     * au picker EST le geste d'adaptation.
     *
     * La découpe emporte le plan-bloc de la mère : s'il existe (chemin « d'un bloc »
     * commencé puis abandonné — 0 version, garanti par assertValidWeekChild), il est
     * supprimé AVEC ses réglages ancrés (décision fondateur 2026-07-24 : chaque
     * semaine repart de la structure saison ; on ne bascule jamais bloc↔semaines,
     * on supprime puis on recrée). Idempotent : 2ᵉ enfant → plus de plan-mère, no-op.
     *
     * ATOMIQUE : sans transaction englobante, un échec du provisioning de l'enfant
     * après le flush du parent laisserait une semaine commitée sans plan — sa
     * génération produirait une version non liée, sa validation un 409 définitif.
     *
     * @param CalendarEntryInput $input
     */
    protected function processPost(object $input, ?string $clubId, ?string $seasonId): object
    {
        return $this->entityManager->wrapInTransaction(function () use ($input, $clubId, $seasonId): object {
            // Semaine enfant (P2-5 E1) : valider la MÈRE avant de créer quoi que ce
            // soit — sous le verrou de son plan-scope, pour sérialiser avec une
            // génération « en bloc » concurrente (exclusivité bloc/semaines).
            if (null !== $input->parentEntryId) {
                $this->schedulePlanProvisioner->lockPlanScope($input->parentEntryId);
                $this->assertValidWeekChild($input, $clubId, $seasonId);
                $this->assertWindowNotAlreadyPlanned($input);
            }

            $output = parent::processPost($input, $clubId, $seasonId);
            if (null !== $input->parentEntryId) {
                // Découpe : le plan-bloc de la mère (0 version) meurt avec ses
                // réglages. Le verrou du plan-scope pris en TÊTE de branche est un
                // verrou de TRANSACTION (pg_advisory_xact_lock, tenu jusqu'au commit) :
                // cette lecture et la suppression sont sérialisées avec un POST
                // /schedule_plans concurrent (même scope) — et le wrapInTransaction
                // englobant rend réglages+plan+entrée atomiques (l'imbriqué de
                // deletePeriodPlan est un no-op Doctrine).
                $motherPlanId = $this->schedulePlanProvisioner->periodPlanId($input->parentEntryId);
                if (null !== $motherPlanId) {
                    $this->removePlanAnchoredSettings($motherPlanId);
                    $this->entityManager->flush();
                    $this->schedulePlanProvisioner->deletePeriodPlan($input->parentEntryId);
                }
                // Le flush du parent rend la ligne enfant visible à la relecture SQL
                // brute du provisioner — même connexion, même transaction.
                $this->provisionIfPlanBearing($output);
            }

            return $output;
        });
    }

    /**
     * Un PUT ne crée JAMAIS de plan (amendement ADR-0002 2026-07-24) : changer le
     * type d'une période n'est pas un geste d'adaptation — le plan naît au POST
     * /schedule_plans du clic Adapter. C'est aussi l'anti-résurrection : un PUT sur
     * une mère découpée (gel d'identité par ses enfants, ci-dessus) ne re-mint pas
     * de plan-bloc. Atomique pour la même raison que le POST.
     *
     * @param array<string, mixed> $uriVariables
     * @param CalendarEntryInput   $input
     */
    protected function processPut(object $input, array $uriVariables, ?string $clubId, ?string $seasonId): object
    {
        return $this->entityManager->wrapInTransaction(function () use ($input, $uriVariables, $clubId, $seasonId): object {
            // Verrou de plan-scope AVANT l'UPDATE de l'entrée : tout autre écrivain du
            // scope (deleteEntryAndCascade, linkSchedule) prend le verrou consultatif PUIS
            // touche les lignes. Laisser le flush du parent verrouiller d'abord la ligne
            // calendar_entry inverserait l'ordre, et un PUT concurrent d'un DELETE de la
            // même période formerait un cycle ABBA (Postgres en tuerait un en 40P01 → 500).
            $entryId = $uriVariables['id'] ?? null;
            if (\is_string($entryId)) {
                $this->schedulePlanProvisioner->lockPlanScope($entryId);
            }

            // D3 v1 — re-dater une racine CLOSURE à plan « d'un bloc ». Tout se fait SOUS le verrou
            // ci-dessus, dans la même transaction. La garde d'unicité de fenêtre passe AVANT toute
            // mutation (409 franc, jamais de re-datage à moitié fait) ; le reste (resync du plan,
            // des contraintes appariées, du titre) une fois l'entrée re-datée par le parent.
            $redate = $this->prepareClosureRootRedate($input, $entryId);
            if (null !== $redate) {
                // La nouvelle fenêtre reste DANS la saison : une période ne se re-date pas hors de
                // la fenêtre de sa saison (refus PARLANT, jamais un 422 muet). Passe AVANT la garde
                // d'unicité, aucune mutation encore faite.
                $this->assertWindowWithinSeason($redate['seasonId'], $redate['newStart'], $redate['newEnd']);
                // Racine = l'entrée elle-même : sa propre famille (COALESCE(parent, id)) est exclue,
                // seuls les AUTRES plans de période qui recoupent la nouvelle fenêtre déclenchent le 409.
                $this->windowUniquenessGuard->assertWindowFree(
                    $redate['clubId'],
                    $redate['seasonId'],
                    $redate['entryId'],
                    $redate['newStart']->format('Y-m-d'),
                    $redate['newEnd']->format('Y-m-d'),
                );
            }

            // Le parent applique l'update (dates comprises) et flushe — TOUJOURS d'abord, y
            // compris pour le re-datage : applyClosureRootRedate resynchronise à partir de l'entrée
            // déjà re-datée, puis re-map la sortie (le nom/titre a pu changer au passage).
            if (null !== $redate) {
                parent::processPut($input, $uriVariables, $clubId, $seasonId);

                return $this->applyClosureRootRedate($redate);
            }

            return parent::processPut($input, $uriVariables, $clubId, $seasonId);
        });
    }

    protected function mapEntityToOutput(object $entity): CalendarEntryResource
    {
        return CalendarEntryResource::fromEntity($entity, $this->redatability->isRedatable($entity));
    }

    /**
     * D3 v1 (décision fondateur 2026-09-04) — le PUT re-date-t-il une racine CLOSURE à plan « d'un
     * bloc » ? Rend null (chemin PUT inchangé) sauf pour EXACTEMENT ce cas : une racine (pas
     * d'enfant), de type CLOSURE, SANS semaines-enfants, PORTANT un plan, dont au moins une date
     * bouge. Une racine HOLIDAY (liée au référentiel), une mère découpée et une semaine-enfant
     * restent gelées (updateEntityFromInput). Une racine CLOSURE SANS plan (le simple FAIT déclaré)
     * n'est pas concernée : sa fenêtre n'était déjà pas gelée, et rien ne gouverne encore de fenêtre
     * à re-synchroniser — parité avec la naissance d'une racine, où la garde d'unicité ne joue pas.
     *
     * @return array{clubId: string, seasonId: string, entryId: string, oldTitle: string, oldStart: DateTimeImmutable, oldEnd: DateTimeImmutable, newStart: DateTimeImmutable, newEnd: DateTimeImmutable}|null
     */
    private function prepareClosureRootRedate(CalendarEntryInput $input, mixed $entryId): ?array
    {
        if (!\is_string($entryId)) {
            return null;
        }
        $entity = $this->entityManager->getRepository(CalendarEntry::class)->find($entryId);
        if (!$entity instanceof CalendarEntry) {
            return null; // introuvable / autre club (RLS) — le parent tranchera (404/403)
        }
        // MÊME prédicat que le dégel de fenêtre (updateEntityFromInput) et que le champ servi
        // `redatable` — un seul foyer, jamais deux copies.
        if (!$this->redatability->isRedatable($entity)) {
            return null;
        }

        $oldStart = $entity->getStartDate();
        $oldEnd = $entity->getEndDate();
        $newStart = null !== $input->startDate ? $this->parseDate($input->startDate) : $oldStart;
        $newEnd = null !== $input->endDate ? $this->parseDate($input->endDate) : $oldEnd;
        if ($newStart->format('Y-m-d') === $oldStart->format('Y-m-d')
            && $newEnd->format('Y-m-d') === $oldEnd->format('Y-m-d')) {
            return null; // aucune date ne bouge : rien à re-dater
        }

        return [
            'clubId' => $entity->getClubId(),
            'seasonId' => $entity->getSeasonId(),
            'entryId' => $entity->getId(),
            'oldTitle' => $entity->getTitle(),
            'oldStart' => $oldStart,
            'oldEnd' => $oldEnd,
            'newStart' => $newStart,
            'newEnd' => $newEnd,
        ];
    }

    /**
     * D3 v1 — une fois l'entrée re-datée par le parent : (3) resync la fenêtre du plan de période,
     * (4) re-date les contraintes appariées (le venue_closed né du même geste), (5) recale le
     * suffixe de fenêtre du titre puis, s'il coïncidait, le nom du plan. La péremption des versions
     * COMPLETED (`resourcesChangedSinceGeneration`) est posée toute seule par
     * ResourceChangeStaleScheduleListener au postUpdate de l'entrée — rien à écrire ici.
     *
     * @param array{clubId: string, seasonId: string, entryId: string, oldTitle: string, oldStart: DateTimeImmutable, oldEnd: DateTimeImmutable, newStart: DateTimeImmutable, newEnd: DateTimeImmutable} $redate
     */
    private function applyClosureRootRedate(array $redate): CalendarEntryResource
    {
        // 3. La fenêtre du plan suit celle de l'entrée (gabarit hebdo sans dates).
        $this->schedulePlanProvisioner->resyncPeriodPlanWindow($redate['entryId'], $redate['newStart'], $redate['newEnd']);

        // 4. Les contraintes de l'entrée dont le COUPLE config.startDate/endDate == l'ANCIENNE
        //    fenêtre (le venue_closed apparié à la naissance, cockpit queries.ts) suivent le
        //    déplacement ; une fermeture saisie plus finement (autres dates) reste intouchée.
        $this->redateEntryPairedConstraints($redate);

        // 5. Titre : le suffixe de fenêtre (convention « — du … au … », SchedulePlanProvisioner::
        //    windowLabel) se recale s'il y était ; sinon le nom reste souverain. Puis le nom du
        //    plan s'il portait encore l'ancien titre (renamePeriodPlanIfStillNamed).
        $entity = $this->entityManager->getRepository(CalendarEntry::class)->find($redate['entryId']);
        if (!$entity instanceof CalendarEntry) {
            // Inatteignable : l'entrée vient d'être mise à jour dans cette transaction. Refus
            // PARLANT par sécurité (idiome $this->refuse — jamais un 422 muet).
            $this->refuse('La période a disparu pendant sa mise à jour.');
        }
        $oldSuffix = $this->schedulePlanProvisioner->windowLabel($redate['oldStart'], $redate['oldEnd']);
        $newSuffix = $this->schedulePlanProvisioner->windowLabel($redate['newStart'], $redate['newEnd']);
        $title = $entity->getTitle();
        if (str_ends_with($title, $oldSuffix)) {
            $entity->setTitle(substr($title, 0, -\strlen($oldSuffix)) . $newSuffix);
        }
        $this->schedulePlanProvisioner->renamePeriodPlanIfStillNamed($redate['entryId'], $redate['oldTitle'], $entity->getTitle());

        $this->entityManager->flush();

        return $this->mapEntityToOutput($entity);
    }

    /**
     * @param array{clubId: string, seasonId: string, entryId: string, oldTitle: string, oldStart: DateTimeImmutable, oldEnd: DateTimeImmutable, newStart: DateTimeImmutable, newEnd: DateTimeImmutable} $redate
     */
    private function redateEntryPairedConstraints(array $redate): void
    {
        $oldStartDay = $redate['oldStart']->format('Y-m-d');
        $oldEndDay = $redate['oldEnd']->format('Y-m-d');
        $newStartDay = $redate['newStart']->format('Y-m-d');
        $newEndDay = $redate['newEnd']->format('Y-m-d');

        // Per-row via l'UnitOfWork (jamais un UPDATE DQL en masse : la table `constraint` est un
        // mot réservé et le filtre tenant injecte un alias non quoté — même piège qu'à la cascade).
        foreach ($this->entityManager->getRepository(Constraint::class)->findBy(['calendarEntryId' => $redate['entryId']]) as $constraint) {
            $config = $constraint->getConfig();
            if (($config['startDate'] ?? null) === $oldStartDay && ($config['endDate'] ?? null) === $oldEndDay) {
                $config['startDate'] = $newStartDay;
                $config['endDate'] = $newEndDay;
                $constraint->setConfig($config);
            }
        }
        // Le flush est porté par applyClosureRootRedate (avec le titre).
    }

    /**
     * P2-5 E1 / P2-41 — garde du POST d'un enfant-SEGMENT : la mère existe (même club —
     * RLS + filtre saison la masquent sinon), porte un plan (closure/holiday),
     * n'est pas elle-même un enfant (1 seul niveau), le type de l'enfant est celui
     * de la mère, et son plan « bloc » n'a AUCUNE version (exclusivité : une
     * période déjà générée d'un bloc ne se découpe pas — supprimer ses versions
     * d'abord). Sa fenêtre est un bloc de semaines calendaires PLEINES lun→dim
     * contiguës (la semaine simple = segment de taille 1), inclus dans les semaines
     * qui couvrent la mère, clamp saison admis. Appelée SOUS le verrou du plan-scope
     * de la mère.
     */
    private function assertValidWeekChild(CalendarEntryInput $input, ?string $clubId, ?string $seasonId): void
    {
        $parent = $this->entityManager->getRepository(CalendarEntry::class)->find((string) $input->parentEntryId);
        // Club ET saison (comme ScheduleStateProcessor) : le find() est season-filtré,
        // mais l'identity-map peut surfacer une ligne d'une AUTRE saison (N-1 archivée)
        // — un enfant rattaché à une mère de saison A hériterait des datées de A,
        // que le season_filter de buildForOverlay dropperait ensuite en silence
        // (le gymnase fermé serait ignoré au solve). Défense §7.1 (revue #262 round 3).
        if (!$parent instanceof CalendarEntry
            || (null !== $clubId && $parent->getClubId() !== $clubId)
            || (null !== $seasonId && $parent->getSeasonId() !== $seasonId)) {
            throw new UnprocessableEntityHttpException('La période mère n’existe pas.');
        }
        if (null !== $parent->getParentEntryId()) {
            throw new UnprocessableEntityHttpException('Une semaine ne peut pas être découpée à son tour (un seul niveau).');
        }
        $parentType = $parent->getPeriodType();
        if (!\in_array($parentType, [CalendarEntryPeriodType::CLOSURE, CalendarEntryPeriodType::HOLIDAY], true)) {
            throw new UnprocessableEntityHttpException('Seule une période à plan (fermeture/vacances) se découpe en semaines.');
        }
        if ($this->parsePeriodType($input->periodType) !== $parentType) {
            throw new UnprocessableEntityHttpException('Une semaine hérite du type de sa période mère.');
        }
        $parentPlanId = $this->schedulePlanProvisioner->periodPlanId($parent->getId());
        if (null !== $parentPlanId && $this->planHasVersions($parentPlanId)) {
            throw new UnprocessableEntityHttpException('Cette période a déjà été adaptée d’un bloc : supprimez d’abord ses versions pour la découper en semaines.');
        }
        // SEGMENT (P2-41) — la fenêtre de l'enfant est un BLOC de semaines calendaires
        // PLEINES lun→dim CONTIGUËS ; la semaine simple est le segment de taille 1. Toutes
        // les bornes se comparent en DATE (Y-m-d, exacte au jour, sans ambiguïté de fuseau).
        $childStart = (string) $input->startDate;
        $childEnd = (string) $input->endDate;
        $childStartDate = new DateTimeImmutable($childStart);
        $childEndDate = new DateTimeImmutable($childEnd);

        // La saison de la MÈRE (toujours présente) borne les deux « clamps » : une semaine
        // de bord rognée par le début ou la fin de saison reste valide — même règle que la
        // semaine simple avant P2-41. SQL brut : season_filter épinglerait la lecture à la
        // saison active (un plan peut vivre pour une autre), RLS scope le club.
        $seasonRow = $this->entityManager->getConnection()->fetchAssociative(
            'SELECT start_date, end_date FROM season WHERE id = :sid',
            ['sid' => $parent->getSeasonId()],
        );
        $seasonStart = false === $seasonRow ? null : (string) $seasonRow['start_date'];
        $seasonEnd = false === $seasonRow ? null : (string) $seasonRow['end_date'];

        // 1) La fenêtre reste DANS les semaines qui COUVRENT la mère : du lundi de la semaine
        //    de son début au dimanche de la semaine de sa fin, le tout clampé à la saison.
        //    Cette borne REMPLACE l'ancien « toucher la mère » : sans elle, le retrait du
        //    plafond ≤7 j laisserait passer un segment débordant largement la mère (qui
        //    hériterait ses contraintes datées date-blind hors de sa portée — revue #262 r2).
        $motherStart = $parent->getStartDate();
        $motherEnd = $parent->getEndDate();
        $mondayOfMotherStart = $motherStart->modify(\sprintf('-%d days', (int) $motherStart->format('N') - 1))->format('Y-m-d');
        $sundayOfMotherEnd = $motherEnd->modify(\sprintf('+%d days', 7 - (int) $motherEnd->format('N')))->format('Y-m-d');
        $envelopeStart = null === $seasonStart ? $mondayOfMotherStart : max($mondayOfMotherStart, $seasonStart);
        $envelopeEnd = null === $seasonEnd ? $sundayOfMotherEnd : min($sundayOfMotherEnd, $seasonEnd);
        if ($childStart < $envelopeStart || $childEnd > $envelopeEnd) {
            throw new UnprocessableEntityHttpException('Ce bloc de semaines déborde la période mère.');
        }

        // 2) Bornes PLEINES : début un lundi, fin un dimanche — SAUF le clamp saison (le bloc
        //    peut commencer au 1er jour de la saison ou finir au dernier, même hors lun/dim).
        //    Une plage de dates continue étant sans trou par construction, ces deux bornes
        //    suffisent à garantir des semaines calendaires ENTIÈRES et CONTIGUËS.
        $startsOnMonday = 1 === (int) $childStartDate->format('N');
        $endsOnSunday = 7 === (int) $childEndDate->format('N');
        $startClamped = null !== $seasonStart && $childStart === $seasonStart;
        $endClamped = null !== $seasonEnd && $childEnd === $seasonEnd;
        if ((!$startsOnMonday && !$startClamped) || (!$endsOnSunday && !$endClamped)) {
            throw new UnprocessableEntityHttpException('Un bloc couvre des semaines entières, du lundi au dimanche.');
        }
        // VACANCES seulement (décision fondateur 2026-09-04) : une semaine n'est « de vacances »
        // que si la vacance (= la fenêtre de la mère) couvre TOUT son lundi→vendredi ; sinon c'est
        // une semaine de saison, qui se planifie en fermeture/overlay, jamais en reprise. Le
        // week-end ne compte pas ; un jour hors saison compte comme couvert (tolérance clamp des
        // vacances d'été). Chaque semaine du segment doit satisfaire la règle. Les enfants
        // FERMETURE gardent leur enveloppe (semaines partielles admises) — d'où le filtre sur le
        // type. Miroir MÉCANIQUE du front `holidayCoversWorkweek` (HolidayWorkweekMirrorParityTest).
        // La saison est toujours présente ici (la mère porte un seasonId, FK garantie) — le
        // `null !== $seasonEnd` ne fait que rassurer l'analyse (le début est déjà connu non nul).
        if (CalendarEntryPeriodType::HOLIDAY === $parentType && null !== $seasonEnd) {
            $holidayStart = $motherStart->format('Y-m-d');
            $holidayEnd = $motherEnd->format('Y-m-d');
            $weekMonday = $childStartDate->modify(\sprintf('-%d days', (int) $childStartDate->format('N') - 1));
            while ($weekMonday->format('Y-m-d') <= $childEnd) {
                if (!HolidayWorkweekRule::covers($weekMonday->format('Y-m-d'), $holidayStart, $holidayEnd, $seasonStart, $seasonEnd)) {
                    $this->refuse('Cette semaine n’est pas entièrement en vacances (lundi-vendredi) : elle se planifie comme une semaine de saison.');
                }
                $weekMonday = $weekMonday->modify('+7 days');
            }
        }
        // Anti-CHEVAUCHEMENT entre semaines d'une même mère (pas seulement le même
        // lundi) : deux plans de semaine qui se recouvrent = deux overlays actifs
        // possibles sur les mêmes jours, sans vainqueur défini (revue #262 round 1).
        $overlap = $this->entityManager->getConnection()->fetchOne(
            'SELECT 1 FROM calendar_entry WHERE parent_entry_id = :pid AND start_date <= :end AND end_date >= :start LIMIT 1',
            ['pid' => $parent->getId(), 'start' => $childStart, 'end' => $childEnd],
        );
        if (false !== $overlap) {
            throw new UnprocessableEntityHttpException('Cette semaine chevauche une semaine déjà découpée pour cette période.');
        }
    }

    /** Le plan porte-t-il au moins une version ? SQL brut (hors season_filter), RLS scope le club. */
    private function planHasVersions(string $schedulePlanId): bool
    {
        return (bool) $this->entityManager->getConnection()->fetchOne(
            'SELECT 1 FROM schedule WHERE schedule_plan_id = :pid LIMIT 1',
            ['pid' => $schedulePlanId],
        );
    }

    /**
     * D3 v1 — la fenêtre re-datée reste-t-elle DANS la saison ? Refus PARLANT sinon (idiome
     * $this->refuse — jamais un 422 muet). SQL brut : `season_filter` épinglerait la lecture à la
     * saison ACTIVE, or le plan re-daté peut vivre pour une autre saison ; RLS scope le club. Saison
     * introuvable → on ne bloque pas (le parent tranchera), parité avec assertValidWeekChild.
     */
    private function assertWindowWithinSeason(?string $seasonId, DateTimeImmutable $start, DateTimeImmutable $end): void
    {
        if (null === $seasonId) {
            return;
        }
        $seasonRow = $this->entityManager->getConnection()->fetchAssociative(
            'SELECT start_date, end_date FROM season WHERE id = :sid',
            ['sid' => $seasonId],
        );
        if (false === $seasonRow) {
            return;
        }
        $seasonStart = (string) $seasonRow['start_date'];
        $seasonEnd = (string) $seasonRow['end_date'];
        // Comparaison en DATE (Y-m-d, sans ambiguïté de fuseau) : le champ season est un DATE.
        if ($start->format('Y-m-d') < substr($seasonStart, 0, 10) || $end->format('Y-m-d') > substr($seasonEnd, 0, 10)) {
            $this->refuse('Ces dates sortent de la saison : une période reste dans la fenêtre de la saison.');
        }
    }

    /** La période a-t-elle des semaines-enfants ? Gel d'identité d'une mère découpée (même sans plan). */
    private function hasWeekChildren(string $calendarEntryId): bool
    {
        return false !== $this->entityManager->getConnection()->fetchOne(
            'SELECT 1 FROM calendar_entry WHERE parent_entry_id = :eid LIMIT 1',
            ['eid' => $calendarEntryId],
        );
    }

    /**
     * Les réglages ancrés à un plan de période : foyer canonique dans OverlayManager,
     * partagé avec la reprise du socle qui détruit les mêmes plans (#8). L'appelant flush.
     */
    private function removePlanAnchoredSettings(string $schedulePlanId): void
    {
        $this->overlayManager->purgePlanAnchoredSettings($schedulePlanId);
    }

    /**
     * N'entre dans le provisioner que pour ce qui peut porter un plan (inv. 9) : un
     * event ou un cutoff y paierait une transaction imbriquée, un verrou consultatif et
     * deux SELECT pour s'entendre répondre « pas de plan ». Le provisioner reste
     * défensif de son côté — c'est lui qui fait autorité sur le mapping type → plan.
     */
    /**
     * ADR-0002 inv. 4 (P2-38) — une semaine qui naît AVEC son plan ne doit pas atterrir dans une
     * fenêtre qu'un AUTRE plan gouverne déjà (règle fondateur : « un overlay d'incident ne touche
     * jamais une semaine de vacances », refusée dans les deux sens).
     *
     * La racine passée est la MÈRE : le plan-bloc de la mère — supprimé quelques lignes plus bas
     * par la découpe — et les semaines sœurs partagent cette racine, donc ne déclenchent jamais la
     * garde. Leur non-chevauchement mutuel reste gardé par {@see assertValidWeekChild}, qu'on ne
     * double pas ici.
     */
    private function assertWindowNotAlreadyPlanned(CalendarEntryInput $input): void
    {
        $parentId = (string) $input->parentEntryId;
        $parent = $this->entityManager->getRepository(CalendarEntry::class)->find($parentId);
        if (!$parent instanceof CalendarEntry || null === $input->startDate || null === $input->endDate) {
            return; // déjà refusé par assertValidWeekChild — on ne double pas son message.
        }

        $this->windowUniquenessGuard->assertWindowFree(
            $parent->getClubId(),
            $parent->getSeasonId(),
            $parentId,
            $input->startDate,
            $input->endDate,
        );
    }

    private function provisionIfPlanBearing(CalendarEntryResource $output): void
    {
        if (\in_array($output->periodType, ['closure', 'holiday'], true)) {
            $this->schedulePlanProvisioner->provisionPeriodPlan($output->id);
        }
    }

    /** @param array<string, mixed> $uriVariables */
    private function deleteEntryAndCascade(array $uriVariables, ?string $clubId): void
    {
        // Delete the overlay versions BEFORE the parent removes the entry (we need
        // the entry managed to drive deleteOverlayForEntry). Guard club ownership
        // inline so a cross-club delete deletes nothing before the parent throws 403.
        $id = $uriVariables['id'] ?? null;

        // P2-5 E1 : supprimer une MÈRE emporte ses semaines enfants — chacune par la
        // même cascade complète (plan + versions + réglages + reminders). Récursion
        // bornée par construction : un enfant n'est jamais parent (garde au POST).
        // Verrou du plan-scope AVANT l'énumération : le POST d'un enfant concurrent
        // tient le même verrou — énumérer avant laisserait survivre son enfant en
        // orphelin au plan fantôme (TOCTOU, revue #262 round 1). Ré-entrant : les
        // étapes plus bas le reprennent sans coût.
        if (\is_string($id) && '' !== $id) {
            $this->schedulePlanProvisioner->lockPlanScope($id);
            foreach ($this->entityManager->getRepository(CalendarEntry::class)->findBy(['parentEntryId' => $id]) as $child) {
                if (null === $clubId || $child->getClubId() === $clubId) {
                    $this->deleteEntryAndCascade(['id' => $child->getId()], $clubId);
                }
            }
        }
        // Capturé AVANT deletePeriodPlan : depuis le lot C2 les réglages de la période sont
        // ancrés au PLAN (inv. 5), or c'est le plan qu'on détruit juste en dessous — après
        // quoi plus rien ne relie ses réglages à cette période, et ils orphelineraient.
        $schedulePlanId = null;
        if (\is_string($id) && '' !== $id) {
            $entry = $this->entityManager->getRepository(CalendarEntry::class)->find($id);
            if ($entry instanceof CalendarEntry && (null === $clubId || $entry->getClubId() === $clubId)) {
                $schedulePlanId = $this->schedulePlanProvisioner->periodPlanId($entry->getId());
                // Verrou EN TÊTE : deleteOverlayForEntry balaie les versions juste
                // en dessous. Le prendre après (dans deletePeriodPlan) ne sérialise
                // rien — une création d'overlay concurrente s'intercale, et sa
                // version se retrouve liée à un plan qu'on vient de supprimer.
                $this->schedulePlanProvisioner->lockPlanScope($entry->getId());
                $this->overlayManager->deleteOverlayForEntry($entry);
                // ADR-0002 inv. 10 : supprimer une indisponibilité supprime SES plans.
                // Ici (et pas dans deleteOverlayForEntry, que la purge des périodes
                // échues appelle sur des entries qui, elles, SURVIVENT) : sinon
                // /api/schedule_plans garderait un plan fantôme nommant une période
                // supprimée, et une re-adaptation repartirait à V1.
                $this->schedulePlanProvisioner->deletePeriodPlan($entry->getId());
            }
        }

        // Parent runs the not-found / cross-club (403) guard and removes the
        // entry; only then do we cascade to its dated constraints.
        parent::processDelete($uriVariables, $clubId);

        if (\is_string($id) && '' !== $id) {
            // Per-row remove (not bulk DQL DELETE): the `constraint` table is a
            // reserved word and the tenant SQL filter injects an unquoted alias
            // on bulk deletes → syntax error. UnitOfWork removes quote correctly.
            $dated = $this->entityManager->getRepository(Constraint::class)->findBy(['calendarEntryId' => $id]);
            foreach ($dated as $constraint) {
                $this->entityManager->remove($constraint);
            }
            // Tous les réglages de la période pendent au PLAN (inv. 5, lots C2-C3) — d'où
            // l'id capturé AVANT sa destruction, sans quoi ils orphelineraient.
            if (null !== $schedulePlanId) {
                $this->removePlanAnchoredSettings($schedulePlanId);
            }
            // …and any reminder logged for this period (else a ghost survives to the season purge).
            foreach ($this->entityManager->getRepository(PeriodReminderLog::class)->findBy(['calendarEntryId' => $id]) as $log) {
                $this->entityManager->remove($log);
            }
            // #10 — les doléances coachs pendent à l'entrée MÈRE des vacances. Supprimer une
            // SEMAINE enfant n'en emporte aucune (elles ne vivent que sur la mère, voulu) ;
            // supprimer la mère les emporte toutes.
            foreach ($this->entityManager->getRepository(CoachWish::class)->findBy(['calendarEntryId' => $id]) as $wish) {
                $this->entityManager->remove($wish);
            }
            // #10 C2 — et la campagne de collecte de la période (ses tokens partent par FK CASCADE).
            foreach ($this->entityManager->getRepository(CoachWishCampaign::class)->findBy(['calendarEntryId' => $id]) as $campaign) {
                $this->entityManager->remove($campaign);
            }
            $this->entityManager->flush();
        }
    }

    /**
     * Une entrée VACANCES racine créée SANS lien explicite reçoit le lien vers la vacance
     * scolaire de la ZONE du club dont la fenêtre chevauche la sienne. Le cockpit apparie ses
     * cartes sur ce lien (RadarPanel, DayDialog) : sans lui, une vacance matérialisée « à la
     * main » et le feed scolaire s'affichaient en DEUX cartes « Vacances d'été ». Une seule
     * vérité côté serveur — le front n'invente pas la règle (règle d'or frontend.md).
     *
     * Bornée : une HOLIDAY RACINE seulement (une fermeture n'est pas une vacance ; une
     * semaine-enfant n'ancre rien). Sans zone, sans club en contexte, ou sans vacance
     * chevauchante → pas de lien (NULL).
     *
     * Même notion, deux opérations : cette méthode est le PENDANT ÉCRITURE de `isHolidayAnchor`
     * (frontend `cockpit/lib/markers.ts`, LECTURE — « racine + schoolHolidayId »). Ici on CRÉE
     * l'ancre (calcul du lien racine HOLIDAY → vacance de la zone) ; là-bas on DÉTECTE, parmi des
     * entrées, celle qui ancre déjà une vacance. Le prédicat d'ancrage est purement STRUCTUREL
     * (deux champs de l'entrée) et sa maison de lecture reste `isHolidayAnchor` côté front — pas
     * une règle métier redérivée : ces deux sites ne sont pas des miroirs à mettre en parité.
     */
    private function autoLinkedHolidayId(CalendarEntryInput $input): ?string
    {
        if (CalendarEntryPeriodType::HOLIDAY !== $this->parsePeriodType($input->periodType) || null !== $input->parentEntryId) {
            return null;
        }
        if (null === $input->startDate || null === $input->endDate) {
            return null;
        }
        $request = $this->requestStack->getCurrentRequest();
        $clubId = $request?->attributes->get('_club_id') ?? $request?->headers->get('X-Club-Id');
        if (!\is_string($clubId)) {
            return null;
        }
        $zone = $this->entityManager->getRepository(Club::class)->find($clubId)?->getSchoolZone();
        if (null === $zone || '' === $zone) {
            return null;
        }
        $matches = $this->schoolHolidayRepository->findByZoneAndWindow($zone, new DateTimeImmutable($input->startDate), new DateTimeImmutable($input->endDate));

        return [] === $matches ? null : $matches[0]->getId();
    }

    /**
     * AUD-BCK-14 — **absent → le défaut ; PRÉSENT mais inconnu → 422.**.
     *
     * Ces trois parsers repliaient en silence (`?? EVENT`, `?? ACTIVE`, et un `tryFrom()` nu
     * dont le `null` de l'inconnu se confond avec le `null` de l'absent) : mot pour mot le
     * motif qu'AUD-BCK-12 a remplacé par un `throw` sur `Constraint`, resté ici. Inatteignable
     * aujourd'hui — `CalendarEntryInput` porte un `Assert\Choice` sur les trois champs — mais
     * le jour où l'un saute, une **PÉRIODE devient un ÉVÉNEMENT** enregistré comme tel, ou un
     * type de période part à NULL : conséquences ADR-0002 (grille possédée par la période,
     * plans ancrés), et pas un mot. Défense en profondeur : on échoue bruyamment.
     *
     * ⚑ **Différence assumée avec le patron de `Constraint`** : là-bas les champs portent
     * `NotBlank`, donc lever sur `null` est correct. Ici les trois sont FACULTATIFS et ont un
     * défaut documenté (genre absent = ÉVÉNEMENT, statut absent = ACTIF, type absent = pas de
     * période) — confondre « absent » et « inconnu » aurait cassé toute création qui s'en
     * remet aux défauts. Le témoin du test unitaire garde précisément cette distinction.
     */
    private function parseKind(?string $value): CalendarEntryKind
    {
        if (null === $value) {
            return CalendarEntryKind::EVENT;
        }

        return CalendarEntryKind::tryFrom($value) ?? throw $this->unknownEnumValue('kind', $value, CalendarEntryKind::values());
    }

    private function parsePeriodType(?string $value): ?CalendarEntryPeriodType
    {
        if (null === $value) {
            return null;
        }

        return CalendarEntryPeriodType::tryFrom($value) ?? throw $this->unknownEnumValue('periodType', $value, CalendarEntryPeriodType::values());
    }

    private function parseStatus(?string $value): CalendarEntryStatus
    {
        if (null === $value) {
            return CalendarEntryStatus::ACTIVE;
        }

        return CalendarEntryStatus::tryFrom($value) ?? throw $this->unknownEnumValue('status', $value, CalendarEntryStatus::values());
    }

    private function parseDate(?string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value ?? 'now');
    }
}
