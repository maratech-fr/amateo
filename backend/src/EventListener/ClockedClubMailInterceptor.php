<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Clock\ClubClock;
use App\Controller\MailboxController;
use App\Entity\Club;
use App\Entity\ClubMailboxMessage;
use App\Mail\ClubBusinessMail;
use App\Repository\ClubMailboxMessageRepository;
use App\Service\ClubDay;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * P4-16 — un club à horloge SIMULÉE n'envoie JAMAIS de vrai e-mail MÉTIER : chaque rappel/relance
 * est rangé dans sa « boîte aux lettres » (option A fondateur 2026-10-02). L'animateur de démo
 * rejoue « à trois semaines des vacances » et VOIT ces e-mails dans l'app, sans spammer personne.
 *
 * On branche `Symfony\Mailer` au `MessageEvent`, à PRIORITÉ HAUTE, et SEULEMENT à l'ENFILAGE
 * (`isQueued()` — les envois de l'app passent par le bus Messenger, `SendEmailMessage` en async).
 * Rejeter à l'enfilage empêche la mise en file : le worker ne voit jamais le message. On ne touche
 * PAS la phase worker (envoi réel d'un club sans horloge).
 *
 * ⚠ DURCISSEMENT SÉCURITÉ (revue PR B) — l'interception est en LISTE BLANCHE, DEUX gardes :
 *
 *  1. SEULS les e-mails MÉTIER explicitement marqués ({@see ClubBusinessMail}) sont candidats.
 *     Le router `/api/*` pose le GUC tenant du DEMANDEUR sur TOUTE requête d'un membre connecté,
 *     y compris les routes PUBLIC_ACCESS (`/api/password/forgot`, vérification d'e-mail…). Sans
 *     ce filtre, un membre d'un club à horloge déclenchait un reset pour une VICTIME, captait le
 *     mail dans la boîte (lisible par tout membre) et lisait le jeton → prise de compte. Les
 *     e-mails de COMPTE (reset, vérif/changement d'adresse, inscription, feedback, santé) ne
 *     portent PAS le marqueur et partent donc TOUJOURS réellement — y compris vers un membre du
 *     club. Liste BLANCHE = défaut fermé : un e-mail non marqué n'est jamais capté.
 *  2. TOUS les destinataires (To/Cc/Cci) doivent appartenir au club du GUC — membre actif
 *     (`club_user`→`app_user.email`) ou coach du club (`coach.email` : les campagnes de vœux
 *     visent des coachs NON-utilisateurs). Un seul destinataire hors club → l'e-mail part réel et
 *     on logue un avertissement (sans contenu). Défense en profondeur : un builder métier buggé
 *     qui adresserait hors club ne capte jamais. Comparaison normalisée (minuscules + trim).
 *
 * Le corps est capté TEL QU'IL EST à l'enfilage, donc AVANT la signature de marque (posée chez le
 * worker, qui ne tourne jamais ici) : `body_text` porte le texte métier, `body_html` est en
 * général nul. Décision assumée (plan horloge, 2026-10-02).
 */
final readonly class ClockedClubMailInterceptor implements EventSubscriberInterface
{
    /**
     * SEC-31 — le libellé neutre qui remplace AU STOCKAGE le lien personnel à jeton du coach
     * (`…/doleances/<jeton>`). Ce jeton EST l'identité du coach : le garder en clair dans la
     * boîte le rendrait lisible par TOUT membre du club (le détail n'a pas de garde gestionnaire,
     * {@see MailboxController}) et par le rôle `amateo_read` — une prise de
     * doléances d'autrui, à un clic. Le message garde son sens de démo, jamais le jeton.
     */
    private const string MASKED_LINK_LABEL = 'lien personnel masqué';

    public function __construct(
        private Connection $connection,
        private EntityManagerInterface $entityManager,
        private ClubClock $clubClock,
        private ClubDay $clubDay,
        private ClubMailboxMessageRepository $mailbox,
        private LoggerInterface $logger,
    ) {}

    /**
     * @return array<class-string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        // Priorité HAUTE : décider de l'interception AVANT tout autre travail sur le message.
        return [MessageEvent::class => ['onMessage', 100]];
    }

    public function onMessage(MessageEvent $event): void
    {
        // Seulement à l'enfilage : rejeter ici empêche la mise en file (le worker ne verra
        // jamais le message). La phase worker est l'envoi réel d'un club sans horloge.
        if (!$event->isQueued()) {
            return;
        }

        $message = $event->getMessage();
        if (!$message instanceof Email) {
            return;
        }

        // Garde 1 — seuls les e-mails MÉTIER sont candidats. Un e-mail de compte (reset, vérif…)
        // n'est jamais marqué → part toujours réel, même adressé à un membre du club.
        if (!ClubBusinessMail::isClubBusiness($message)) {
            return;
        }

        $clubId = $this->currentClubId();
        if (null === $clubId) {
            return; // hors contexte club → envoi réel
        }

        $club = $this->entityManager->find(Club::class, $clubId);
        if (!$club instanceof Club) {
            return;
        }
        if (!$this->clubClock->simulatedTodayFor($club) instanceof DateTimeImmutable) {
            return; // club sans horloge active → envoi normal
        }

        // Garde 2 — tous les destinataires doivent appartenir au club du GUC.
        $recipients = $this->recipients($message);
        if ([] === $recipients) {
            return; // aucun destinataire exploitable → ne jamais capter
        }
        $clubEmails = $this->clubRecipientEmails($clubId);
        foreach ($recipients as $recipient) {
            if (!isset($clubEmails[$recipient])) {
                // Un destinataire hors club sur un e-mail métier (builder buggé) : on NE capte
                // PAS, l'e-mail part réel. Avertissement SANS contenu ni adresse.
                $this->logger->warning('Clocked club business mail left real: a recipient is outside the club', ['club' => $clubId]);

                return;
            }
        }

        // Horloge active + e-mail métier adressé au club → ranger dans la boîte, puis rejeter.
        $this->mailbox->record($this->capture($message, $clubId, $this->clubDay->todayFor($club)));
        $event->reject();
    }

    private function currentClubId(): ?string
    {
        $clubId = (string) $this->connection->fetchOne('SELECT COALESCE(current_setting(\'app.club_id\', true), \'\')');

        return '' === $clubId ? null : $clubId;
    }

    /**
     * Les adresses NORMALISÉES de tous les destinataires (To + Cc + Cci).
     *
     * @return list<string>
     */
    private function recipients(Email $email): array
    {
        $out = [];
        foreach ([...$email->getTo(), ...$email->getCc(), ...$email->getBcc()] as $address) {
            $out[] = $this->normalize($address->getAddress());
        }

        return $out;
    }

    /**
     * Les e-mails NORMALISÉS autorisés à capter pour ce club : membres ACTIFS + coachs du club.
     * Lu sous le GUC déjà posé (RLS borne `club_user`/`coach` au club), filtre club_id explicite
     * en ceinture. `app_user` est global (pas de RLS).
     *
     * @return array<string, true>
     */
    private function clubRecipientEmails(string $clubId): array
    {
        /** @var list<mixed> $members */
        $members = $this->connection->fetchFirstColumn(
            'SELECT u.email FROM app_user u JOIN club_user cu ON cu.user_id = u.id WHERE cu.club_id = ? AND cu.is_active = true',
            [$clubId],
        );
        /** @var list<mixed> $coaches */
        $coaches = $this->connection->fetchFirstColumn(
            'SELECT email FROM coach WHERE club_id = ? AND email IS NOT NULL',
            [$clubId],
        );

        $set = [];
        foreach ([...$members, ...$coaches] as $email) {
            if (\is_string($email) && '' !== trim($email)) {
                $set[$this->normalize($email)] = true;
            }
        }

        return $set;
    }

    private function normalize(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    private function capture(Email $email, string $clubId, DateTimeImmutable $simulatedDate): ClubMailboxMessage
    {
        $message = new ClubMailboxMessage;
        $message->setClubId($clubId);
        $message->setSimulatedDate($simulatedDate);
        $message->setFromAddress(mb_substr($this->joinAddresses($email->getFrom()), 0, 255));
        $message->setToAddress(mb_substr($this->joinAddresses($email->getTo()), 0, 1000));
        $message->setSubject(mb_substr($email->getSubject() ?? '', 0, 998));
        $message->setBodyText($this->maskPersonalLinks($this->asString($email->getTextBody())));
        $message->setBodyHtml($this->maskPersonalLinks($this->asString($email->getHtmlBody())));

        return $message;
    }

    /**
     * SEC-31 — masque tout lien personnel à jeton (`…/doleances/<jeton>`) AVANT l'écriture en
     * boîte : le jeton est remplacé par un libellé neutre, pour text ET html. Le `\S+` capte le
     * jeton seul (aucune espace), `\S*` en amont capte l'URL absolue qui le précède sur sa ligne.
     * `preg_replace` ne rend `null` que sur erreur regex (motif constant ici) — repli défensif.
     */
    private function maskPersonalLinks(?string $body): ?string
    {
        if (null === $body) {
            return null;
        }

        return preg_replace('~\S*/doleances/\S+~', self::MASKED_LINK_LABEL, $body) ?? $body;
    }

    /**
     * @param array<Address> $addresses
     */
    private function joinAddresses(array $addresses): string
    {
        return implode(', ', array_map(static fn (Address $a): string => $a->toString(), $addresses));
    }

    /**
     * `Email::getTextBody()`/`getHtmlBody()` rendent `resource|string|null` : un corps posé comme
     * flux doit être lu en chaîne. On rejette ce message (jamais envoyé), lire le flux du clone
     * d'enfilage est donc sans effet de bord.
     */
    private function asString(mixed $body): ?string
    {
        if (\is_resource($body)) {
            $contents = stream_get_contents($body);

            return \is_string($contents) ? $contents : null;
        }

        return \is_string($body) ? $body : null;
    }
}
