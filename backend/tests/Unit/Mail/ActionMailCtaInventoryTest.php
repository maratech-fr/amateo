<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mail;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * **PROMESSE (D1) : aucun e-mail sortant n'échappe à la politique du bouton d'action.** Tout
 * foyer d'`Email` sous `src/` est, par construction, soit un e-mail qui PORTE un lien d'action
 * (et doit poser son bouton CTA via les en-têtes {@see App\Mail\EmailTemplateRenderer::CTA_URL_HEADER}
 * / {@see App\Mail\EmailTemplateRenderer::CTA_LABEL_HEADER}), soit un e-mail EXEMPT (accusé, refus,
 * avis — aucun lien d'action à cliquer). Cette garde STATIQUE ancre l'inventaire fermé : un
 * `new Email(` ajouté demain sans être classé ici fait ROUGIR la CI, forçant l'auteur à trancher
 * (CTA ou exempt) plutôt qu'à laisser filer un e-mail nu.
 *
 * On interroge `git grep` (fichiers suivis seulement, rapide, portée `src/` → ce test, qui cite
 * l'idiome dans sa prose et dans l'inventaire, est hors portée). On compare par PRODUCTEUR
 * (fichier) et par NOMBRE de foyers : la valeur de la garde est l'EXHAUSTIVITÉ, pas le numéro de
 * ligne (qui glisse au premier câblage). Les deux sens sont gardés :
 *  - un foyer grepé dans un fichier non listé, ou un foyer de PLUS dans un fichier listé → ROUGE
 *    (« site non listé ») ;
 *  - une entrée d'inventaire sans foyer grepé en face → ROUGE (« entrée sans site »).
 *
 * La classification CTA/exempt (lien + libellé attendus, branches des foyers bi-branches
 * {@see CTA_SITES}) est documentée ici pour la lecture ; sa VÉRITÉ comportementale (l'en-tête ==
 * le lien du corps, le libellé, le bouton rendu une fois) vit dans les tests par mail de l'étage 2.
 *
 * Non-bloquant à dessein (tourne dans `backend-tests`, pas un step de `blocking-tests`) : patron
 * {@see App\Tests\Unit\ValidationExceptionCarriesViolationsTest} — une discipline de sortie, un
 * faux vert ne corrompt aucun étage aval, il est rattrapé au gate `backend-tests`.
 */
#[Group('phase1')]
final class ActionMailCtaInventoryTest extends TestCase
{
    private const string BACKEND = __DIR__ . '/../../..';

    /** Le motif des foyers d'`Email` : `new Email` ou `new TemplatedEmail`, `\b` final pour ne pas mordre un suffixe. */
    private const string EMAIL_SITE_PATTERN = 'new (Templated)?Email\b';

    /**
     * Foyers CTA — un e-mail à lien d'action : {fichier => [branche => [lien, libellé]]}. Les
     * foyers bi-branches (une seule `new Email`, plusieurs issues) listent CHAQUE branche ; le
     * `count` attendu reste le nombre de `new Email` du fichier ({@see EXPECTED_SITE_COUNT}).
     *
     * @var array<string, array<string, array{0: string, 1: string}>>
     */
    private const array CTA_SITES = [
        'src/Command/ClubErasureReminderCommand.php' => ['rappel' => ['{base}/register', 'Inscrire un gestionnaire']],
        'src/Command/DeploymentTickCommand.php' => ['transition' => ['{base}/admin', 'Ouvrir la console']],
        'src/Command/FeedbackDigestCommand.php' => ['digest' => ['{base}/admin?tab=incidents', 'Traiter les signalements']],
        'src/Command/HealthAlertCommand.php' => ['alerte' => ['{base}/admin', 'Ouvrir la console']],
        'src/Service/AccountErasureService.php' => ['orphelin' => ['{base}/register', 'Inscrire un gestionnaire']],
        'src/Service/ClubInvitationMailer.php' => ['invitation' => ['{base}/invitation/{token}', 'Accepter l\'invitation']],
        'src/Service/InactivityMailBuilder.php' => ['inactivite' => ['{base}/login', 'Me connecter']],
        'src/Service/OrphanAccountMailBuilder.php' => ['sans-club' => ['{base}/login', 'Me connecter']],
        'src/Service/PeriodReminderMailBuilder.php' => ['periode' => ['{base}/', 'Ouvrir le cockpit']],
        'src/Service/PlacementRunEmailBuilder.php' => ['placement' => ['{base}/matchs', 'Ouvrir le calendrier des matchs']],
        'src/Service/TransitionReminderMailBuilder.php' => ['transition' => ['{base}/', 'Ouvrir l\'application']],
    ];

    /**
     * Foyers MIXTES : une même `new Email` dont une branche porte un CTA et une autre est exempte,
     * ou qui sert plusieurs builders. Listés à part pour que leur nature soit explicite.
     *
     * @var array<string, string>
     */
    private const array MIXED_SITES = [
        'src/Service/ClubApprovalService.php' => 'foyer demande (CTA {base}/club-approval/{token} « Examiner la demande ») + foyer décision bi-branche (approuvée : CTA {base}/login « Me connecter » ; refusée : EXEMPT)',
        'src/Service/CoachWishMailBuilder.php' => 'foyer unique email() servant buildCoachLink (DÉJÀ câblé /doleances) + buildDigest/buildFinalRecap (CTA {base}/ « Ouvrir le cockpit »)',
        'src/Service/FeedbackMailBuilder.php' => 'build() accusé (EXEMPT) + buildTreated() (CTA {base}/nouveautes « Voir les nouveautés »)',
    ];

    /**
     * Foyers EXEMPTS ou DÉJÀ câblés hors de ce lot — aucun CTA à ajouter ici.
     *
     * @var array<string, string>
     */
    private const array OTHER_SITES = [
        'src/Controller/AuthController.php' => 'confirmation de changement d\'e-mail (DÉJÀ câblé) + avis à l\'ancienne adresse (EXEMPT)',
        'src/Controller/PasswordController.php' => 'réinitialisation du mot de passe (DÉJÀ câblé)',
        'src/Service/Registration/RegisterService.php' => 'vérification d\'inscription (DÉJÀ câblé) + « ce compte existe déjà » (EXEMPT)',
    ];

    /**
     * Le NOMBRE de foyers `new Email` attendu par fichier (⇄ `git grep`). C'est l'assertion
     * opposable : un foyer de plus/de moins dans un fichier, ou un fichier qui entre/sort, rougit.
     *
     * @var array<string, int>
     */
    private const array EXPECTED_SITE_COUNT = [
        'src/Command/ClubErasureReminderCommand.php' => 1,
        'src/Command/DeploymentTickCommand.php' => 1,
        'src/Command/FeedbackDigestCommand.php' => 1,
        'src/Command/HealthAlertCommand.php' => 1,
        'src/Controller/AuthController.php' => 2,
        'src/Controller/PasswordController.php' => 1,
        'src/Service/AccountErasureService.php' => 1,
        'src/Service/ClubApprovalService.php' => 2,
        'src/Service/ClubInvitationMailer.php' => 1,
        'src/Service/CoachWishMailBuilder.php' => 1,
        'src/Service/FeedbackMailBuilder.php' => 2,
        'src/Service/InactivityMailBuilder.php' => 1,
        'src/Service/OrphanAccountMailBuilder.php' => 1,
        'src/Service/PeriodReminderMailBuilder.php' => 1,
        'src/Service/PlacementRunEmailBuilder.php' => 1,
        'src/Service/Registration/RegisterService.php' => 2,
        'src/Service/TransitionReminderMailBuilder.php' => 1,
    ];

    public function testTheMotifMatchesAKnownEmailSite(): void
    {
        // `grep-zero-is-not-proof` : on prouve que le motif MORD un positif avant de s'en servir.
        self::assertSame(1, preg_match('~' . self::EMAIL_SITE_PATTERN . '~', '$email = (new Email)->from($x);'), 'le motif doit reconnaître `(new Email)`');
        self::assertSame(1, preg_match('~' . self::EMAIL_SITE_PATTERN . '~', '(new TemplatedEmail())'), 'le motif doit reconnaître `new TemplatedEmail`');
    }

    public function testEveryEmailSiteIsClassifiedAndEveryClassifiedSiteExists(): void
    {
        $grepped = $this->emailSiteCountsFromGit();

        // Sens 1 — tout foyer grepé est classé (« site non listé » → ROUGE).
        foreach ($grepped as $file => $count) {
            self::assertArrayHasKey($file, self::EXPECTED_SITE_COUNT, \sprintf(
                "Un foyer `new Email` vit dans `%s`, non classé par l'inventaire CTA/exempt.\n"
                . 'Classez-le (CTA avec lien+libellé, ou exempt avec sa raison) — un e-mail ne part jamais nu.',
                $file,
            ));
            self::assertSame(self::EXPECTED_SITE_COUNT[$file], $count, \sprintf(
                '`%s` porte %d foyer(s) `new Email`, l\'inventaire en attendait %d — un e-mail a été ajouté/retiré sans classement.',
                $file,
                $count,
                self::EXPECTED_SITE_COUNT[$file],
            ));
        }

        // Sens 2 — toute entrée d'inventaire a son foyer en face (« entrée sans site » → ROUGE).
        foreach (self::EXPECTED_SITE_COUNT as $file => $expected) {
            self::assertArrayHasKey($file, $grepped, \sprintf(
                '`%s` est inventorié mais ne porte plus aucun `new Email` — entrée morte à retirer.',
                $file,
            ));
        }

        // Cohérence interne : toute entrée riche (CTA/mixte/autre) est un fichier inventorié.
        foreach ([...array_keys(self::CTA_SITES), ...array_keys(self::MIXED_SITES), ...array_keys(self::OTHER_SITES)] as $file) {
            self::assertArrayHasKey($file, self::EXPECTED_SITE_COUNT, \sprintf('`%s` est décrit mais absent du compte attendu.', $file));
        }
    }

    /**
     * Le compte de foyers `new Email` par fichier, lu de `git grep -nE` sous `src/`.
     *
     * @return array<string, int>
     */
    private function emailSiteCountsFromGit(): array
    {
        $process = Process::fromShellCommandline(
            'git grep -nE "' . self::EMAIL_SITE_PATTERN . '" -- src || true',
            self::BACKEND,
        );
        $process->run();

        $counts = [];
        foreach (explode("\n", trim($process->getOutput())) as $line) {
            if ('' === $line) {
                continue;
            }
            // Format `path:line:code` → la clé est le chemin.
            $file = substr($line, 0, (int) strpos($line, ':'));
            $counts[$file] = ($counts[$file] ?? 0) + 1;
        }

        return $counts;
    }
}
