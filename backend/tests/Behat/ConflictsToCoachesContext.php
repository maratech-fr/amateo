<?php

declare(strict_types=1);

namespace App\Tests\Behat;

use App\Tests\Integration\Api\ConflictCoachMailApiTest;
use App\Tests\Unit\Service\ConflictCoachMailBuilderTest;
use App\Tests\Unit\Service\ConflictCoachMailingTest;
use Behat\Hook\AfterScenario;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use DateTimeImmutable;
use RuntimeException;

/**
 * « Les conflits partent aux coachs » de bout en bout : petit club jetable bâti par l'API
 * ({@see SmallClubFixtureTrait}, socle généré et validé), coachs et rencontres posés par l'API,
 * envoi par `POST /api/fixtures/conflicts/mail-coaches`, e-mails lus dans Mailpit (patron
 * {@see DemoRetainedContext::pullVerificationToken} : recherche par destinataire, boucle bornée).
 *
 * Les règles fines (mémoire, gardes, plafond, échappement, gabarit) sont prouvées au plus bas :
 * {@see ConflictCoachMailingTest}, {@see ConflictCoachMailBuilderTest},
 * {@see ConflictCoachMailApiTest}. Ici : l'enchaînement réel du
 * gestionnaire, jusqu'à la boîte du coach.
 */
final class ConflictsToCoachesContext extends BaseContext
{
    use SmallClubFixtureTrait;

    private string $mailpitBase;

    private string $karimEmail = '';

    private string $karimId = '';

    private string $emmaId = '';

    private string $saturday = '';

    private string $u15f1MatchId = '';

    /** @var array{status: int, json: array<mixed>} */
    private array $lastSend = ['status' => 0, 'json' => []];

    /** @var list<string> identifiants Mailpit déjà lus pour Karim */
    private array $seenMailIds = [];

    public function __construct()
    {
        parent::__construct();
        $this->mailpitBase = rtrim((string) (getenv('BEHAT_MAILPIT_BASE') ?: 'http://mailpit:8025'), '/');
    }

    #[Given('un petit club dont le planning de saison est en vigueur')]
    public function unPetitClubAvecSocle(): void
    {
        $this->buildSmallClub();
        $this->generateAndValidateSmallClubSocle();
        // Un samedi à venir, assez loin pour rester « à venir » toute la durée du scénario.
        $this->saturday = new DateTimeImmutable('today')->modify('+7 days')->modify('next saturday')->format('Y-m-d');
    }

    #[Given('Karim, avec une adresse, coache U15F1 et U13F1, qui jouent à l\'extérieur le même samedi à 15h30 et à 16h')]
    public function karimCoacheDeuxEquipesQuiJouentEnMemeTemps(): void
    {
        $this->karimEmail = 'karim-' . strtolower($this->smallClubAra) . '@smoke.fr';
        $this->karimId = $this->newCoach('Karim', 'Benali', $this->karimEmail);
        $this->mainCoach('U15F1', $this->karimId);
        $this->mainCoach('U13F1', $this->karimId);
        $this->u15f1MatchId = $this->awayMatch('U15F1', $this->saturday, '15:30');
        $this->awayMatch('U13F1', $this->saturday, '16:00');
    }

    #[Given('Emma, sans adresse, coache U17F1 qui joue à l\'extérieur ce samedi à 16h, et joue elle-même avec U15F1')]
    public function emmaSansAdresseCoacheEtJoue(): void
    {
        $this->emmaId = $this->newCoach('Emma', 'Durand', null);
        $this->mainCoach('U17F1', $this->emmaId);
        $joined = $this->apiPost('coach_player_memberships', ['coachId' => $this->emmaId, 'teamId' => $this->smallClubTeamIds['U15F1'], 'isActive' => true], $this->smallClubToken);
        if (!\in_array($joined['status'], [200, 201], true)) {
            throw new RuntimeException(\sprintf('Emma n\'a pas pu rejoindre U15F1 comme joueuse (HTTP %d)', $joined['status']));
        }
        $this->awayMatch('U17F1', $this->saturday, '16:00');

        // Témoin positif du décor : l'écran voit bien Karim ET Emma en double ce samedi — sinon
        // un envoi « réussi » ne prouverait rien.
        $doubled = $this->personsInDouble();
        if ([] !== array_diff([$this->karimId, $this->emmaId], $doubled)) {
            throw new RuntimeException(\sprintf('le décor devrait mettre Karim et Emma en double, le radar voit : %s', json_encode($doubled)));
        }
    }

    #[When('le gestionnaire envoie les conflits aux coachs')]
    public function leGestionnaireEnvoie(): void
    {
        $this->lastSend = $this->apiPost('fixtures/conflicts/mail-coaches', [], $this->smallClubToken);
    }

    #[When('le gestionnaire envoie de nouveau les conflits aux coachs')]
    public function leGestionnaireEnvoieDeNouveau(): void
    {
        $this->leGestionnaireEnvoie();
    }

    #[Then('Karim reçoit un e-mail qui liste ses 2 conflits de personne')]
    public function karimRecoitSesConflits(): void
    {
        $this->assertSent();
        $mail = $this->nextMailForKarim();
        if (!str_starts_with($mail['subject'], '2 conflits vous concernent — ')) {
            throw new RuntimeException(\sprintf('sujet inattendu : « %s »', $mail['subject']));
        }
        if (2 !== substr_count($mail['text'], 'Personne en double — ')) {
            throw new RuntimeException('l\'e-mail de Karim devrait porter 2 tuiles « Personne en double » :' . "\n" . $mail['text']);
        }
        if (!str_contains($mail['text'], 'à titre indicatif')) {
            throw new RuntimeException('l\'e-mail ne dit pas qu\'il est indicatif');
        }
    }

    #[Then('Emma est nommée comme coach sans adresse, et la ligne d\'envoi compte 1 e-mail envoyé par le gestionnaire')]
    public function emmaEstNommeeEtLaLigneCompte(): void
    {
        $skipped = $this->lastSend['json']['skipped'] ?? [];
        $skippedIds = \is_array($skipped) ? array_column($skipped, 'coachId') : [];
        if ([$this->emmaId] !== $skippedIds) {
            throw new RuntimeException(\sprintf('Emma devrait être la seule sautée, obtenu : %s', json_encode($skipped)));
        }

        $last = $this->apiGet('fixtures/conflicts', $this->smallClubToken)['json']['coachMailing']['last'] ?? null;
        if (!\is_array($last) || 1 !== ($last['mailedCount'] ?? null) || 'Petit' !== ($last['sentByFirstName'] ?? null) || [$this->emmaId] !== ($last['skippedCoachIds'] ?? null)) {
            throw new RuntimeException(\sprintf('ligne « Envoyé le … » inattendue : %s', json_encode($last)));
        }
    }

    #[When('le match de U15F1 est avancé au dimanche matin')]
    public function leMatchDeU15F1EstAvance(): void
    {
        $sunday = new DateTimeImmutable($this->saturday)->modify('+1 day')->format('Y-m-d');
        $moved = $this->apiPut(\sprintf('fixtures/%s', $this->u15f1MatchId), [
            'teamId' => $this->smallClubTeamIds['U15F1'],
            'matchDate' => $sunday,
            'homeAway' => 'AWAY',
            'opponentLabel' => 'Les Loups',
            'kickoffTime' => '10:00',
        ], $this->smallClubToken);
        if (200 !== $moved['status']) {
            throw new RuntimeException(\sprintf('le déplacement du match a répondu %d (200 attendu)', $moved['status']));
        }
        if ([] !== $this->personsInDouble()) {
            throw new RuntimeException('une fois le match avancé, plus personne ne devrait être en double');
        }
    }

    #[Then('Karim reçoit un e-mail qui lui dit que plus aucun conflit ne le concerne')]
    public function karimRecoitPlusAucun(): void
    {
        $this->assertSent();
        $mail = $this->nextMailForKarim();
        if (!str_starts_with($mail['subject'], 'Plus aucun conflit sur un match ne vous concerne — ')) {
            throw new RuntimeException(\sprintf('sujet inattendu : « %s »', $mail['subject']));
        }
        $skipped = $this->lastSend['json']['skipped'] ?? null;
        if ([] !== $skipped) {
            throw new RuntimeException('Emma n\'avait jamais reçu de liste : elle n\'a rien à apprendre, personne n\'est sauté');
        }
    }

    #[Then('un nouvel envoi est refusé, faute de conflit à envoyer ou de coach à prévenir')]
    public function unNouvelEnvoiEstRefuse(): void
    {
        $again = $this->apiPost('fixtures/conflicts/mail-coaches', [], $this->smallClubToken);
        if (422 !== $again['status'] || 'Aucun conflit à envoyer et aucun coach à prévenir.' !== ($again['json']['error'] ?? null)) {
            throw new RuntimeException(\sprintf('un envoi sans rien à dire devrait être un 422 nommé, obtenu %d %s', $again['status'], json_encode($again['json'])));
        }
    }

    #[Then('Karim reçoit un e-mail qui liste son conflit, sans marque de nouveauté')]
    public function karimRecoitSonConflitSansMarque(): void
    {
        $this->assertSent();
        $mail = $this->nextMailForKarim();
        if (!str_starts_with($mail['subject'], 'Un conflit vous concerne — ') || 1 !== substr_count($mail['text'], 'Personne en double — ')) {
            throw new RuntimeException(\sprintf('Karim devrait recevoir UN conflit, obtenu « %s » :' . "\n" . '%s', $mail['subject'], $mail['text']));
        }
        foreach ([' · Nouveau', ' · Modifié', 'Depuis le dernier envoi', 'Cette liste vous est renvoyée'] as $absent) {
            if (str_contains($mail['text'], $absent)) {
                throw new RuntimeException(\sprintf('premier envoi : aucune marque attendue, trouvé « %s »', $absent));
            }
        }
    }

    #[When('U11M1, aussi coachée par Karim, joue à l\'extérieur ce samedi à 16h')]
    public function u11m1AussiCoacheeParKarimJoue(): void
    {
        $this->mainCoach('U11M1', $this->karimId);
        $this->awayMatch('U11M1', $this->saturday, '16:00');

        // Témoin du décor : Karim tient U15F1, le premier coup d'envoi (15h30) ; ses conflits sont
        // U15F1 × U13F1, l'ancien, inchangé, et U15F1 × U11M1, le nouveau. U13F1 × U11M1 ne fait
        // aucune ligne : il n'y est retenu nulle part (règle prouvée au plus bas par
        // MatchConflictDetectorTest::testASoleMainOfThreeOverlappingMatchesHoldsTheFirstKickoffAndHasTwoConflicts).
        // U11M1 joue APRÈS U15F1 : à coup d'envoi égal, l'id — aléatoire — déciderait qui Karim
        // tient, et l'ancien conflit disparaîtrait une fois sur deux.
        $expected = [$this->teamPair('U15F1', 'U13F1'), $this->teamPair('U15F1', 'U11M1')];
        sort($expected);
        $seen = $this->doublePairsOf($this->karimId);
        if ($expected !== $seen) {
            throw new RuntimeException(\sprintf('le décor devrait mettre Karim en double sur U15F1 × U13F1 et U15F1 × U11M1, le radar voit : %s', json_encode($seen)));
        }
    }

    #[Then('Karim reçoit un e-mail qui liste ses 2 conflits, dont 1 marqué nouveau')]
    public function karimRecoitSesDeuxConflitsDontUnNouveau(): void
    {
        $this->assertSent();
        $mail = $this->nextMailForKarim();
        if (2 !== substr_count($mail['text'], 'Personne en double — ')) {
            throw new RuntimeException('la liste COMPLÈTE de Karim devrait porter 2 tuiles, l\'ancienne comprise :' . "\n" . $mail['text']);
        }
        if (1 !== substr_count($mail['text'], ' · Nouveau') || str_contains($mail['text'], ' · Modifié')) {
            throw new RuntimeException('seul le nouveau conflit devrait être marqué, l\'ancien inchangé ne l\'est pas :' . "\n" . $mail['text']);
        }
        if (!str_contains($mail['text'], '1 conflit est nouveau ou modifié : il est marqué.')) {
            throw new RuntimeException('la phrase « Depuis le dernier envoi… » manque :' . "\n" . $mail['text']);
        }
    }

    #[Then('un nouvel envoi sans changement est refusé : rien de nouveau depuis le dernier envoi')]
    public function unNouvelEnvoiSansChangementEstRefuse(): void
    {
        $again = $this->apiPost('fixtures/conflicts/mail-coaches', [], $this->smallClubToken);
        if (422 !== $again['status'] || 'Rien de nouveau depuis le dernier envoi : cochez « Tout renvoyer » pour renvoyer les listes.' !== ($again['json']['error'] ?? null)) {
            throw new RuntimeException(\sprintf('un envoi sans nouveauté devrait être un 422 nommé, obtenu %d %s', $again['status'], json_encode($again['json'])));
        }
    }

    #[When('le gestionnaire renvoie tout aux coachs')]
    public function leGestionnaireRenvoieTout(): void
    {
        $this->lastSend = $this->apiPost('fixtures/conflicts/mail-coaches', ['resendAll' => true], $this->smallClubToken);
    }

    #[Then('Karim reçoit sa liste complète, qui dit qu\'aucun conflit n\'est nouveau ou modifié depuis l\'envoi précédent')]
    public function karimRecoitSaListeRenvoyee(): void
    {
        $this->assertSent();
        $mail = $this->nextMailForKarim();
        if (2 !== substr_count($mail['text'], 'Personne en double — ')) {
            throw new RuntimeException('la liste renvoyée devrait porter les 2 tuiles :' . "\n" . $mail['text']);
        }
        if (!str_contains($mail['text'], 'Cette liste vous est renvoyée : aucun conflit de cette liste n\'est nouveau ou modifié depuis l\'envoi du ')) {
            throw new RuntimeException('la phrase de renvoi manque :' . "\n" . $mail['text']);
        }
        if (str_contains($mail['text'], ' · Nouveau') || str_contains($mail['text'], ' · Modifié')) {
            throw new RuntimeException('une liste renvoyée sans nouveauté ne porte aucune marque :' . "\n" . $mail['text']);
        }
    }

    #[AfterScenario]
    public function nettoyer(): void
    {
        $this->teardownSmallClub();
    }

    /** @return list<string> les personnes que le radar voit en double sur deux matchs (MATCH_MATCH) */
    private function personsInDouble(): array
    {
        $conflicts = $this->apiGet('fixtures/conflicts', $this->smallClubToken)['json']['conflicts'] ?? [];
        $persons = [];
        foreach (\is_array($conflicts) ? $conflicts : [] as $conflict) {
            if (\is_array($conflict) && 'MATCH_MATCH' === ($conflict['type'] ?? null) && \is_string($conflict['coachId'] ?? null)) {
                $persons[] = $conflict['coachId'];
            }
        }

        return array_values(array_unique($persons));
    }

    /**
     * Les paires d'équipes des conflits « personne en double » (MATCH_MATCH, non tenus) que le radar
     * voit pour cette personne, triées.
     *
     * @return list<string>
     */
    private function doublePairsOf(string $personId): array
    {
        $conflicts = $this->apiGet('fixtures/conflicts', $this->smallClubToken)['json']['conflicts'] ?? [];
        $pairs = [];
        foreach (\is_array($conflicts) ? $conflicts : [] as $conflict) {
            if (!\is_array($conflict) || 'MATCH_MATCH' !== ($conflict['type'] ?? null) || $personId !== ($conflict['coachId'] ?? null) || isset($conflict['heldBy'])) {
                continue;
            }
            $left = \is_array($conflict['left'] ?? null) ? (string) ($conflict['left']['teamId'] ?? '') : '';
            $right = \is_array($conflict['right'] ?? null) ? (string) ($conflict['right']['teamId'] ?? '') : '';
            $sides = [$left, $right];
            sort($sides);
            $pairs[] = implode('|', $sides);
        }
        sort($pairs);

        return $pairs;
    }

    /** La paire de deux équipes du décor, au format de {@see doublePairsOf}. */
    private function teamPair(string $teamA, string $teamB): string
    {
        $sides = [$this->smallClubTeamIds[$teamA], $this->smallClubTeamIds[$teamB]];
        sort($sides);

        return implode('|', $sides);
    }

    private function newCoach(string $first, string $last, ?string $email): string
    {
        $created = $this->apiPost('coaches', array_filter(['firstName' => $first, 'lastName' => $last, 'email' => $email], static fn (?string $v): bool => null !== $v), $this->smallClubToken);
        if (!\in_array($created['status'], [200, 201], true)) {
            throw new RuntimeException(\sprintf('création du coach « %s » refusée (HTTP %d)', $first, $created['status']));
        }

        return $this->smallClubStringField($created['json'], 'id');
    }

    private function mainCoach(string $teamName, string $coachId): void
    {
        $assigned = $this->apiPost('team_coaches', ['teamId' => $this->smallClubTeamIds[$teamName], 'coachId' => $coachId, 'role' => 'MAIN'], $this->smallClubToken);
        if (!\in_array($assigned['status'], [200, 201], true)) {
            throw new RuntimeException(\sprintf('affectation à « %s » refusée (HTTP %d)', $teamName, $assigned['status']));
        }
    }

    /** Un match à l'extérieur (sans gymnase du club : aucune collision de salle ne brouille le radar). */
    private function awayMatch(string $teamName, string $date, string $kickoff): string
    {
        $created = $this->apiPost('fixtures', [
            'teamId' => $this->smallClubTeamIds[$teamName],
            'matchDate' => $date,
            'homeAway' => 'AWAY',
            'opponentLabel' => 'Les Loups',
            'kickoffTime' => $kickoff,
        ], $this->smallClubToken);
        if (!\in_array($created['status'], [200, 201], true)) {
            throw new RuntimeException(\sprintf('le match de « %s » a été refusé (HTTP %d)', $teamName, $created['status']));
        }

        return $this->smallClubStringField($created['json'], 'id');
    }

    private function assertSent(): void
    {
        if (200 !== $this->lastSend['status']) {
            throw new RuntimeException(\sprintf('l\'envoi aux coachs a répondu %d (200 attendu) : %s', $this->lastSend['status'], json_encode($this->lastSend['json'])));
        }
    }

    /**
     * Le prochain e-mail NEUF de Karim dans Mailpit (la file d'envoi est asynchrone : boucle bornée).
     *
     * @return array{subject: string, text: string}
     */
    private function nextMailForKarim(): array
    {
        for ($attempt = 0; $attempt < 20; ++$attempt) {
            $search = $this->httpGet($this->mailpitBase . '/api/v1/search?query=' . rawurlencode('to:' . $this->karimEmail));
            $messages = $search['json']['messages'] ?? [];
            foreach (\is_array($messages) ? $messages : [] as $message) {
                $id = \is_array($message) ? ($message['ID'] ?? null) : null;
                if (!\is_string($id) || \in_array($id, $this->seenMailIds, true)) {
                    continue;
                }
                $this->seenMailIds[] = $id;
                $body = $this->httpGet($this->mailpitBase . '/api/v1/message/' . $id)['json'];

                return ['subject' => (string) ($body['Subject'] ?? ''), 'text' => (string) ($body['Text'] ?? '')];
            }
            sleep(1);
        }

        throw new RuntimeException('aucun e-mail neuf pour Karim dans le webmail — la file d\'envoi est-elle consommée ?');
    }
}
