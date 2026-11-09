<?php

declare(strict_types=1);

namespace App\Tests\Unit\Documentation;

use App\Tests\Integration\Api\ErrorDetailSurvivesProdModeTest;
use App\Tests\Security\ClubApprovalFlowTest;
use PHPUnit\Framework\TestCase;

/**
 * Les trois pages publiques à jeton (approbation de club, invitation, doléances des coachs) et les
 * classes de `src/` qu'elles importent directement ne LÈVENT aucune `…HttpException`.
 *
 * Pourquoi : l'anti-énumération de ces pages (jeton inconnu, malformé ou consommé → 404 OCTET-
 * IDENTIQUE) repose sur le fait qu'elles répondent par des `JsonResponse` explicites, toutes
 * identiques. Une `HttpException` instanciée dans `src/` sur ce chemin serait relayée en prod avec
 * son message par `HttpExceptionDetailListener` ({@see ErrorDetailSurvivesProdModeTest}) — un corps
 * différent, donc un oracle « ce jeton a existé ». La parité elle-même est jouée en prod par
 * {@see ClubApprovalFlowTest::testUnknownAndConsumedTokensStayByteIdenticalWhenDebugIsOff} ; ce garde
 * couvre les deux autres pages et les chemins que ce test ne rejoue pas.
 *
 * Un refus nouveau sur ces routes s'écrit en `JsonResponse` explicite (le 404 partagé du contrôleur
 * pour tout ce qui doit rester indistinguable).
 */
final class PublicTokenControllersNeverThrowHttpExceptionsTest extends TestCase
{
    private const array CONTROLLERS = [
        'Controller/ClubApprovalController.php',
        'Controller/PublicInvitationController.php',
        'Controller/PublicCoachWishController.php',
    ];

    private const string THROW_PATTERN = '/throw\s+new\s+\\\\?[\w\\\\]*HttpException\s*\(/';

    public function testNoTokenPageNorTheClassesItImportsThrowsAnHttpException(): void
    {
        $offenders = [];
        foreach ($this->filesToInspect() as $relative) {
            $source = (string) file_get_contents($this->src() . '/' . $relative);
            if (1 === preg_match(self::THROW_PATTERN, $source)) {
                $offenders[] = $relative;
            }
        }

        self::assertSame([], $offenders, \sprintf(
            "Ces fichiers du chemin d'une page publique à jeton lèvent une HttpException :\n  - %s\n\n"
            . "Elle serait relayée en prod avec son message (HttpExceptionDetailListener) et casserait\n"
            . 'le 404 octet-identique « inconnu / consommé » : répondez par une JsonResponse explicite.',
            implode("\n  - ", $offenders),
        ));
    }

    /** Le garde voit bien les classes importées (sinon il ne garderait que les contrôleurs). */
    public function testTheInspectedSetReachesTheImportedServices(): void
    {
        $files = $this->filesToInspect();

        self::assertContains('Service/ClubApprovalService.php', $files);
        self::assertContains('Service/ClubInvitationManager.php', $files);
        self::assertContains('Service/CoachWishUpserter.php', $files);
    }

    /** @return list<string> les contrôleurs + chaque classe `App\…` qu'ils importent, en chemins relatifs à src/ */
    private function filesToInspect(): array
    {
        $files = self::CONTROLLERS;
        foreach (self::CONTROLLERS as $controller) {
            $source = (string) file_get_contents($this->src() . '/' . $controller);
            preg_match_all('/^use App\\\\([\w\\\\]+);$/m', $source, $matches);
            foreach ($matches[1] as $class) {
                $relative = str_replace('\\', '/', $class) . '.php';
                self::assertFileExists($this->src() . '/' . $relative, 'import App\\' . $class . ' introuvable sous src/');
                $files[] = $relative;
            }
        }
        $files = array_values(array_unique($files));
        sort($files);

        return $files;
    }

    private function src(): string
    {
        $src = realpath(__DIR__ . '/../../../src');
        self::assertIsString($src);

        return $src;
    }
}
