<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Club;
use App\Enum\ClubRole;
use App\Mail\ClubBusinessMail;
use App\Mail\ClubMailMetadata;
use App\Mail\EmailTemplateRenderer;
use DateTimeImmutable;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mime\Email;

/**
 * Les mails des demandes d'adhésion et de la fermeture d'un club sans gestionnaire (chantier
 * Membre) — textes validés mot pour mot par le fondateur :
 *  - aux gestionnaires : une ou plusieurs demandes d'adhésion, puis la synthèse au plafond ;
 *  - au demandeur : accepté (avec son rôle) ou refusé (avec l'échéance de son compte s'il
 *    devient orphelin) ;
 *  - aux Membres restants : le club n'a plus de gestionnaire (J0, rappel J-7) ;
 *  - une demande de RÔLE (PR 3) : aux gestionnaires (demande, synthèse, transmission à l'adresse
 *    officielle), au demandeur (nommé, refusé, dépassé, sans réponse, transmis).
 *
 * Tous sont des mails MÉTIER du club ({@see ClubBusinessMail}) portant son identité
 * ({@see ClubMailMetadata}). Sous horloge simulée, un mail dont tous les destinataires sont
 * membres/coachs du club va dans la boîte aux lettres ; un destinataire EXTÉRIEUR (demandeur
 * refusé, sorti du club) fait partir le mail réellement — ce qui n'arrive pour un club démo que si
 * son animateur décide une adhésion en attente (les inscriptions réelles n'atteignent jamais une
 * démo : `findRealByFfbbCode` les exclut).
 *
 * Un lien d'action est posé sur sa propre ligne et doublé du bouton CTA ; sans base front
 * configurée, ni lien ni bouton (patron des crons).
 */
final class MembershipRequestMailBuilder
{
    public function __construct(
        #[Autowire('%env(default::FRONTEND_BASE_URL)%')]
        private readonly string $frontendBaseUrl = '',
        // Expéditeur unique — le défaut ne sert qu'aux tests unitaires.
        private readonly MailFrom $mailFrom = new MailFrom,
        // Nom produit unique — le défaut ne sert qu'aux tests unitaires.
        private readonly ProductIdentity $productIdentity = new ProductIdentity,
    ) {}

    /**
     * Une ou plusieurs demandes d'adhésion nées ensemble (même transaction) — un mail par gestionnaire.
     *
     * @param list<array{name: string, email: string}> $requesters noms DÉJÀ nettoyés (MailSafeName)
     */
    public function buildNewRequests(string $to, Club $club, array $requesters): Email
    {
        $product = $this->productIdentity->name();
        $clubName = $club->getName();
        $count = \count($requesters);
        if (1 === $count) {
            $subject = \sprintf('Demande d\'adhésion — %s souhaite rejoindre %s', $requesters[0]['name'], $clubName);
            $lines = [\sprintf('%s (%s) demande à rejoindre l\'espace %s du club %s.', $requesters[0]['name'], $requesters[0]['email'], $product, $clubName)];
        } else {
            $subject = \sprintf('%d demandes d\'adhésion — %s', $count, $clubName);
            $lines = [\sprintf('%d personnes demandent à rejoindre l\'espace %s du club %s :', $count, $product, $clubName)];
            foreach ($requesters as $requester) {
                $lines[] = \sprintf('- %s (%s)', $requester['name'], $requester['email']);
            }
        }
        $link = $this->link('/club');
        $lines[] = '';
        $lines[] = 'Connectez-vous pour accepter ou refuser cette demande dans l\'écran Club, section « Demandes d\'adhésion »' . (null !== $link ? " :\n" . $link : '.');
        $lines[] = '';
        $lines[] = 'Si vous ne connaissez pas cette personne, refusez la demande. Ce message ne vous demande rien d\'autre que d\'ouvrir l\'application.';

        return $this->email($to, $club, $subject, $lines, $link, 'Voir les demandes');
    }

    /** Le plafond quotidien du club est atteint : une seule synthèse, puis silence jusqu'au lendemain. */
    public function buildDigest(string $to, Club $club): Email
    {
        $product = $this->productIdentity->name();
        $link = $this->link('/club');

        return $this->email($to, $club, \sprintf('Plusieurs demandes d\'adhésion aujourd\'hui — %s', $club->getName()), [
            \sprintf('Plusieurs personnes ont demandé à rejoindre l\'espace %s du club %s aujourd\'hui. Vous ne recevrez plus de message individuel avant demain.', $product, $club->getName()),
            '',
            'Retrouvez toutes les demandes dans l\'écran Club, section « Demandes d\'adhésion »' . (null !== $link ? " :\n" . $link : '.'),
        ], $link, 'Voir les demandes');
    }

    /** Le demandeur est accepté — le mail dit avec quel rôle il entre. */
    public function buildAccepted(string $to, Club $club, string $firstName, ClubRole $role): Email
    {
        $product = $this->productIdentity->name();
        $link = $this->link('/login');
        $lines = [
            \sprintf('Le club %s a accepté votre demande : vous avez maintenant accès à son espace %s.', $club->getName(), $product),
            ClubRole::MANAGER === $role ? 'Vous y entrez comme gestionnaire.' : 'Vous y entrez comme membre : vous pouvez tout consulter.',
        ];
        if (null !== $link) {
            $lines[] = '';
            $lines[] = "Connectez-vous :\n" . $link;
        }

        return $this->email($to, $club, \sprintf('%s a accepté votre demande', $club->getName()), $lines, $link, 'Me connecter', $firstName);
    }

    /** Le demandeur est refusé — un seul mail, qui porte l'échéance du compte s'il devient orphelin. */
    public function buildRefused(string $to, Club $club, string $firstName, ?DateTimeImmutable $deletionDeadline): Email
    {
        $product = $this->productIdentity->name();
        $lines = [
            \sprintf('Le club %s n\'a pas donné suite à votre demande d\'accès à son espace %s.', $club->getName(), $product),
            '',
            'Si vous pensez qu\'il s\'agit d\'une erreur, rapprochez-vous de votre club.',
        ];
        if ($deletionDeadline instanceof DateTimeImmutable) {
            $lines[] = '';
            $lines[] = \sprintf(
                'Vous n\'avez plus accès à aucun club sur %s. Sans nouvel accès, votre compte sera supprimé le %s. Vous pourrez vous réinscrire ensuite si besoin.',
                $product,
                $deletionDeadline->format('d/m/Y'),
            );
        }

        return $this->email($to, $club, \sprintf('%s n\'a pas donné suite à votre demande', $club->getName()), $lines, null, null, $firstName);
    }

    /** Aux Membres restants : le club n'a plus de gestionnaire (J0) ou rappel à 7 jours de la suppression. */
    public function buildClubClosing(string $to, Club $club, string $firstName, DateTimeImmutable $deadline, bool $reminder): Email
    {
        $product = $this->productIdentity->name();
        $subject = \sprintf('L\'espace %s du club %s n\'a plus de gestionnaire', $product, $club->getName());
        // Chantier Membre (PR 3) : le Membre qui est gestionnaire du club demande lui-même depuis
        // le bandeau de l'application (plus de renvoi à l'inscription).
        $link = $this->link('/club');
        $lines = [
            \sprintf(
                'Le dernier gestionnaire de l\'espace %s du club %s a quitté le service. Sans reprise par un gestionnaire, cet espace sera supprimé définitivement le %s%s.',
                $product,
                $club->getName(),
                $deadline->format('d/m/Y'),
                $reminder ? ', dans 7 jours' : '',
            ),
            '',
            'Vous gardez un accès en consultation jusque-là. Si vous êtes gestionnaire du club, cliquez « Demander à devenir gestionnaire » dans le bandeau de l\'application : l\'adresse officielle du club validera.',
        ];
        if (null !== $link) {
            $lines[] = '';
            $lines[] = "Ouvrir l'application :\n" . $link;
        }

        return $this->email($to, $club, ($reminder ? 'Rappel — ' : '') . $subject, $lines, $link, 'Ouvrir l\'application', $firstName);
    }

    // ---------------------------------------------------------------------------------------
    // Chantier Membre (PR 3) — un Membre demande à devenir gestionnaire.
    // ---------------------------------------------------------------------------------------

    /** Aux gestionnaires actifs : un Membre demande à devenir gestionnaire. `$requesterName` DÉJÀ nettoyé (MailSafeName). */
    public function buildRoleRequest(string $to, Club $club, string $requesterName, string $requesterEmail): Email
    {
        $product = $this->productIdentity->name();
        $link = $this->link('/club');

        return $this->email($to, $club, \sprintf('%s demande à devenir gestionnaire de %s', $requesterName, $club->getName()), [
            \sprintf('%s (%s), membre de l\'espace %s du club %s, demande à en devenir gestionnaire.', $requesterName, $requesterEmail, $product, $club->getName()),
            '',
            'Connectez-vous pour accepter ou refuser dans l\'écran Club, section « Membres »' . (null !== $link ? " :\n" . $link : '.'),
            '',
            'Un gestionnaire peut tout modifier : n\'acceptez que si vous connaissez cette personne. Sans réponse sous 14 jours, la demande sera transmise à l\'adresse officielle du club.',
        ], $link, 'Voir les membres');
    }

    /** Le plafond quotidien des demandes de rôle est atteint : une synthèse « Membres », puis silence. */
    public function buildRoleDigest(string $to, Club $club): Email
    {
        $product = $this->productIdentity->name();
        $link = $this->link('/club');

        return $this->email($to, $club, \sprintf('Plusieurs demandes de gestionnaire aujourd\'hui — %s', $club->getName()), [
            \sprintf('Plusieurs membres ont demandé aujourd\'hui à devenir gestionnaire de l\'espace %s du club %s. Vous ne recevrez plus de message individuel avant demain.', $product, $club->getName()),
            '',
            'Retrouvez ces demandes dans l\'écran Club, section « Membres »' . (null !== $link ? " :\n" . $link : '.'),
        ], $link, 'Voir les membres');
    }

    /** Le demandeur est nommé gestionnaire (par un gestionnaire ou par l'adresse officielle). */
    public function buildRoleGranted(string $to, Club $club, string $firstName): Email
    {
        $product = $this->productIdentity->name();
        $link = $this->link('/login');
        $lines = [\sprintf('Le club %s a accepté votre demande : vous êtes maintenant gestionnaire de son espace %s.', $club->getName(), $product)];
        if (null !== $link) {
            $lines[] = '';
            $lines[] = "Connectez-vous :\n" . $link;
        }

        return $this->email($to, $club, \sprintf('%s vous a nommé gestionnaire', $club->getName()), $lines, $link, 'Me connecter', $firstName);
    }

    /**
     * La demande de rôle est refusée ; le Membre garde la consultation. `$renewFrom` : refus de
     * l'ADRESSE OFFICIELLE — la date à partir de laquelle il pourra renouveler sa demande.
     */
    public function buildRoleRefused(string $to, Club $club, string $firstName, ?DateTimeImmutable $renewFrom = null): Email
    {
        $lines = [
            \sprintf('Le club %s n\'a pas donné suite à votre demande de devenir gestionnaire. Vous gardez votre accès en consultation.', $club->getName()),
            '',
            'Si vous pensez qu\'il s\'agit d\'une erreur, rapprochez-vous de votre club.',
        ];
        if ($renewFrom instanceof DateTimeImmutable) {
            $lines[] = '';
            $lines[] = \sprintf('Vous pourrez renouveler votre demande à partir du %s.', $renewFrom->format('d/m/Y'));
        }

        return $this->email($to, $club, \sprintf('%s n\'a pas donné suite à votre demande', $club->getName()), $lines, null, null, $firstName);
    }

    /** Un autre a repris le club avant que la demande ne soit examinée. */
    public function buildSuperseded(string $to, Club $club, string $firstName): Email
    {
        $product = $this->productIdentity->name();

        return $this->email($to, $club, \sprintf('%s a déjà retrouvé un gestionnaire', $club->getName()), [
            \sprintf('Un gestionnaire a repris l\'espace %s du club %s avant que votre demande ne soit examinée. Vous pouvez lui demander à devenir gestionnaire depuis l\'application.', $product, $club->getName()),
        ], null, null, $firstName);
    }

    /** L'adresse officielle n'a pas répondu dans les 7 jours. */
    public function buildOfficialExpired(string $to, Club $club, string $firstName, DateTimeImmutable $requestedAt): Email
    {
        return $this->email($to, $club, \sprintf('Le club %s n\'a pas répondu à votre demande', $club->getName()), [
            \sprintf('L\'adresse officielle du club %s n\'a pas répondu à votre demande du %s dans les 7 jours. Vous pouvez la renvoyer depuis l\'application, ou contacter le support.', $club->getName(), $requestedAt->format('d/m/Y')),
        ], null, null, $firstName);
    }

    /** Aux gestionnaires : la demande restée sans réponse 14 jours part à l'adresse officielle. `$requesterName` DÉJÀ nettoyé. */
    public function buildRoleEscalatedToManagers(string $to, Club $club, string $requesterName): Email
    {
        $link = $this->link('/club');

        return $this->email($to, $club, \sprintf('La demande de %s est transmise à l\'adresse officielle de %s', $requesterName, $club->getName()), [
            \sprintf('Sans réponse depuis 14 jours, la demande de %s de devenir gestionnaire de %s a été transmise à l\'adresse officielle du club, qui peut l\'accepter. Vous pouvez toujours la traiter dans l\'écran Club, section « Membres »', $requesterName, $club->getName())
                . (null !== $link ? " :\n" . $link : '.'),
        ], $link, 'Voir les membres');
    }

    /** Au demandeur : sa demande sans réponse des gestionnaires part à l'adresse officielle. */
    public function buildRoleEscalatedToRequester(string $to, Club $club, string $firstName, DateTimeImmutable $requestedAt): Email
    {
        return $this->email($to, $club, \sprintf('Votre demande est transmise au club %s', $club->getName()), [
            \sprintf('Les gestionnaires de %s n\'ont pas répondu à votre demande du %s. Elle est transmise à l\'adresse officielle du club, qui peut l\'accepter sous 7 jours.', $club->getName(), $requestedAt->format('d/m/Y')),
        ], null, null, $firstName);
    }

    private function link(string $path): ?string
    {
        return '' !== $this->frontendBaseUrl ? rtrim($this->frontendBaseUrl, '/') . $path : null;
    }

    /**
     * Foyer UNIQUE de rendu : salutation, lignes, signature produit, CTA, marqueurs métier du club.
     *
     * @param list<string> $lines
     */
    private function email(string $to, Club $club, string $subject, array $lines, ?string $ctaUrl, ?string $ctaLabel, ?string $firstName = null): Email
    {
        $greeting = null !== $firstName ? \sprintf('Bonjour %s,', $firstName) : 'Bonjour,';
        $text = implode("\n", [$greeting, '', ...$lines, '', $this->productIdentity->name()]);

        $email = ClubBusinessMail::mark(
            (new Email)
                ->from($this->mailFrom->address())
                ->to($to)
                ->subject($subject)
                ->text($text),
        );
        if (null !== $ctaUrl && null !== $ctaLabel) {
            $headers = $email->getHeaders();
            $headers->addTextHeader(EmailTemplateRenderer::CTA_URL_HEADER, $ctaUrl);
            $headers->addTextHeader(EmailTemplateRenderer::CTA_LABEL_HEADER, $ctaLabel);
        }
        ClubMailMetadata::mark($email, $club);

        return $email;
    }
}
