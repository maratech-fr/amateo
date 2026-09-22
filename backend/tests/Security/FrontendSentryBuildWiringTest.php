<?php

declare(strict_types=1);

namespace App\Tests\Security;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * P5-19 — a frontend's config is frozen at COMPILE time, so a Sentry DSN written
 * in .env.prod at runtime never reaches the bundle. The DSN must travel: declared
 * as a build ARG before the build step (docker/frontend/Dockerfile), and handed
 * to that build by the deploy workflow. This static guard proves the chain still
 * connects — a silent break would leave the app looking instrumented while every
 * event dies. It says NOTHING about the value (empty is the correct default:
 * Sentry stays inert, and switching it on also needs the ingest host in
 * docker/frontend/csp.conf — the founder's move once the account exists).
 */
#[Group('phase1')]
final class FrontendSentryBuildWiringTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';

    public function testDockerfileDeclaresTheDsnBeforeTheBuildStep(): void
    {
        $stage = $this->frontendBuildStage();

        $argPos = strpos($stage, 'ARG VITE_SENTRY_DSN');
        $envPos = strpos($stage, 'ENV VITE_SENTRY_DSN');
        $buildPos = strpos($stage, 'npm run build');

        self::assertIsInt($argPos, 'The frontend build stage must declare `ARG VITE_SENTRY_DSN`.');
        self::assertIsInt($envPos, 'The frontend build stage must promote the DSN with `ENV VITE_SENTRY_DSN`.');
        self::assertIsInt($buildPos, 'The frontend build stage must run `npm run build`.');

        // Vite freezes import.meta.env at build time: a DSN declared AFTER the
        // build step would never reach the bundle.
        self::assertLessThan(
            $buildPos,
            $argPos,
            '`ARG VITE_SENTRY_DSN` must be declared BEFORE `npm run build`, or Vite bakes an empty DSN.',
        );
        self::assertLessThan($buildPos, $envPos, '`ENV VITE_SENTRY_DSN` must be set BEFORE `npm run build`.');
    }

    public function testDeployWorkflowPassesTheDsnToTheFrontendBuild(): void
    {
        $step = $this->deployFrontendBuildStep();

        self::assertStringContainsString(
            'build-args:',
            $step,
            'The deploy workflow frontend build must declare build-args.',
        );
        self::assertStringContainsString(
            'VITE_SENTRY_DSN=',
            $step,
            'The deploy workflow must pass VITE_SENTRY_DSN into the frontend image build.',
        );
    }

    public function testProdComposeForwardsTheDsnToTheFrontendBuild(): void
    {
        // The VM pulls the deploy-built image, but the prod compose must still
        // carry the arg so a local `docker compose -f docker-compose.prod.yml
        // build` rehearses the exact same wiring.
        self::assertStringContainsString(
            'VITE_SENTRY_DSN',
            $this->frontendComposeBuildBlock(),
            'docker-compose.prod.yml frontend build must forward VITE_SENTRY_DSN as a build arg.',
        );
    }

    /** The `FROM tooling AS build` stage of docker/frontend/Dockerfile, up to the next stage. */
    private function frontendBuildStage(): string
    {
        $dockerfile = $this->read('/docker/frontend/Dockerfile');
        $start = strpos($dockerfile, 'FROM tooling AS build');
        self::assertIsInt($start, 'docker/frontend/Dockerfile must have a `FROM tooling AS build` stage.');

        $next = strpos($dockerfile, 'FROM ', $start + 1);

        return false === $next
            ? substr($dockerfile, $start)
            : substr($dockerfile, $start, $next - $start);
    }

    /** The "Build & push scheduler-frontend" step of .github/workflows/deploy.yml, up to the next step. */
    private function deployFrontendBuildStep(): string
    {
        $workflow = $this->read('/.github/workflows/deploy.yml');
        $start = strpos($workflow, 'Build & push scheduler-frontend');
        self::assertIsInt($start, 'deploy.yml must have a "Build & push scheduler-frontend" step.');

        $next = strpos($workflow, '- name:', $start + 1);

        return false === $next
            ? substr($workflow, $start)
            : substr($workflow, $start, $next - $start);
    }

    /** The `build:` block of the `frontend:` service in docker-compose.prod.yml, up to the next service key. */
    private function frontendComposeBuildBlock(): string
    {
        $compose = $this->read('/docker-compose.prod.yml');
        $start = strpos($compose, "\n  frontend:");
        self::assertIsInt($start, 'docker-compose.prod.yml must define a `frontend:` service.');

        // Next top-level service (two-space-indented key) after `frontend:`.
        $next = preg_match('/\n {2}[a-z][\w-]*:/', $compose, $matches, \PREG_OFFSET_CAPTURE, $start + 12);

        return 1 === $next
            ? substr($compose, $start, $matches[0][1] - $start)
            : substr($compose, $start);
    }

    private function read(string $relativePath): string
    {
        $path = self::ROOT . $relativePath;
        $contents = is_file($path) ? file_get_contents($path) : false;
        self::assertIsString($contents, "File not found at {$path}");

        return $contents;
    }
}
