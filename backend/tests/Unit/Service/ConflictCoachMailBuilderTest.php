<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Club;
use App\Entity\Coach;
use App\Entity\Fixture;
use App\Entity\Season;
use App\Entity\Team;
use App\Entity\Venue;
use App\Enum\CoachGender;
use App\Enum\ConflictPersonRole;
use App\Enum\FixtureHomeAway;
use App\EventListener\EmailSignatureListener;
use App\Mail\ClubMailMetadata;
use App\Mail\EmailTemplateRenderer;
use App\Service\BrandAssets;
use App\Service\ConflictCoachMailBuilder;
use App\Service\Conflicts\ConflictMoments;
use App\Service\ProductIdentity;
use App\Storage\LogoStorage;
use App\Tests\Integration\Api\ConflictCoachMailApiTest;
use DateTimeImmutable;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Email;

/**
 * Les deux e-mails « conflits aux coachs » disent mot pour mot ce que le plan fixe : une liste à sa
 * date, indicative, sans décision ni suivi (« il ne pourra pas être surpris »). Le mail « conflits »
 * porte ses tuiles en fragment HTML (enveloppé par la carte de marque) et la même liste en texte ;
 * le mail « plus aucun conflit » est un texte seul.
 *
 * Club fictif « Les Aiglons » (libellé « AIGLONS »), Nadia gestionnaire, Karim B. (principal U15,
 * joue en SM2), saison « 2025-2026 », aujourd'hui = 03/11/2026 pour le club.
 *
 * Le jumeau d'envoi réel (collecteur, destinataires) : {@see ConflictCoachMailApiTest}.
 */
#[Group('phase1')]
final class ConflictCoachMailBuilderTest extends TestCase
{
    private Club $club;

    private Season $season;

    private Coach $karim;

    private Team $u15;

    private Team $sm2;

    private Team $u13a;

    private Venue $nord;

    // ── Mail « conflits » : enveloppe ──

    /** Sujet au singulier / pluriel, expéditeur « Nadia (club) via produit », destinataire, club marqué, aucun bouton. */
    public function testTheConflictsMailIsAddressedFromTheSenderOnBehalfOfTheClub(): void
    {
        $one = $this->conflicts([$this->matchMatch()]);
        $two = $this->conflicts([$this->matchMatch(), $this->coachUnavailable(5)]);

        self::assertSame('Un conflit vous concerne — AIGLONS', $one->getSubject());
        self::assertSame('2 conflits vous concernent — AIGLONS', $two->getSubject());
        self::assertSame('karim@aiglons.test', $one->getTo()[0]->getAddress());
        self::assertSame('Nadia (AIGLONS) via ' . new ProductIdentity()->name(), $one->getFrom()[0]->getName());
        self::assertSame($this->club->getId(), $one->getHeaders()->getHeaderBody(ClubMailMetadata::CLUB_ID_HEADER));
        self::assertTrue($one->getHeaders()->has(EmailTemplateRenderer::HTML_FRAGMENT_HEADER), 'les tuiles voyagent en fragment HTML');
        self::assertFalse($one->getHeaders()->has(EmailTemplateRenderer::CTA_URL_HEADER), 'aucun bouton : rien à faire, le mail informe');
    }

    /** Le texte salue, dit « à titre indicatif », nomme la saison et la date, puis rappelle que le planning peut bouger. */
    public function testTheConflictsMailSaysWhatItIsAndWhatItIsNot(): void
    {
        $text = (string) $this->conflicts([$this->matchMatch()])->getTextBody();

        self::assertStringStartsWith('Bonjour Karim,', $text);
        self::assertStringContainsString('Nadia (AIGLONS) vous informe, à titre indicatif, des conflits de personnes sur un match, datés d\'aujourd\'hui ou à venir, qui vous concernent pour la saison 2025-2026, au 03/11/2026.', $text);
        self::assertStringContainsString('Cette liste vaut à la date ci-dessus ; le planning peut encore bouger. Les matchs sans heure connue ne sont pas vérifiés.', $text);
    }

    /** Un conflit traité part sans son statut ni sa note : le mail ne porte aucune décision. */
    public function testNeitherTreatmentStatusNorNoteReachesTheCoach(): void
    {
        $treated = $this->matchMatch() + ['resolution' => ['status' => 'RESOLVED_INTERNALLY', 'note' => 'NOTE-INTERNE-DU-BUREAU', 'updatedAt' => '2026-11-01T10:00:00+00:00']];
        $email = $this->conflicts([$treated]);
        $all = (string) $email->getTextBody() . (string) $email->getHtmlBody();

        self::assertStringNotContainsString('NOTE-INTERNE-DU-BUREAU', $all);
        self::assertStringNotContainsString('RESOLVED', $all);
    }

    // ── Mail « conflits » : tuiles ──

    /** Chaque famille a son bandeau « famille — personne · jour date », et les tuiles sont triées par date. */
    public function testEachFamilyHasItsBannerAndTilesAreSortedByDate(): void
    {
        $email = $this->conflicts([$this->matchMatch(), $this->matchTraining(), $this->coachUnavailable(3)]);
        $text = (string) $email->getTextBody();

        self::assertStringContainsString('Personne en double — Karim B. · samedi 14/03', $text);
        self::assertStringContainsString('Match × entraînement — Karim B. · vendredi 13/03', $text);
        self::assertStringContainsString('Coach principal indisponible — Karim B. · samedi 14/03', $text);
        self::assertLessThan(strpos($text, 'Personne en double'), strpos($text, 'Match × entraînement'), 'le vendredi 13/03 passe avant le samedi 14/03');
        self::assertStringContainsString('Personne en double', (string) $email->getHtmlBody(), 'le fragment porte les mêmes tuiles');
    }

    /** Le mot de gravité est propre au mail : 3 « Important », 4 « À regarder », 5 « À surveiller ». */
    public function testTheSeverityWordIsTheMailsOwn(): void
    {
        self::assertStringContainsString('Important', (string) $this->conflicts([$this->coachUnavailable(3)])->getHtmlBody());
        self::assertStringContainsString('À regarder', (string) $this->conflicts([$this->coachUnavailable(4)])->getHtmlBody());
        self::assertStringContainsString('À surveiller', (string) $this->conflicts([$this->coachUnavailable(5)])->getHtmlBody());
    }

    /** Domicile et extérieur : équipe (rôle), lieu, adversaire, horaires, départ/retour quand le trajet est connu. */
    public function testMatchSidesSayTheTeamRoleWhereWhoAndWhen(): void
    {
        $text = (string) $this->conflicts([$this->matchMatch()])->getTextBody();

        self::assertStringContainsString('U15 (coach) · domicile · vs Les Loups · 15:30 → 17:25', $text);
        self::assertStringContainsString('SM2 (joueur) · extérieur à Villeurbanne · vs Les Ours · départ 16:00 · 17:00 · retour 19:30', $text);
        self::assertStringContainsString('Chevauchement 16:00 → 17:25 (1h25)', $text);
    }

    /** Une coach qui joue lit « joueuse ». */
    public function testThePlayerWordFollowsTheCoachsGender(): void
    {
        $this->karim->setGender(CoachGender::FEMALE);

        self::assertStringContainsString('SM2 (joueuse)', (string) $this->conflicts([$this->matchMatch()])->getTextBody());
    }

    /** Heure estimée, lieu inconnu, trajet inconnu, adversaire vide : chaque inconnue est dite, jamais inventée. */
    public function testEveryUnknownIsSaidNeverInvented(): void
    {
        $estimated = $this->matchMatch(right: ['kickoffTime' => null, 'estimatedKickoff' => true, 'estimatedKickoffTime' => '17:00']);
        $unknownPlace = $this->matchMatch(right: ['opponentPlace' => null]);
        $unknownTravel = $this->matchMatch(right: ['travelOneWayMinutes' => null]);
        $noOpponent = $this->matchMatch(left: ['opponentLabel' => '']);

        self::assertStringContainsString('17:00 (heure estimée)', (string) $this->conflicts([$estimated])->getTextBody());
        self::assertStringContainsString('extérieur (lieu inconnu)', (string) $this->conflicts([$unknownPlace])->getTextBody());
        self::assertStringContainsString('extérieur à Villeurbanne · vs Les Ours · 17:00 · trajet inconnu', (string) $this->conflicts([$unknownTravel])->getTextBody());
        $noOpponentText = (string) $this->conflicts([$noOpponent])->getTextBody();
        self::assertStringContainsString('U15 (coach) · domicile · 15:30 → 17:25', $noOpponentText);
        self::assertStringNotContainsString('vs  ·', $noOpponentText);
    }

    /** Le côté entraînement nomme l'équipe, le gymnase et la séance ; un chevauchement qui passe minuit garde ses deux jours. */
    public function testTrainingSideAndOverlapPastMidnight(): void
    {
        $text = (string) $this->conflicts([$this->matchTraining()])->getTextBody();
        self::assertStringContainsString('Entraînement U13A · Gymnase Nord · 18:30 → 20:00', $text);

        $late = $this->matchMatch();
        $late['start'] = '2026-03-14T23:30:00';
        $late['end'] = '2026-03-15T00:45:00';
        self::assertStringContainsString('Chevauchement sam. 14/03 23:30 → dim. 15/03 00:45 (1h15)', (string) $this->conflicts([$late])->getTextBody());
    }

    /** L'indisponibilité nomme le match manqué, et dit quand son heure n'est qu'estimée. */
    public function testUnavailabilityNamesTheMissedMatch(): void
    {
        self::assertStringContainsString('Indisponible pour le match de U15, samedi 14/03 à 15:30 · vs Les Loups', (string) $this->conflicts([$this->coachUnavailable(3)])->getTextBody());

        $estimated = $this->coachUnavailable(3);
        $estimated['fixture']['kickoffTime'] = null;
        $estimated['fixture']['estimatedKickoff'] = true;
        $estimated['fixture']['estimatedKickoffTime'] = '15:30';
        self::assertStringContainsString('Indisponible pour le match de U15, samedi 14/03 à 15:30 (heure estimée) · vs Les Loups', (string) $this->conflicts([$estimated])->getTextBody());
    }

    /**
     * Abus : équipe, adversaire, gymnase, lieu et nom du coach sont des saisies du club (ou de FBI)
     * — tout est échappé UNE fois dans le builder, aucune balise ne survit dans le fragment, et le
     * fragment ne charge aucune image distante.
     */
    public function testUntrustedNamesAreEscaped(): void
    {
        $this->u15->setName('<script>alert(1)</script>');
        $this->nord->setName('<b>Nord</b>');
        $this->karim->setFirstName('<i>Karim</i>');
        $hostile = $this->matchMatch(left: ['opponentLabel' => '"><img src=x onerror=alert(2)>'], right: ['opponentPlace' => '<u>Lyon</u>']);

        $html = (string) $this->conflicts([$hostile, $this->matchTraining()])->getHtmlBody();

        foreach (['<script', '<img', '<b>Nord', '<i>Karim', '<u>Lyon', 'onerror=alert(2)>'] as $raw) {
            self::assertStringNotContainsString($raw, $html);
        }
        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringContainsString('&lt;b&gt;Nord', $html);
        self::assertStringNotContainsString('src="http', $html, 'aucune image distante');
    }

    /** Taille : 50 tuiles, liste complète non plafonnée, tiennent sous 100 Ko une fois dans la carte (Gmail coupe au-delà). */
    public function testFiftyTilesStayUnderOneHundredKilobytes(): void
    {
        $conflicts = [];
        for ($i = 0; $i < 50; ++$i) {
            $conflicts[] = $this->matchMatch(left: ['fixtureId' => \sprintf('ffffffff-2222-4000-8000-%012d', $i), 'opponentLabel' => 'Adversaire au nom assez long numéro ' . $i]);
        }
        $email = $this->conflicts($conflicts);
        $fragment = (string) $email->getHtmlBody();

        self::assertSame(50, substr_count((string) $email->getTextBody(), 'Personne en double — '), 'aucune tuile n\'est retirée');
        $card = new EmailTemplateRenderer()->render((string) $email->getTextBody(), 'Amateo', 'Accroche', 'https://amateo.test', 'AIGLONS', true, bodyHtmlFragment: $fragment);
        self::assertLessThan(100 * 1024, \strlen($card), \sprintf('50 tuiles pèsent %d octets', \strlen($card)));
    }

    // ── Mail « plus aucun conflit » ──

    /** Sujet, salutation, phrase datée avec la saison, attribution au gestionnaire COURANT, sans tuile. */
    public function testTheClearedMailSaysNothingIsLeft(): void
    {
        $email = $this->builder()->buildCleared('karim@aiglons.test', $this->karim, $this->club, $this->season, 'Paul', new DateTimeImmutable('2026-11-03'));
        $text = (string) $email->getTextBody();

        self::assertSame('Plus aucun conflit sur un match ne vous concerne — AIGLONS', $email->getSubject());
        self::assertSame('Paul (AIGLONS) via ' . new ProductIdentity()->name(), $email->getFrom()[0]->getName());
        self::assertStringStartsWith('Bonjour Karim,', $text);
        self::assertStringContainsString('Les conflits qui vous avaient été signalés ne sont plus d\'actualité au 03/11/2026 : aucun conflit de personne sur un match, daté d\'aujourd\'hui ou à venir, ne vous concerne pour la saison 2025-2026. Paul (AIGLONS) vous en informe.', $text);
        self::assertStringContainsString('Le planning peut encore bouger. Les matchs sans heure connue ne sont pas vérifiés.', $text);
        self::assertStringNotContainsString('Personne en double', $text . (string) $email->getHtmlBody(), 'aucune tuile');
    }

    /**
     * Le mail « plus aucun conflit » espace ses paragraphes COMME la liste : chacune de ses trois
     * phrases est un paragraphe à part, du même style que les paragraphes du mail « conflits »
     * (comparés une fois passés par la vraie chaîne de signature, là où le coach les lit).
     */
    public function testTheClearedMailSpacesItsParagraphsLikeTheList(): void
    {
        $list = $this->conflicts([$this->matchMatch()]);
        $cleared = $this->builder()->buildCleared('karim@aiglons.test', $this->karim, $this->club, $this->season, 'Paul', new DateTimeImmutable('2026-11-03'));
        foreach ([$list, $cleared] as $email) {
            $this->signatureListener()->onMessage(new MessageEvent($email, Envelope::create($email), 'null://null', true));
        }

        $listGreeting = $this->paragraphStartingWith((string) $list->getHtmlBody(), 'Bonjour Karim,');
        self::assertNotNull($listGreeting, 'témoin : la liste pose sa salutation en paragraphe');
        foreach (['Bonjour Karim,', 'Les conflits qui vous avaient été signalés', 'Le planning peut encore bouger.'] as $start) {
            $paragraph = $this->paragraphStartingWith((string) $cleared->getHtmlBody(), $start);
            self::assertNotNull($paragraph, \sprintf('« %s… » doit être un paragraphe à part', $start));
            self::assertSame($listGreeting->getAttribute('style'), $paragraph->getAttribute('style'), 'même espacement que la liste');
        }
    }

    // ── Mémoire par conflit : marques « Nouveau » / « Modifié » et phrase d'envoi précédent ──

    /**
     * Le coach repère ce qui a bougé depuis le dernier envoi : la ligne-titre de la tuile finit par
     * « · Nouveau » ou « · Modifié » APRÈS le mot de gravité — le début « Personne en double — »,
     * que l'envoi réel compte, reste intact.
     */
    public function testANewOrChangedConflictIsMarkedAtTheEndOfItsTitleLine(): void
    {
        $double = $this->matchMatch();
        $unavailable = $this->coachUnavailable(5);
        $text = (string) $this->conflicts(
            [$double, $unavailable],
            [$double['fingerprint'] => 'new', $unavailable['fingerprint'] => 'changed'],
            new DateTimeImmutable('2026-11-03 14:32'),
        )->getTextBody();

        self::assertStringContainsString("Personne en double — Karim B. · samedi 14/03 · Important · Nouveau\n", $text);
        self::assertStringContainsString("Coach principal indisponible — Karim B. · samedi 14/03 · À surveiller · Modifié\n", $text);
        self::assertSame(1, substr_count($text, 'Personne en double — '), 'le début de ligne compté par l\'envoi réel est intact');
    }

    /** Dans le HTML, la marque est une seconde PASTILLE à côté de la gravité ; une tuile non marquée n'en a pas. */
    public function testTheHtmlMarkIsASecondPillNextToTheSeverity(): void
    {
        $double = $this->matchMatch();
        $html = (string) $this->conflicts(
            [$double, $this->coachUnavailable(5)],
            [$double['fingerprint'] => 'new'],
            new DateTimeImmutable('2026-11-03 14:32'),
        )->getHtmlBody();

        $marked = $this->tileNodes($html, 'Personne en double');
        self::assertSame('Important', $marked[1]['text']);
        self::assertSame('Nouveau', $marked[2]['text'], 'la marque suit le mot de gravité');
        self::assertTrue($marked[2]['pill'], 'la marque est une pastille');

        $plain = $this->tileNodes($html, 'Coach principal indisponible');
        self::assertNotContains('Nouveau', array_column($plain, 'text'), 'témoin : la tuile non marquée n\'a pas de pastille de marque');
        self::assertNotContains('Modifié', array_column($plain, 'text'));
    }

    /** La phrase qui suit l'intro dit combien de conflits ont bougé depuis l'envoi précédent — singulier et pluriel. */
    public function testTheIntroSaysHowManyConflictsAreNewOrChangedSinceTheLastSend(): void
    {
        $double = $this->matchMatch();
        $unavailable = $this->coachUnavailable(5);
        $training = $this->matchTraining();
        $since = new DateTimeImmutable('2026-11-03 14:32');

        $one = (string) $this->conflicts([$double, $unavailable], [$double['fingerprint'] => 'new'], $since)->getTextBody();
        $two = (string) $this->conflicts([$double, $unavailable, $training], [$double['fingerprint'] => 'new', $training['fingerprint'] => 'changed'], $since)->getTextBody();

        $sentence = 'Depuis le dernier envoi du 03/11/2026, 1 conflit est nouveau ou modifié : il est marqué.';
        self::assertStringContainsString($sentence, $one);
        self::assertLessThan(strpos($one, 'Personne en double'), strpos($one, $sentence), 'la phrase précède les tuiles');
        self::assertGreaterThan(strpos($one, 'vous informe, à titre indicatif'), strpos($one, $sentence), 'la phrase suit l\'intro');
        self::assertStringContainsString('Depuis le dernier envoi du 03/11/2026, 2 conflits sont nouveaux ou modifiés : ils sont marqués.', $two);
    }

    /**
     * « Tout renvoyer » sans nouveauté : la liste dit qu'AUCUN conflit de cette liste n'est nouveau ou
     * modifié — vrai même si un autre conflit a disparu ou si une équipe a été renommée. Jamais
     * « rien n'a changé », qui serait faux dans ces cas.
     */
    public function testAResentListSaysNoneOfItsConflictsIsNewOrChanged(): void
    {
        $email = $this->conflicts([$this->matchMatch(), $this->coachUnavailable(5)], [], new DateTimeImmutable('2026-11-10 09:15'));
        $text = (string) $email->getTextBody();
        $all = $text . (string) $email->getHtmlBody();

        self::assertStringContainsString('Cette liste vous est renvoyée : aucun conflit de cette liste n\'est nouveau ou modifié depuis l\'envoi du 10/11/2026.', $text);
        self::assertStringContainsString('Cette liste vous est renvoyée', (string) $email->getHtmlBody(), 'le fragment porte la même phrase');
        self::assertStringNotContainsString('rien n\'a changé', $all);
        self::assertStringNotContainsString('Depuis le dernier envoi', $all, 'aucun conflit marqué : pas de phrase de décompte');
        self::assertStringNotContainsString('· Nouveau', $text);
        self::assertStringNotContainsString('· Modifié', $text);
    }

    /**
     * Un coach sans AUCUN envoi détaillé connu (mémoire d'avant cette fonction) : ni marque ni
     * phrase — on ne prétend pas savoir ce qu'il a déjà reçu.
     */
    public function testWithoutAPreviousSendThereIsNeitherMarkNorSentence(): void
    {
        $email = $this->conflicts([$this->matchMatch(), $this->coachUnavailable(5)]);
        $all = (string) $email->getTextBody() . (string) $email->getHtmlBody();

        foreach (['Nouveau', 'Modifié', 'Depuis le dernier envoi', 'Cette liste vous est renvoyée'] as $absent) {
            self::assertStringNotContainsString($absent, $all);
        }
    }

    // ── Forme servie par le radar et structure de la tuile ──

    /**
     * Un conflit construit EXACTEMENT comme le radar le sert (côtés par `ConflictMoments::fixtureView`,
     * bornes au format mural `ConflictMoments::WALL_CLOCK_FORMAT`) montre son chevauchement : la ligne
     * « Chevauchement … » ne doit jamais disparaître faute de lire le format réel.
     */
    public function testAConflictAsTheRadarServesItShowsItsOverlap(): void
    {
        $home = $this->servedSide($this->u15, FixtureHomeAway::HOME, '15:30', '2026-03-14 15:00', '2026-03-14 17:25', ConflictPersonRole::MAIN);
        $away = $this->servedSide($this->sm2, FixtureHomeAway::AWAY, '17:00', '2026-03-14 16:00', '2026-03-14 19:30', ConflictPersonRole::PLAYER);
        $conflict = [
            'type' => 'MATCH_MATCH',
            'severity' => 3,
            'coachId' => $this->karim->getId(),
            'coachRole' => 'PLAYER',
            'start' => new DateTimeImmutable('2026-03-14 16:00')->format(ConflictMoments::WALL_CLOCK_FORMAT),
            'end' => new DateTimeImmutable('2026-03-14 17:25')->format(ConflictMoments::WALL_CLOCK_FORMAT),
            'left' => $home,
            'right' => $away,
        ];

        $email = $this->conflicts([$conflict]);

        self::assertStringContainsString('Chevauchement 16:00 → 17:25 (1h25)', (string) $email->getTextBody());
        self::assertStringContainsString('Chevauchement 16:00 → 17:25 (1h25)', (string) $email->getHtmlBody());
    }

    /**
     * La tuile se lit comme la référence validée : titre de famille (gras), mot de gravité en
     * PASTILLE à contour, sous-ligne « personne · jour date » à part, libellé d'équipe et heure
     * estimée en gras, chevauchement en PIED de tuile. On teste les éléments et leur ordre, jamais
     * les couleurs.
     */
    public function testATileReadsTitlePillSubLineSidesThenOverlap(): void
    {
        $estimated = $this->matchMatch(right: ['kickoffTime' => null, 'estimatedKickoff' => true, 'estimatedKickoffTime' => '17:00']);
        $nodes = $this->tileNodes((string) $this->conflicts([$estimated])->getHtmlBody(), 'Personne en double');

        self::assertSame('Personne en double', $nodes[0]['text']);
        self::assertTrue($nodes[0]['bold'], 'le titre de famille est en gras');
        self::assertFalse($nodes[0]['pill'], 'témoin : le titre n\'est pas une pastille');
        self::assertSame('Important', $nodes[1]['text'], 'le mot de gravité suit le titre');
        self::assertTrue($nodes[1]['pill'], 'le mot de gravité est une pastille à contour');
        self::assertSame('Karim B. · samedi 14/03', $nodes[2]['text'], 'la personne et le jour forment une sous-ligne à part');
        self::assertFalse($nodes[2]['bold']);
        self::assertFalse($nodes[2]['pill']);
        self::assertFalse($this->nodeNamed($nodes, '15:30 → 17:25')['bold'], 'témoin négatif : une heure réelle n\'est pas en gras');
        self::assertTrue($this->nodeNamed($nodes, 'U15 (coach)')['bold'], 'le libellé d\'équipe est en gras');
        self::assertTrue($this->nodeNamed($nodes, 'SM2 (joueur)')['bold']);
        self::assertTrue($this->nodeNamed($nodes, '17:00 (heure estimée)')['bold'], 'l\'heure estimée est en gras');
        self::assertStringStartsWith('Chevauchement 16:00 → 17:25', $nodes[\count($nodes) - 1]['text'], 'le chevauchement ferme la tuile');
        foreach ($nodes as $node) {
            self::assertStringNotContainsString('Personne en double —', $node['text'], 'titre et sous-ligne ne sont plus un seul bandeau');
        }
    }

    /** Côté entraînement et indisponibilité : « Entraînement U13A » et l'heure estimée du match manqué en gras. */
    public function testTrainingLabelAndEstimatedMissedKickoffAreBold(): void
    {
        $training = $this->tileNodes((string) $this->conflicts([$this->matchTraining()])->getHtmlBody(), 'Match × entraînement');
        self::assertTrue($this->nodeNamed($training, 'Entraînement U13A')['bold']);

        $unavailable = $this->coachUnavailable(5);
        $unavailable['fixture']['kickoffTime'] = null;
        $unavailable['fixture']['estimatedKickoff'] = true;
        $unavailable['fixture']['estimatedKickoffTime'] = '15:30';
        $nodes = $this->tileNodes((string) $this->conflicts([$unavailable])->getHtmlBody(), 'Coach principal indisponible');
        self::assertSame('À surveiller', $nodes[1]['text']);
        self::assertTrue($nodes[1]['pill']);
        self::assertTrue($this->nodeNamed($nodes, '15:30 (heure estimée)')['bold']);
    }

    /** Genre non renseigné : « joueur », comme la référence — jamais l'écriture « joueur·euse ». */
    public function testAnUnspecifiedGenderReadsJoueur(): void
    {
        $this->karim->setGender(CoachGender::UNSPECIFIED);
        $email = $this->conflicts([$this->matchMatch()]);

        self::assertStringContainsString('SM2 (joueur)', (string) $email->getTextBody());
        self::assertStringNotContainsString('joueur·euse', (string) $email->getTextBody() . (string) $email->getHtmlBody());
    }

    /** Passé par la vraie chaîne de signature, le mail « plus aucun » porte le pied anti-usurpation (texte et HTML). */
    public function testTheClearedMailCarriesTheAntiSpoofFooter(): void
    {
        $email = $this->builder()->buildCleared('karim@aiglons.test', $this->karim, $this->club, $this->season, 'Paul', new DateTimeImmutable('2026-11-03'));

        $this->signatureListener()->onMessage(new MessageEvent($email, Envelope::create($email), 'null://null', true));

        self::assertStringContainsString('Vous ne connaissez pas ce club ? Ignorez ce message.', (string) $email->getTextBody());
        self::assertSame(1, substr_count((string) $email->getHtmlBody(), 'Vous ne connaissez pas ce club ? Ignorez ce message.'));
    }

    protected function setUp(): void
    {
        $this->club = new Club()->setName('Les Aiglons de Villefranche')->setShortName('AIGLONS');
        $this->season = new Season()->setName('2025-2026');
        $this->karim = new Coach()->setFirstName('Karim')->setLastName('Benali')->setEmail('karim@aiglons.test')->setGender(CoachGender::MALE);
        $this->u15 = new Team()->setName('U15');
        $this->sm2 = new Team()->setName('SM2');
        $this->u13a = new Team()->setName('U13A');
        $this->nord = new Venue()->setName('Gymnase Nord');
    }

    private function builder(): ConflictCoachMailBuilder
    {
        return new ConflictCoachMailBuilder;
    }

    /**
     * @param list<array<string, mixed>>     $conflicts
     * @param array<string, 'new'|'changed'> $marks          empreinte → marque
     * @param DateTimeImmutable|null         $previousSentAt le dernier envoi au coach (null : aucun reçu connu)
     */
    private function conflicts(array $conflicts, array $marks = [], ?DateTimeImmutable $previousSentAt = null): Email
    {
        return $this->builder()->buildConflicts(
            'karim@aiglons.test',
            $this->karim,
            $this->club,
            $this->season,
            'Nadia',
            new DateTimeImmutable('2026-11-03'),
            $conflicts,
            [$this->u15->getId() => $this->u15, $this->sm2->getId() => $this->sm2, $this->u13a->getId() => $this->u13a],
            [$this->nord->getId() => $this->nord],
            [$this->karim->getId() => $this->karim],
            $marks,
            $previousSentAt,
        );
    }

    /**
     * Karim coache U15 samedi 14/03 15:30 à domicile (vs Les Loups) et joue avec SM2 à 17:00 à
     * Villeurbanne (vs Les Ours, 1 h de trajet, match 1h30) → chevauchement 16:00 → 17:25.
     *
     * @param array<string, mixed> $left
     * @param array<string, mixed> $right
     *
     * @return array<string, mixed>
     */
    private function matchMatch(array $left = [], array $right = []): array
    {
        return [
            'type' => 'MATCH_MATCH',
            'severity' => 3,
            'coachId' => $this->karim->getId(),
            'coachRole' => 'PLAYER',
            'start' => '2026-03-14T16:00:00',
            'end' => '2026-03-14T17:25:00',
            'left' => $left + [
                'fixtureId' => 'ffffffff-1111-4000-8000-000000000001',
                'teamId' => $this->u15->getId(),
                'homeAway' => 'HOME',
                'matchDate' => '2026-03-14',
                'kickoffTime' => '15:30',
                'estimatedKickoff' => false,
                'estimatedKickoffTime' => null,
                'travelOneWayMinutes' => null,
                'matchDurationMinutes' => 115,
                'opponentLabel' => 'Les Loups',
                'windowStart' => '2026-03-14T15:00:00',
                'windowEnd' => '2026-03-14T17:25:00',
                'role' => 'MAIN',
            ],
            'right' => $right + [
                'fixtureId' => 'ffffffff-1111-4000-8000-000000000002',
                'teamId' => $this->sm2->getId(),
                'homeAway' => 'AWAY',
                'matchDate' => '2026-03-14',
                'kickoffTime' => '17:00',
                'estimatedKickoff' => false,
                'estimatedKickoffTime' => null,
                'travelOneWayMinutes' => 60,
                'matchDurationMinutes' => 90,
                'opponentLabel' => 'Les Ours',
                'opponentPlace' => 'Villeurbanne',
                'windowStart' => '2026-03-14T16:00:00',
                'windowEnd' => '2026-03-14T19:30:00',
                'role' => 'PLAYER',
            ],
            'fingerprint' => 'MATCH_MATCH:' . $this->karim->getId(),
        ];
    }

    /**
     * Karim coache U15 vendredi 13/03 à 18:30 (match avancé, domicile) alors que sa séance U13A
     * occupe le Gymnase Nord de 18:30 à 20:00.
     *
     * @return array<string, mixed>
     */
    private function matchTraining(): array
    {
        return [
            'type' => 'MATCH_TRAINING',
            'severity' => 4,
            'coachId' => $this->karim->getId(),
            'coachRole' => 'MAIN',
            'start' => '2026-03-13T18:30:00',
            'end' => '2026-03-13T20:00:00',
            'fixture' => [
                'fixtureId' => 'ffffffff-1111-4000-8000-000000000003',
                'teamId' => $this->u15->getId(),
                'homeAway' => 'HOME',
                'matchDate' => '2026-03-13',
                'kickoffTime' => '18:30',
                'estimatedKickoff' => false,
                'estimatedKickoffTime' => null,
                'travelOneWayMinutes' => null,
                'matchDurationMinutes' => 115,
                'opponentLabel' => 'Les Loups',
                'windowStart' => '2026-03-13T18:00:00',
                'windowEnd' => '2026-03-13T20:25:00',
                'role' => 'MAIN',
            ],
            'training' => [
                'slotTemplateId' => 'eeeeeeee-0000-4000-8000-000000000001',
                'scheduleId' => 'bbbbbbbb-0000-4000-8000-000000000001',
                'teamId' => $this->u13a->getId(),
                'venueId' => $this->nord->getId(),
                'dayOfWeek' => 5,
                'startTime' => '18:30',
                'durationMinutes' => 90,
                'role' => 'MAIN',
                'windowStart' => '2026-03-13T18:30:00',
                'windowEnd' => '2026-03-13T20:00:00',
            ],
            'fingerprint' => 'MATCH_TRAINING:' . $this->karim->getId(),
        ];
    }

    /** @return array<string, mixed> Karim indisponible pour le match de U15 samedi 14/03 15:30 (gravité donnée). */
    private function coachUnavailable(int $severity): array
    {
        return [
            'type' => 'MATCH_COACH_UNAVAILABLE',
            'severity' => $severity,
            'coachId' => $this->karim->getId(),
            'coachRole' => 'MAIN',
            'fixture' => [
                'fixtureId' => 'ffffffff-1111-4000-8000-000000000004',
                'teamId' => $this->u15->getId(),
                'homeAway' => 'HOME',
                'matchDate' => '2026-03-14',
                'kickoffTime' => '15:30',
                'estimatedKickoff' => false,
                'estimatedKickoffTime' => null,
                'travelOneWayMinutes' => null,
                'matchDurationMinutes' => 115,
                'opponentLabel' => 'Les Loups',
                'windowStart' => '2026-03-14T15:00:00',
                'windowEnd' => '2026-03-14T17:25:00',
                'role' => 'MAIN',
            ],
            'fingerprint' => 'MATCH_COACH_UNAVAILABLE:' . $this->karim->getId() . ':' . $severity,
        ];
    }

    /**
     * Un côté de match sérialisé par la VRAIE maison du radar ({@see ConflictMoments::fixtureView}),
     * décoré du lieu adverse comme le fait le flux sur un côté extérieur.
     *
     * @return array<string, mixed>
     */
    private function servedSide(Team $team, FixtureHomeAway $homeAway, string $kickoff, string $windowStart, string $windowEnd, ConflictPersonRole $role): array
    {
        $away = FixtureHomeAway::AWAY === $homeAway;
        $fixture = new Fixture;
        $fixture->setClubId($this->club->getId());
        $fixture->setSeasonId($this->season->getId());
        $fixture->setTeamId($team->getId());
        $fixture->setMatchDate(new DateTimeImmutable('2026-03-14'));
        $fixture->setHomeAway($homeAway);
        $fixture->setOpponentLabel($away ? 'Les Ours' : 'Les Loups');
        $fixture->setKickoffTime(DateTimeImmutable::createFromFormat('!H:i', $kickoff) ?: null);
        $window = ['start' => new DateTimeImmutable($windowStart), 'end' => new DateTimeImmutable($windowEnd)];

        $side = ConflictMoments::fixtureView([
            'fixture' => $fixture,
            'window' => $window,
            'venueWindow' => $window,
            'conflictWindow' => $window,
            'estimated' => false,
            'estimatedKickoffTime' => null,
            'travelOneWayMinutes' => $away ? 60 : null,
            'matchDurationMinutes' => $away ? 90 : 115,
            'personIds' => [$this->karim->getId()],
        ], $role);

        return $away ? $side + ['opponentPlace' => 'Villeurbanne'] : $side;
    }

    /**
     * Les nœuds de texte d'UNE tuile (de son titre de famille jusqu'avant la tuile suivante ou la
     * phrase de clôture), dans l'ordre de lecture, avec : en gras ? dans une pastille à contour ?
     *
     * @return list<array{text: string, bold: bool, pill: bool}>
     */
    private function tileNodes(string $fragment, string $familyTitle): array
    {
        $doc = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?><div id="tiles-root">' . $fragment . '</div>');
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $all = [];
        foreach (new DOMXPath($doc)->query('//div[@id="tiles-root"]//text()[normalize-space()]') ?: [] as $node) {
            // Les espaces insécables (filet de couleur `&nbsp;`) ne sont pas du texte lu.
            $text = trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', (string) $node->nodeValue));
            if ('' !== $text) {
                $all[] = ['text' => $text, 'bold' => $this->isBold($node), 'pill' => $this->inPill($node)];
            }
        }

        $start = null;
        foreach ($all as $i => $node) {
            if ($familyTitle === $node['text'] || str_starts_with($node['text'], $familyTitle . ' ')) {
                $start = $i;
                break;
            }
        }
        self::assertNotNull($start, \sprintf('aucun titre de famille « %s » dans le fragment', $familyTitle));

        $tile = [];
        foreach (\array_slice($all, $start) as $i => $node) {
            $isNextTitle = $i > 0 && \in_array($node['text'], ['Personne en double', 'Match × entraînement', 'Coach principal indisponible'], true);
            if ($isNextTitle || str_starts_with($node['text'], 'Cette liste vaut')) {
                break;
            }
            $tile[] = $node;
        }

        return $tile;
    }

    /**
     * @param list<array{text: string, bold: bool, pill: bool}> $nodes
     *
     * @return array{text: string, bold: bool, pill: bool}
     */
    private function nodeNamed(array $nodes, string $text): array
    {
        foreach ($nodes as $node) {
            if ($text === $node['text']) {
                return $node;
            }
        }
        self::fail(\sprintf('« %s » n\'est pas un élément de texte à part dans la tuile (lus : %s)', $text, implode(' | ', array_column($nodes, 'text'))));
    }

    /** Gras = le plus proche ancêtre qui décide du poids le met à 600 ou plus (ou `b`/`strong`). */
    private function isBold(DOMNode $node): bool
    {
        for ($el = $node->parentNode; $el instanceof DOMElement; $el = $el->parentNode) {
            if (\in_array(strtolower($el->tagName), ['b', 'strong'], true)) {
                return true;
            }
            if (1 === preg_match('/font-weight\s*:\s*([a-z0-9]+)/i', $el->getAttribute('style'), $m)) {
                return 'bold' === strtolower($m[1]) || (ctype_digit($m[1]) && (int) $m[1] >= 600);
            }
        }

        return false;
    }

    /**
     * Pastille = un élément EN LIGNE (`span`, ou `display:inline-block`) à contour complet
     * (`border:`) et coins arrondis — pas un bloc de carte ou de tuile, qui peut lui aussi être arrondi.
     */
    private function inPill(DOMNode $node): bool
    {
        for ($el = $node->parentNode; $el instanceof DOMElement && 'tiles-root' !== $el->getAttribute('id'); $el = $el->parentNode) {
            $style = $el->getAttribute('style');
            $inline = 'span' === strtolower($el->tagName) || 1 === preg_match('/display\s*:\s*inline-block/i', $style);
            if ($inline && 1 === preg_match('/(^|;)\s*border\s*:/i', $style) && str_contains($style, 'border-radius')) {
                return true;
            }
        }

        return false;
    }

    /** Le paragraphe (`<p>`) dont le texte commence par `$start`, dans un HTML signé. */
    private function paragraphStartingWith(string $html, string $start): ?DOMElement
    {
        $doc = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?>' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        foreach ($doc->getElementsByTagName('p') as $p) {
            if (str_starts_with(trim((string) preg_replace('/\s+/u', ' ', $p->textContent)), $start)) {
                return $p;
            }
        }

        return null;
    }

    private function signatureListener(): EmailSignatureListener
    {
        return new EmailSignatureListener(
            new ProductIdentity,
            new BrandAssets,
            new EmailTemplateRenderer,
            new class implements LogoStorage {
                public function store(string $clubId, string $bytes): void {}

                public function read(string $clubId): ?string
                {
                    return null;
                }

                public function delete(string $clubId): void {}
            },
        );
    }
}
