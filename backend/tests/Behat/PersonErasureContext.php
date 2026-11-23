<?php

declare(strict_types=1);

namespace App\Tests\Behat;

use App\Tests\PersonTraceSweep;
use Behat\Hook\AfterScenario;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use RuntimeException;

/**
 * Promesse : effacer une personne (RGPD, article 17 ; commande de support `app:people:erase`,
 * lot conception 3, PR 3) efface tout ce qui vient d'elle. Après l'effacement, plus aucune colonne de
 * la base ne contient son nom, son mail, son téléphone ni le commentaire de sa doléance ; ses séances
 * restent, sans coach ; la collecte ouverte désigne le coach suivant.
 *
 * Décor fait par les VRAIS gestes, sur un petit club inscrit par l'API ({@see SmallClubFixtureTrait}) :
 * saisie des coachs, de l'indisponibilité, génération du socle, collecte envoyée et réponse publique
 * commentée, récapitulatif quotidien de la collecte (la tâche planifiée `app:coach-wishes:digest`),
 * signalement du planning. L'exemple « démonstration » passe le club en démonstration, horloge simulée
 * réglée, avant le récapitulatif : il est alors rangé dans la boîte du club.
 *
 * Le balayage ({@see PersonTraceSweep}) lit toutes les colonnes texte, JSON et tableau du schéma par la
 * connexion d'ADMINISTRATION (par la connexion par défaut, soumise à la sécurité par club, il serait
 * vert sans rien voir) ; il est prouvé d'abord sur le décor, avant l'effacement. La demande figée et la
 * photo rangent prénom et nom dans deux champs : le balayage cherche donc aussi le nom de famille seul,
 * rendu propre au scénario (« Durand- » et huit lettres) pour ne jamais rencontrer un autre club.
 * Plus bas, jumeaux cités : `Security/PersonErasureLeavesNoTraceTest` (le relevé, cas sans geste
 * rapide), `Integration/Structure/PersonEraserTest` (chaque étape), `Integration/Command/ErasePersonCommandTest`
 * (la porte du support, audit, autre club).
 */
final class PersonErasureContext extends BaseContext
{
    use SmallClubFixtureTrait;

    private string $scheduleId = '';

    private string $campaignId = '';

    private string $lastName = '';

    private string $email = '';

    private string $phone = '';

    private string $comment = '';

    private string $monday = '';

    private bool $demo = false;

    /** L'équipe qu'elle partage et le coach qui la partage avec elle : le « coach suivant » de la collecte. */
    private string $sharedTeam = '';

    private string $nextCoach = '';

    /** @var list<string> séances de la version générée qui nommaient la personne avant l'effacement */
    private array $herSessionIds = [];

    #[Given('/^un petit club dont la coach « (?P<coach>[^»]+) », joignable par mail et par téléphone, entraîne seule « (?P<alone>[^»]+) » et partage « (?P<shared>[^»]+) » avec « (?P<other>[^»]+) »$/u')]
    public function unPetitClubDontLaCoach(string $coach, string $alone, string $shared, string $other): void
    {
        $this->registerSmallClub();
        $marker = strtr(strtolower(substr($this->smallClubAra, -8)), '0123456789', 'ghijklmnop');
        [$firstName, $lastName] = explode(' ', $coach, 2);
        $this->lastName = $lastName . '-' . ucfirst($marker);
        $this->email = 'camille.' . strtolower($this->smallClubAra) . '@smoke.fr';
        $this->phone = '06' . substr(str_pad((string) crc32($this->smallClubAra), 8, '0', \STR_PAD_LEFT), 0, 8);
        $this->comment = 'Stage ' . strtolower($this->smallClubAra) . ' la premiere semaine';
        [$this->sharedTeam, $this->nextCoach] = [$shared, $other];

        $categories = $this->apiGet('sport_categories', $this->smallClubToken);
        $category = $categories['json']['member'][0]['id'] ?? null;
        if (!\is_string($category)) {
            throw new RuntimeException('aucune catégorie sportive pour le petit club');
        }
        foreach ([$alone, $shared] as $name) {
            $this->smallClubTeamIds[$name] = $this->createdId($this->apiPost('teams', [
                'name' => $name, 'sportCategoryId' => $category, 'priorityTierId' => 3, 'sessionsPerWeek' => 2,
            ], $this->smallClubToken), 'équipe ' . $name);
        }
        $venueId = $this->createdId($this->apiPost('venues', ['name' => 'Gymnase Nord', 'source' => 'manual'], $this->smallClubToken), 'Gymnase Nord');
        $this->smallClubVenueIds['Gymnase Nord'] = $venueId;
        foreach ([1, 2, 4] as $day) {
            foreach (['18:00', '19:30'] as $start) {
                $this->createdId($this->apiPost('venue_training_slots', [
                    'venueId' => $venueId, 'dayOfWeek' => $day, 'startTime' => $start, 'durationMinutes' => 90, 'capacity' => 1,
                ], $this->smallClubToken), 'créneau du Gymnase Nord');
            }
        }
        $this->smallClubCoachIds[$coach] = $this->createdId($this->apiPost('coaches', [
            'firstName' => $firstName, 'lastName' => $this->lastName, 'email' => $this->email, 'phone' => $this->phone,
        ], $this->smallClubToken), 'coach ' . $coach);
        $this->smallClubCoachIds[$other] = $this->createdId($this->apiPost('coaches', ['firstName' => $other], $this->smallClubToken), 'coach ' . $other);
        // Camille d'abord sur les deux équipes : elle est la première principale, donc la destinataire de la collecte.
        $this->assignSmallClubCoach($coach, $alone);
        $this->assignSmallClubCoach($coach, $shared);
        $this->assignSmallClubCoach($other, $shared);
    }

    #[Given('/^« (?P<coach>[^»]+) » est indisponible le vendredi, et le planning de saison est généré$/u')]
    public function estIndisponibleEtLePlanningEstGenere(string $coach): void
    {
        $this->createdId($this->apiPost('constraints', [
            'name' => $this->fullName($coach) . ' · indisponible le vendredi', 'scope' => 'COACH', 'scopeTargetId' => $this->smallClubCoachIds[$coach],
            'family' => 'COACH_AVAILABILITY', 'ruleType' => 'HARD', 'config' => ['unavailableDays' => [5]], 'isActive' => true,
        ], $this->smallClubToken), 'indisponibilité de ' . $coach);

        $this->scheduleId = $this->generateSmallClubSeason();
        $ids = $this->dbalScalar(\sprintf('SELECT string_agg(id::text, \',\' ORDER BY id) AS behatval FROM schedule_slot_template WHERE schedule_id=\'%s\' AND coach_id=\'%s\'', $this->scheduleId, $this->smallClubCoachIds[$coach]), admin: true);
        $this->herSessionIds = '' === $ids ? [] : explode(',', $ids);
        if ([] === $this->herSessionIds) {
            throw new RuntimeException(\sprintf('décor : le planning généré ne nomme %s sur aucune séance', $coach));
        }
    }

    #[Given('/^une collecte de doléances est envoyée pour des vacances à venir, et « (?P<coach>[^»]+) » y répond avec un commentaire$/u')]
    public function uneCollecteEstEnvoyeeEtElleYRepond(string $coach): void
    {
        $this->monday = $this->smallClubFutureMonday(28, 13);
        $entryId = $this->createdId($this->apiPost('calendar_entries', [
            'kind' => 'period', 'periodType' => 'holiday', 'title' => 'Vacances à venir',
            'startDate' => $this->monday, 'endDate' => date('Y-m-d', (int) strtotime($this->monday . ' +13 days')),
        ], $this->smallClubToken), 'période de vacances');
        $this->createdId($this->apiPost('schedule_plans', ['calendarEntryId' => $entryId], $this->smallClubToken), 'planning des vacances');
        $teamIds = array_values($this->smallClubTeamIds);
        $this->campaignId = $this->createdId($this->apiPost('coach_wish_campaigns', [
            'calendarEntryId' => $entryId, 'deadline' => date('Y-m-d', (int) strtotime($this->monday . ' -3 days')),
            'weeks' => [$this->monday], 'teamIds' => $teamIds,
        ], $this->smallClubToken), 'collecte');

        $sent = $this->apiPost(\sprintf('coach_wish_campaigns/%s/send-links', $this->campaignId), [], $this->smallClubToken);
        if (!\in_array($sent['status'], [200, 201], true) || ($sent['json']['sent'] ?? 0) < 1) {
            throw new RuntimeException(\sprintf('l\'envoi des liens de la collecte a répondu %d : %s', $sent['status'], json_encode($sent['json'], \JSON_UNESCAPED_UNICODE)));
        }
        $token = '';
        foreach ($this->campaignCoaches() as $entry) {
            if (($entry['coachId'] ?? null) === $this->smallClubCoachIds[$coach] && \is_string($entry['token'] ?? null)) {
                $token = $entry['token'];
            }
        }
        if ('' === $token) {
            throw new RuntimeException(\sprintf('décor : aucun lien de collecte pour %s', $coach));
        }

        $answer = $this->publicPost(\sprintf('coach-wishes/public/%s', $token), ['submissions' => [[
            'teamId' => reset($teamIds), 'weekStart' => $this->monday, 'slotsWanted' => 1, 'comment' => $this->comment,
        ]]]);
        if (200 !== $answer['status']) {
            throw new RuntimeException(\sprintf('la réponse publique de %s a répondu %d', $coach, $answer['status']));
        }
    }

    #[Given('/^le club est (?P<regime>réel|passé en démonstration sous horloge simulée) quand le récapitulatif de la collecte part$/u')]
    public function leClubEstQuandLeRecapitulatifPart(string $regime): void
    {
        $this->demo = 'réel' !== $regime;
        if ($this->demo) {
            $this->dbalExec(\sprintf('UPDATE club SET is_demo = true WHERE id=\'%s\'', $this->smallClubId), admin: true);
            $this->dbalExec(\sprintf('UPDATE app_user SET is_demo = true WHERE id=\'%s\'', $this->smallClubUserId), admin: true);
            $clock = $this->apiPost('club/clock', ['date' => date('Y-m-d')], $this->smallClubToken);
            if (200 !== $clock['status']) {
                throw new RuntimeException(\sprintf('régler l\'horloge simulée du club a répondu %d : %s', $clock['status'], json_encode($clock['json'], \JSON_UNESCAPED_UNICODE)));
            }
        }

        $this->runConsole(['php', 'bin/console', 'app:coach-wishes:digest']);

        if ($this->demo && 0 === (int) $this->dbalScalar(\sprintf('SELECT COUNT(*) AS behatval FROM club_mailbox_message WHERE club_id=\'%s\' AND body_text ILIKE \'%%%s%%\'', $this->smallClubId, $this->lastName), admin: true)) {
            throw new RuntimeException('décor : le récapitulatif de la collecte n\'est pas rangé dans la boîte du club de démonstration, ou ne la cite pas');
        }
    }

    #[Given('je signale le planning généré')]
    public function jeSignaleLePlanning(): void
    {
        $this->createdId($this->apiPost('feedback', [
            'topic' => 'bug', 'message' => 'Le planning ne convient pas.', 'context' => ['scheduleId' => $this->scheduleId],
        ], $this->smallClubToken), 'signalement');
    }

    /**
     * Le geste du support. Avant lui, le balayage est prouvé sur le décor : il doit trouver la personne
     * dans sa fiche, la demande figée, le signalement et sa doléance (et la boîte, en démonstration).
     */
    #[When('/^le support efface la personne « (?P<coach>[^»]+) »$/u')]
    public function leSupportEffaceLaPersonne(string $coach): void
    {
        $before = explode(', ', $this->traces());
        $expected = ['person.last_name', 'person.email', 'person.phone', 'coach_wish.comment', 'schedule.snapshot_data', 'feedback.context'];
        if ($this->demo) {
            $expected[] = 'club_mailbox_message.body_text';
        }
        $missing = array_diff($expected, $before);
        if ([] !== $missing) {
            throw new RuntimeException(\sprintf('décor : avant l\'effacement, le balayage ne trouve pas %s dans %s (trouvé : %s)', $coach, implode(', ', $missing), implode(', ', $before)));
        }

        $this->runConsole(['php', 'bin/console', 'app:people:erase', '--club=' . $this->smallClubId, '--coach=' . $this->smallClubCoachIds[$coach]]);
    }

    #[Then('plus aucune colonne de la base ne contient son nom, son mail, son téléphone ni son commentaire')]
    public function plusAucuneColonneNeContientSaTrace(): void
    {
        $left = $this->traces();
        if ('' !== $left) {
            throw new RuntimeException(\sprintf('après l\'effacement, la personne est encore dans : %s', $left));
        }
    }

    #[Then('ses séances restent, sans coach')]
    public function sesSeancesRestentSansCoach(): void
    {
        $list = implode(',', array_map(static fn (string $id): string => '\'' . $id . '\'', $this->herSessionIds));
        $kept = (int) $this->dbalScalar(\sprintf('SELECT COUNT(*) AS behatval FROM schedule_slot_template WHERE id IN (%s)', $list), admin: true);
        if (\count($this->herSessionIds) !== $kept) {
            throw new RuntimeException(\sprintf('%d séances sur %d de la personne effacée restent', $kept, \count($this->herSessionIds)));
        }
        $named = (int) $this->dbalScalar(\sprintf('SELECT COUNT(*) AS behatval FROM schedule_slot_template WHERE id IN (%s) AND coach_id IS NOT NULL', $list), admin: true);
        if (0 !== $named) {
            throw new RuntimeException(\sprintf('%d séances de la personne effacée nomment encore un coach', $named));
        }
    }

    /** Sur l'équipe qu'elle partageait, le coach suivant répond désormais : il est le destinataire et son lien couvre l'équipe. */
    #[Then('la collecte ouverte désigne le coach suivant')]
    public function laCollecteDesigneLeCoachSuivant(): void
    {
        $campaign = $this->apiGet(\sprintf('coach_wish_campaigns/%s', $this->campaignId), $this->smallClubToken);
        $shared = $this->smallClubTeamIds[$this->sharedTeam];
        $next = $this->smallClubCoachIds[$this->nextCoach];
        $recipient = null;
        foreach (\is_array($campaign['json']['teams'] ?? null) ? $campaign['json']['teams'] : [] as $team) {
            if (\is_array($team) && ($team['teamId'] ?? null) === $shared) {
                $recipient = $team['recipientCoachId'] ?? null;
            }
        }
        if ($next !== $recipient) {
            throw new RuntimeException(\sprintf('la collecte désigne « %s » pour %s (attendu %s)', \is_string($recipient) ? $recipient : 'personne', $this->sharedTeam, $this->nextCoach));
        }
        foreach ($this->campaignCoaches() as $entry) {
            if (($entry['coachId'] ?? null) === $next && \in_array($shared, \is_array($entry['teamIds'] ?? null) ? $entry['teamIds'] : [], true)) {
                return;
            }
        }

        throw new RuntimeException(\sprintf('%s n\'a pas de lien de collecte qui couvre %s', $this->nextCoach, $this->sharedTeam));
    }

    #[AfterScenario]
    public function nettoyer(): void
    {
        $this->teardownSmallClub();
    }

    /** Colonnes de toute la base (connexion d'administration) qui contiennent encore nom, mail, téléphone ou commentaire. */
    private function traces(): string
    {
        return $this->dbalScalar(PersonTraceSweep::sql([$this->lastName, $this->email, $this->phone, $this->comment]), admin: true);
    }

    private function fullName(string $coach): string
    {
        return explode(' ', $coach, 2)[0] . ' ' . $this->lastName;
    }

    /** @return list<array<string, mixed>> */
    private function campaignCoaches(): array
    {
        $campaign = $this->apiGet(\sprintf('coach_wish_campaigns/%s', $this->campaignId), $this->smallClubToken);

        return array_values(array_filter(\is_array($campaign['json']['coaches'] ?? null) ? $campaign['json']['coaches'] : [], 'is_array'));
    }

    /** @param array{status: int, json: array<mixed>} $response */
    private function createdId(array $response, string $what): string
    {
        $id = $response['json']['id'] ?? null;
        if (!\in_array($response['status'], [200, 201], true) || !\is_string($id) || '' === $id) {
            throw new RuntimeException(\sprintf('création de « %s » refusée (HTTP %d) : %s', $what, $response['status'], json_encode($response['json'], \JSON_UNESCAPED_UNICODE)));
        }

        return $id;
    }
}
