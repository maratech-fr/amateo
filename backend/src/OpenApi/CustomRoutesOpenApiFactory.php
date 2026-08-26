<?php

declare(strict_types=1);

namespace App\OpenApi;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\PathItem;
use ApiPlatform\OpenApi\Model\RequestBody;
use ApiPlatform\OpenApi\Model\Response;
use ApiPlatform\OpenApi\OpenApi;
use App\Enum\AdminJobSource;
use App\Enum\AdminJobStatus;
use ArrayObject;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;

/**
 * Registry for custom Symfony `#[Route]`s that are NOT API Platform operations
 * and would otherwise be absent from the generated OpenAPI (and the
 * `specs/courantes/openapi-snapshot.json`). This decorator injects their paths
 * so `/api/docs` and the snapshot document the full contract; the endpoints
 * themselves are unchanged.
 *
 * ⚠ EVERY custom `#[Route]` must be declared here — a route missing from this
 * factory is invisible to the export even after the snapshot is regenerated.
 *
 * ⚑ **La dette est soldée depuis P4-47** : les 15 routes qui restaient hors contrat
 * (console superadmin, pages publiques à token, proxy FFBB) sont déclarées, et
 * `EveryCustomRouteIsDocumentedTest::KNOWN_UNDOCUMENTED` est VIDE. Ce test confronte la
 * factory au ROUTEUR dans les deux sens — il n'y a donc plus de baseline où se réfugier :
 * **toute route custom ajoutée sans son entrée ici fait rougir la CI**. Le réflexe est
 * désormais « nouvelle `#[Route]` custom = nouvelle entrée dans cette factory », point.
 */
// SEC-16 : priorité NÉGATIVE pour être le décorateur le PLUS EXTERNE — lexik
// décore aussi cette factory (priorité 0) et écrit `/api/login` en dur avec un
// `200 {token}` devenu faux. Le plus externe passe en dernier sur les chemins :
// sans cette priorité, notre correction du contrat était silencieusement écrasée.
#[AsDecorator('api_platform.openapi.factory', priority: -10)]
final readonly class CustomRoutesOpenApiFactory implements OpenApiFactoryInterface
{
    public function __construct(private OpenApiFactoryInterface $decorated) {}

    public function __invoke(array $context = []): OpenApi
    {
        $openApi = ($this->decorated)($context);
        $paths = $openApi->getPaths();

        $paths->addPath('/api/register', new PathItem(post: new Operation(
            operationId: 'postApiRegister',
            tags: ['Auth'],
            responses: [
                // A3: never authenticates and never reveals account existence — an identical
                // 202 for a fresh or an already-registered email. The JWT is issued only by
                // /api/register/verify once the emailed link is followed.
                '202' => $this->jsonResponse('Verification pending — an email was sent (identical for a fresh or an already-registered address)', [
                    'type' => 'object',
                    'properties' => [
                        'status' => ['type' => 'string', 'enum' => ['verification_pending']],
                    ],
                ]),
                '400' => new Response('Validation error'),
                '403' => new Response('Anti-robot verification failed (only when the Turnstile check is enabled)'),
                '429' => new Response('Too many attempts (rate limited)'),
            ],
            summary: 'Register a user (creates an unverified account; sends an email-verification link)',
            requestBody: $this->jsonBody([
                'type' => 'object',
                'required' => ['email', 'password', 'firstName', 'lastName', 'ara', 'consent'],
                'properties' => [
                    'email' => ['type' => 'string', 'format' => 'email'],
                    'password' => ['type' => 'string', 'minLength' => 8],
                    'firstName' => ['type' => 'string'],
                    'lastName' => ['type' => 'string'],
                    'ara' => ['type' => 'string', 'description' => 'FFBB club code — 3-20 uppercase alphanumeric'],
                    'club_name' => ['type' => 'string', 'description' => 'Required only when the ARA creates a new club (snake_case)'],
                    'consent' => ['type' => 'boolean', 'description' => 'RGPD: acceptance of the terms & privacy policy — required (400 without)'],
                    'turnstileToken' => ['type' => 'string', 'description' => 'Cloudflare Turnstile challenge token — required only when the anti-robot check is enabled (see GET /api/register/config); absent or invalid then → 403'],
                ],
            ]),
        )));

        $paths->addPath('/api/register/config', new PathItem(get: new Operation(
            operationId: 'getApiRegisterConfig',
            tags: ['Auth'],
            responses: [
                '200' => $this->jsonResponse('Public registration configuration', [
                    'type' => 'object',
                    'properties' => [
                        // Null while the anti-robot check is disabled — the frontend only
                        // renders the Turnstile widget when a sitekey is served (the key is
                        // public by nature, the matching secret never leaves the backend).
                        'turnstileSiteKey' => ['type' => 'string', 'nullable' => true],
                    ],
                ]),
            ],
            summary: 'Public registration configuration (Turnstile sitekey when the anti-robot check is enabled)',
        )));

        $paths->addPath('/api/register/verify', new PathItem(post: new Operation(
            operationId: 'postApiRegisterVerify',
            tags: ['Auth'],
            responses: [
                '200' => $this->jsonResponse('Verified — materialises the club and logs in (the JWT is set as the httpOnly BEARER cookie, NOT returned here)', [
                    'type' => 'object',
                    'properties' => [
                        'membershipStatus' => ['type' => 'string', 'enum' => ['none', 'pending', 'active']],
                        'user' => ['type' => 'object', 'properties' => [
                            'id' => ['type' => 'string'],
                            'email' => ['type' => 'string'],
                        ]],
                    ],
                ]),
                '400' => new Response('Invalid or expired verification token'),
                '429' => new Response('Too many attempts (rate limited)'),
            ],
            summary: 'Consume an email-verification token: verify the account, create/join its club, and log in',
            requestBody: $this->jsonBody([
                'type' => 'object',
                'required' => ['token'],
                'properties' => [
                    'token' => ['type' => 'string', 'description' => 'The raw token from the verification email link'],
                ],
            ]),
        )));

        $paths->addPath('/api/me', new PathItem(
            get: new Operation(
                operationId: 'getApiMe',
                tags: ['Auth'],
                responses: [
                    '200' => new Response('The authenticated user + its club context', new ArrayObject([
                        'application/json' => ['schema' => ['type' => 'object', 'properties' => [
                            'id' => ['type' => 'string'],
                            'email' => ['type' => 'string'],
                            // P4-74 — l'adresse en attente de confirmation (null = aucune).
                            'pendingEmail' => ['type' => ['string', 'null']],
                            'firstName' => ['type' => 'string'],
                            'lastName' => ['type' => 'string'],
                            'membershipStatus' => ['type' => 'string', 'enum' => ['none', 'pending', 'active']],
                            'role' => ['type' => 'string', 'nullable' => true],
                            'club' => ['type' => 'object', 'nullable' => true, 'properties' => [
                                'id' => ['type' => 'string'],
                                'name' => ['type' => 'string'],
                                'onboardingCompleted' => ['type' => 'boolean'],
                                'logoUrl' => ['type' => 'string', 'nullable' => true],
                                'accentColor' => ['type' => 'string', 'nullable' => true],
                                'accentColorDark' => ['type' => 'string', 'nullable' => true],
                                'accentPalette' => ['type' => 'array', 'nullable' => true, 'items' => ['type' => 'string']],
                            ]],
                            // ADR-0002 : le plan de saison — le calendrier de base. null si
                            // la saison n'en a pas encore. `chosenScheduleId` = la version
                            // choisie (« validée ») ; `hasFinishedVersion` = le plan porte au
                            // moins une version terminée ; `currentStructureHash` = le hash
                            // du payload solver courant, pour griser « Régénérer » quand
                            // la structure sélectionnée est déjà à l'identique.
                            // Le document est en OpenAPI 3.1 : `nullable` y a disparu, un
                            // consommateur l'ignore. Il faut l'union JSON-Schema, sinon le
                            // contrat promet du non-null là où null est l'état NORMAL
                            // (saison sans plan ; aucune version choisie = espace de travail)
                            // et un client généré déréférence null.
                            'seasonPlan' => ['type' => ['object', 'null'], 'properties' => [
                                'id' => ['type' => 'string'],
                                'name' => ['type' => 'string'],
                                'chosenScheduleId' => ['type' => ['string', 'null']],
                                'hasFinishedVersion' => ['type' => 'boolean'],
                                'currentStructureHash' => ['type' => ['string', 'null']],
                            ]],
                            'hasGenerated' => ['type' => 'boolean'],
                        ]]],
                    ])),
                    '401' => new Response('Unauthorized'),
                ],
                summary: 'Hydrate the authenticated user and its active club context',
            ),
            delete: new Operation(
                operationId: 'deleteApiMe',
                tags: ['Auth'],
                responses: [
                    '200' => $this->jsonResponse('Account anonymized (RGPD erasure)', [
                        'type' => 'object',
                        'properties' => [
                            'message' => ['type' => 'string'],
                            'clubPurgeScheduled' => ['type' => 'boolean'],
                            'gracePeriodDays' => ['type' => 'integer'],
                        ],
                    ]),
                    '400' => new Response('Wrong password'),
                    '401' => new Response('Unauthorized'),
                ],
                summary: 'RGPD erasure: anonymize the connected account (re-authentication: current password required); if no active member remains, schedule the club workspace purge (30-day grace, auto-cancelled if a member returns)',
                requestBody: $this->jsonBody([
                    'type' => 'object',
                    'required' => ['password'],
                    'properties' => [
                        'password' => ['type' => 'string'],
                    ],
                ]),
            ),
            patch: new Operation(
                operationId: 'patchApiMe',
                tags: ['Auth'],
                responses: [
                    '200' => $this->jsonResponse('Updated profile', [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'string'],
                            'email' => ['type' => 'string'],
                            'pendingEmail' => ['type' => ['string', 'null']],
                            'firstName' => ['type' => 'string'],
                            'lastName' => ['type' => 'string'],
                        ],
                    ]),
                    '400' => new Response('Validation error (empty name)'),
                    // P4-74 — le PATCH ne change plus l'e-mail : un e-mail DIFFÉRENT
                    // de l'actuel est refusé et pointe POST /api/me/email.
                    '422' => new Response('E-mail changes go through the confirmation flow (POST /api/me/email)'),
                ],
                summary: 'Update the connected user profile (name only — e-mail changes go through POST /api/me/email)',
                requestBody: $this->jsonBody([
                    'type' => 'object',
                    'properties' => [
                        'firstName' => ['type' => 'string'],
                        'lastName' => ['type' => 'string'],
                    ],
                ]),
            ),
        ));

        // P4-74 — changer d'e-mail : confirmer d'abord (lien envoyé à la nouvelle
        // adresse), basculer ensuite. L'adresse courante reste active tant qu'on
        // n'a pas confirmé.
        $paths->addPath('/api/me/email', new PathItem(
            post: new Operation(
                operationId: 'postApiMeEmail',
                tags: ['Auth'],
                responses: [
                    '200' => $this->jsonResponse('Confirmation link sent to the new address; the current address stays active until confirmed', [
                        'type' => 'object',
                        'properties' => [
                            'status' => ['type' => 'string', 'enum' => ['confirmation_sent']],
                            'pendingEmail' => ['type' => 'string'],
                        ],
                    ]),
                    '400' => new Response('Invalid e-mail, or same as the current address'),
                    '401' => new Response('Unauthorized'),
                    '409' => new Response('E-mail already used (active or pending) by another account'),
                    '429' => new Response('Too many requests (per-user rate limit)'),
                ],
                summary: 'Request an e-mail change: store it as pending and send a confirmation link to the NEW address',
                requestBody: $this->jsonBody([
                    'type' => 'object',
                    'required' => ['email'],
                    'properties' => ['email' => ['type' => 'string', 'format' => 'email']],
                ]),
            ),
            delete: new Operation(
                operationId: 'deleteApiMeEmail',
                tags: ['Auth'],
                responses: [
                    '200' => $this->jsonResponse('Pending e-mail change cancelled', [
                        'type' => 'object',
                        'properties' => ['status' => ['type' => 'string', 'enum' => ['cancelled']]],
                    ]),
                    '401' => new Response('Unauthorized'),
                ],
                summary: 'Cancel a pending e-mail change (clears pendingEmail and its tokens)',
            ),
        ));

        $paths->addPath('/api/me/email/confirm', new PathItem(post: new Operation(
            operationId: 'postApiMeEmailConfirm',
            tags: ['Auth'],
            responses: [
                '200' => $this->jsonResponse('E-mail switched; a fresh httpOnly JWT cookie is set for the new identity (the account stays verified/connectable)', [
                    'type' => 'object',
                    'properties' => [
                        'status' => ['type' => 'string', 'enum' => ['email_confirmed']],
                        'email' => ['type' => 'string'],
                    ],
                ]),
                '400' => new Response('Invalid/expired token, or no pending change'),
                '409' => new Response('The pending address was taken by another account in the meantime'),
                '429' => new Response('Too many attempts (per-IP rate limit)'),
            ],
            summary: 'Confirm the pending e-mail change (PUBLIC_ACCESS — the token is the identity)',
            requestBody: $this->jsonBody([
                'type' => 'object',
                'required' => ['token'],
                'properties' => ['token' => ['type' => 'string', 'description' => 'The raw token from the confirmation email link']],
            ]),
        )));

        // SEC-16 (audit) — RÉÉCRITURE du path que le décorateur de lexik écrit en
        // dur (`OpenApi/OpenApiFactory.php` : 200 + `{token}`). Depuis que le
        // jeton part en cookie httpOnly, ce contrat MENT : la réponse est un 204
        // sans corps. Un contrat publié faux se propage au front et aux clients.
        $paths->addPath('/api/login', new PathItem(post: new Operation(
            operationId: 'login_check_post',
            tags: ['Login Check'],
            responses: [
                '204' => new Response('Authenticated: the JWT is set as the httpOnly `BEARER` cookie (path /api, SameSite=Strict) — the response has NO body'),
                '401' => new Response('Bad credentials (identical answer for an unverified account — A3 anti-enumeration)'),
            ],
            summary: 'Log in: sets the httpOnly JWT cookie (the token is never returned in the body)',
            requestBody: $this->jsonBody([
                'type' => 'object',
                'required' => ['email', 'password'],
                'properties' => [
                    'email' => ['type' => 'string', 'format' => 'email'],
                    'password' => ['type' => 'string'],
                ],
            ]),
        )));

        $paths->addPath('/api/logout', new PathItem(post: new Operation(
            operationId: 'postApiLogout',
            tags: ['Auth'],
            responses: [
                '200' => $this->jsonResponse('Session ended: the auth cookie is cleared server-side', [
                    'type' => 'object',
                    'properties' => ['status' => ['type' => 'string', 'enum' => ['logged_out']]],
                ]),
            ],
            summary: 'Clear the httpOnly JWT cookie (public and idempotent — the JS cannot clear what it cannot read)',
        )));

        $paths->addPath('/api/mercure/auth', new PathItem(get: new Operation(
            operationId: 'getApiMercureAuth',
            tags: ['Auth'],
            responses: [
                '200' => $this->jsonResponse('Mercure subscriber JWT set as an httpOnly cookie (path /.well-known/mercure); the body exposes the topic template to subscribe to', [
                    'type' => 'object',
                    'properties' => [
                        'expiresIn' => ['type' => 'integer', 'description' => 'Cookie/JWT TTL in seconds'],
                        'topicTemplate' => ['type' => 'string', 'description' => 'URI template club:{clubId}:schedule:{id} — subscribe to it as-is'],
                    ],
                ]),
                '400' => new Response('No club resolved for the authenticated user'),
                '401' => new Response('Unauthorized'),
                '503' => new Response('Mercure secret not configured'),
            ],
            summary: 'FRT-04: mint the hub subscriber JWT (scoped to the member own club generation topics), delivered as the mercureAuthorization cookie',
        )));

        $paths->addPath('/api/me/export', new PathItem(get: new Operation(
            operationId: 'getApiMeExport',
            tags: ['Auth'],
            responses: [
                '200' => new Response('RGPD portability export of the connected account (user + memberships), served as a JSON download'),
                '401' => new Response('Unauthorized'),
            ],
            summary: 'RGPD portability: export the connected user own account data (self-only)',
        )));

        $paths->addPath('/api/club/export', new PathItem(get: new Operation(
            operationId: 'getApiClubExport',
            tags: ['Club'],
            responses: [
                '200' => new Response('RGPD portability export of the current club workspace (raw rows per table), served as a JSON download'),
                '401' => new Response('Unauthorized'),
                '403' => new Response('Member but not a management role'),
                '404' => new Response('No active membership in the current club'),
            ],
            summary: 'RGPD portability: export the current club full workspace (management only, tenant from JWT)',
        )));

        $paths->addPath('/api/me/password', new PathItem(post: new Operation(
            operationId: 'postApiMePassword',
            tags: ['Auth'],
            responses: [
                '200' => new Response('Password changed'),
                '400' => new Response('Wrong current password or new password too short'),
            ],
            summary: 'Change the connected user password (current password required)',
            requestBody: $this->jsonBody([
                'type' => 'object',
                'required' => ['currentPassword', 'newPassword'],
                'properties' => [
                    'currentPassword' => ['type' => 'string'],
                    'newPassword' => ['type' => 'string', 'minLength' => 8],
                ],
            ]),
        )));

        foreach ($this->uncoveredCustomPaths() as $path => $pathItem) {
            $paths->addPath($path, $pathItem);
        }

        foreach ($this->manualEditPaths() as $path => $pathItem) {
            $paths->addPath($path, $pathItem);
        }

        foreach ($this->holidayPaths() as $path => $pathItem) {
            $paths->addPath($path, $pathItem);
        }

        foreach ($this->adminAuthPaths() as $path => $pathItem) {
            $paths->addPath($path, $pathItem);
        }

        foreach ($this->adminMonitoringPaths() as $path => $pathItem) {
            $paths->addPath($path, $pathItem);
        }

        foreach ($this->adminJobPaths() as $path => $pathItem) {
            $paths->addPath($path, $pathItem);
        }

        foreach ($this->adminSupportPaths() as $path => $pathItem) {
            $paths->addPath($path, $pathItem);
        }

        foreach ($this->adminJournalPaths() as $path => $pathItem) {
            $paths->addPath($path, $pathItem);
        }

        foreach ($this->publicTokenPaths() as $path => $pathItem) {
            $paths->addPath($path, $pathItem);
        }

        foreach ($this->ffbbPaths() as $path => $pathItem) {
            $paths->addPath($path, $pathItem);
        }

        foreach ($this->releaseNotePaths() as $path => $pathItem) {
            $paths->addPath($path, $pathItem);
        }

        foreach ($this->feedbackPaths() as $path => $pathItem) {
            $paths->addPath($path, $pathItem);
        }

        foreach ($this->adminReleaseNotePaths() as $path => $pathItem) {
            $paths->addPath($path, $pathItem);
        }

        foreach ($this->adminFeedbackPaths() as $path => $pathItem) {
            $paths->addPath($path, $pathItem);
        }

        $paths->addPath('/api/seasons/{id}/transition', new PathItem(post: new Operation(
            operationId: 'transitionSeason',
            tags: ['Season'],
            responses: [
                '201' => $this->jsonResponse('N+1 draft season created from the source season entries (never the generated plan)', [
                    'type' => 'object',
                    'properties' => [
                        'seasonId' => ['type' => 'string'],
                        'name' => ['type' => 'string'],
                        'startDate' => ['type' => 'string', 'format' => 'date'],
                        'endDate' => ['type' => 'string', 'format' => 'date'],
                        'counts' => ['type' => 'object', 'additionalProperties' => ['type' => 'integer']],
                    ],
                ]),
                '400' => new Response('No club in context'),
                '403' => new Response('Management role required'),
                '404' => new Response('Season not found (or another club\'s)'),
                '409' => new Response('Source is not the current season, or a next season already exists (body carries existingSeasonId)'),
            ],
            summary: 'Copy the current season entries (venues/teams/coaches/links/permanent constraints) into a fresh N+1 draft',
            parameters: [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string'], 'description' => 'Source season id (must be the current season)']],
        )));

        $paths->addPath('/api/league-match-windows', new PathItem(get: new Operation(
            operationId: 'getLeagueMatchWindows',
            tags: ['Match'],
            responses: [
                '200' => $this->jsonResponse('Federation match-kickoff windows inherited by the club (league envelope, AURA default)', [
                    'type' => 'object',
                    'properties' => [
                        'league' => ['type' => 'string'],
                        'items' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                            'id' => ['type' => 'string'],
                            'league' => ['type' => 'string'],
                            'category' => ['type' => 'string'],
                            'level' => ['type' => 'string'],
                            'gender' => ['type' => 'string', 'nullable' => true],
                            'dayOfWeek' => ['type' => 'integer'],
                            'kickoffMin' => ['type' => 'string'],
                            'kickoffMax' => ['type' => 'string'],
                        ]]],
                    ],
                ]),
                '400' => new Response('No club in context'),
                '401' => new Response('Unauthorized (missing/expired JWT)'),
            ],
            summary: 'League match-kickoff windows inherited by the club (global reference, read-only)',
        )));

        $paths->addPath('/api/fbi-ingestions/latest', new PathItem(get: new Operation(
            operationId: 'getLatestFbiIngestion',
            tags: ['Match'],
            responses: [
                '200' => $this->jsonResponse('The last FBI export deposit of the club/season (freshness: « last deposit N days ago ») — null when none yet', [
                    'type' => 'object',
                    'properties' => [
                        'latest' => ['type' => 'object', 'nullable' => true, 'properties' => [
                            'depositedAt' => ['type' => 'string', 'format' => 'date-time'],
                            'source' => ['type' => 'string', 'enum' => ['FBI_XLSX', 'FFBB_API']],
                            'created' => ['type' => 'integer'],
                            'updated' => ['type' => 'integer'],
                            'unchanged' => ['type' => 'integer'],
                            'deviationsCount' => ['type' => 'integer'],
                        ]],
                    ],
                ]),
                '400' => new Response('No club or season in context'),
                '401' => new Response('Unauthorized (missing/expired JWT)'),
            ],
            summary: 'Last FBI export deposit of the club/season (freshness feed, read-only, open to any member)',
        )));

        $paths->addPath('/api/fixtures/conflicts', new PathItem(get: new Operation(
            operationId: 'getFixtureConflicts',
            tags: ['Match'],
            responses: [
                '200' => $this->jsonResponse('Same-coach time-occupancy conflicts (match↔match and match↔training) recomputed live for the current club/season', [
                    'type' => 'object',
                    'properties' => [
                        'clubId' => ['type' => 'string'],
                        'seasonId' => ['type' => 'string', 'nullable' => true],
                        'conflicts' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                            'type' => ['type' => 'string', 'enum' => ['MATCH_MATCH', 'MATCH_TRAINING', 'VENUE_UNAVAILABLE', 'TEAM_LINK_OVERLAP']],
                            'coachId' => ['type' => 'string'],
                            'start' => ['type' => 'string', 'format' => 'date-time', 'description' => 'Overlap segment start'],
                            'end' => ['type' => 'string', 'format' => 'date-time', 'description' => 'Overlap segment end'],
                            'left' => ['type' => 'object', 'nullable' => true, 'description' => 'MATCH_MATCH: first fixture'],
                            'right' => ['type' => 'object', 'nullable' => true, 'description' => 'MATCH_MATCH: second fixture'],
                            'fixture' => ['type' => 'object', 'nullable' => true, 'description' => 'MATCH_TRAINING: the match'],
                            'training' => ['type' => 'object', 'nullable' => true, 'description' => 'MATCH_TRAINING: the training slot'],
                            'fingerprint' => ['type' => 'string', 'description' => 'Stable identity of the conflict — same while it is the same dispute, changes when its nature changes (the guardian compares it across visits)'],
                        ]]],
                    ],
                ]),
                '400' => new Response('No club in context'),
                '401' => new Response('Unauthorized (missing/expired JWT)'),
            ],
            summary: 'Same-coach match/training conflict radar (read-only, computed on the fly)',
        )));

        $paths->addPath('/api/matches/module-visit', new PathItem(post: new Operation(
            operationId: 'stampMatchModuleVisit',
            tags: ['Match'],
            responses: [
                '200' => $this->jsonResponse('What changed in the match module since this user\'s previous visit — new fixtures arrived, new conflicts, and whether the season plan moved. Stamps the visit as a side effect (first visit stays silent).', [
                    'type' => 'object',
                    'properties' => [
                        'firstVisit' => ['type' => 'boolean', 'description' => 'True on the very first visit: the reference is set silently, every count is zero'],
                        'newFixturesCount' => ['type' => 'integer', 'description' => 'Fixtures created since the reference was taken'],
                        'newConflictFingerprints' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Conflicts present now and absent from the reference (vanished ones are not reported)'],
                        'planningChanged' => ['type' => 'boolean', 'description' => 'The chosen season version or the latest completed one differs from the reference'],
                        'referenceTakenAt' => ['type' => 'string', 'format' => 'date-time', 'description' => 'The moment the badges are measured against'],
                    ],
                ]),
                '400' => new Response('No club or no season in context'),
                '401' => new Response('Unauthorized (missing/expired JWT)'),
            ],
            summary: 'Stamp the match-module visit and return what changed since the previous one (per user; open to any member)',
        )));

        $paths->addPath('/api/competitions/entry-deadlines', new PathItem(post: new Operation(
            operationId: 'setCompetitionEntryDeadlines',
            tags: ['Match'],
            responses: [
                '200' => $this->jsonResponse('The competitions whose league/committee entry deadline was set (or cleared). When a paired competition receives a non-null deadline, it also becomes the overridable community default for that federation competition (last write wins).', [
                    'type' => 'object',
                    'properties' => [
                        'updated' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Ids of the competitions written'],
                        'deadline' => ['type' => 'string', 'format' => 'date', 'nullable' => true, 'description' => 'The deadline applied (null = cleared)'],
                    ],
                ]),
                '403' => new Response('Not a management member'),
                '409' => new Response('The selected season is archived (read-only)'),
                '422' => new Response('No competitions, malformed deadline, or an unknown/foreign competition id (nothing is written)'),
            ],
            summary: 'Set (or clear) the entry deadline on a set of competitions — management only',
            requestBody: $this->jsonBody([
                'type' => 'object',
                'required' => ['competitionIds', 'deadline'],
                'properties' => [
                    'competitionIds' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'The competitions to stamp with the same deadline'],
                    'deadline' => ['type' => 'string', 'format' => 'date', 'nullable' => true, 'description' => 'The league/committee entry deadline (AAAA-MM-JJ), or null to clear the club value'],
                ],
            ]),
        )));

        $paths->addPath('/api/matches/deadline-outlook', new PathItem(get: new Operation(
            operationId: 'getMatchDeadlineOutlook',
            tags: ['Match'],
            responses: [
                '200' => $this->jsonResponse('The entry-deadline cockpit outlook: each still-owed effective deadline (club value, else community default) with its competitions, how many home fixtures remain to enter, and whether the seven-day reminder window is open. When at least one window is open, the current user\'s guardian delta is joined (read-only, the visit is not stamped).', [
                    'type' => 'object',
                    'properties' => [
                        'windows' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                            'deadline' => ['type' => 'string', 'format' => 'date'],
                            'source' => ['type' => 'string', 'enum' => ['club', 'community'], 'description' => 'Where the effective deadline came from'],
                            'competitionNames' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'toEnterCount' => ['type' => 'integer', 'description' => 'Home fixtures not yet entered in FBI (UNPLACED included)'],
                            'withinWindow' => ['type' => 'boolean', 'description' => 'True within seven days of the deadline (overdue included)'],
                        ]]],
                        'guardianDelta' => ['type' => 'object', 'nullable' => true, 'description' => 'Present only when a reminder window is open AND the user already has a visit reference', 'properties' => [
                            'newFixturesCount' => ['type' => 'integer'],
                            'newConflictFingerprints' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'planningChanged' => ['type' => 'boolean'],
                        ]],
                    ],
                ]),
                '400' => new Response('No club in context'),
                '401' => new Response('Unauthorized (missing/expired JWT)'),
            ],
            summary: 'League/committee entry-deadline outlook for the cockpit (read-only, open to any member)',
        )));

        $paths->addPath('/api/venue-unavailability-impact', new PathItem(get: new Operation(
            operationId: 'getVenueUnavailabilityImpact',
            tags: ['Match'],
            responses: [
                '200' => $this->jsonResponse('Per-unavailability impact: affected placed matches + training sessions of the effective schedules (cockpit alert feed)', [
                    'type' => 'object',
                    'properties' => [
                        'clubId' => ['type' => 'string'],
                        'seasonId' => ['type' => 'string', 'nullable' => true],
                        'items' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                            'unavailabilityId' => ['type' => 'string'],
                            'venueId' => ['type' => 'string'],
                            'startDate' => ['type' => 'string', 'format' => 'date'],
                            'endDate' => ['type' => 'string', 'format' => 'date'],
                            'label' => ['type' => 'string', 'nullable' => true],
                            'affectedFixtures' => ['type' => 'array', 'items' => ['type' => 'object']],
                            'trainingOccurrences' => ['type' => 'integer', 'description' => 'Dated training sessions inside the range'],
                            'trainingSlotCount' => ['type' => 'integer', 'description' => 'Distinct weekly slots affected'],
                        ]]],
                    ],
                ]),
                '400' => new Response('No club in context'),
                '401' => new Response('Unauthorized (missing/expired JWT)'),
            ],
            summary: 'Venue unavailability impact (alert-only, computed on the fly — blocks nothing)',
        )));

        $paths->addPath('/api/fixtures/place', new PathItem(post: new Operation(
            operationId: 'placeMatches',
            tags: ['Match'],
            responses: [
                '200' => $this->jsonResponse('Synchronous match placement: the solver places every placeable UNPLACED home match; the rest comes back named', [
                    'type' => 'object',
                    'properties' => [
                        'placed' => ['type' => 'integer'],
                        'skipped' => ['type' => 'integer', 'description' => 'Placements refused at write time (a manual gesture won during the solve)'],
                        'unplaced' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                            'matchId' => ['type' => 'string'],
                            'reason' => ['type' => 'string', 'enum' => ['no_access_window', 'no_league_intersection', 'venue_unavailable', 'venue_full']],
                            'message' => ['type' => 'string'],
                        ]]],
                        'diagnostics' => ['type' => 'array', 'items' => ['type' => 'object']],
                        'metrics' => ['type' => 'object', 'nullable' => true],
                    ],
                ]),
                '400' => new Response('No club in context'),
                '401' => new Response('Unauthorized (missing/expired JWT)'),
                '403' => new Response('Not a management member'),
                '409' => new Response('Placement already running, season plan not chosen, or archived season'),
                '502' => new Response('Engine unreachable — retry, nothing was written'),
            ],
            summary: 'Auto-place the unplaced home matches (writes PLACED+SOLVER; manual anchors never move)',
        )));

        $paths->addPath('/api/ffbb/engagements', new PathItem(get: new Operation(
            operationId: 'listFfbbEngagements',
            tags: ['Match'],
            responses: [
                '200' => $this->jsonResponse('The club\'s FFBB engagements of the current season — on-demand, never cached; each row carries a pre-fill suggestion', [
                    'type' => 'object',
                    'properties' => [
                        'engagements' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                            'ffbbCompetitionId' => ['type' => 'string'],
                            'ffbbCompetitionCode' => ['type' => 'string'],
                            'competitionName' => ['type' => 'string'],
                            'ffbbPouleId' => ['type' => 'string'],
                            'pouleName' => ['type' => 'string'],
                            'category' => ['type' => 'string', 'nullable' => true],
                            'level' => ['type' => 'string', 'nullable' => true],
                            'gender' => ['type' => 'string', 'nullable' => true],
                            'pouleSize' => ['type' => 'integer'],
                            'pouleOpponents' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'suggestedTeamId' => ['type' => 'string', 'nullable' => true],
                            'suggestedCompetitionId' => ['type' => 'string', 'nullable' => true],
                        ]]],
                    ],
                ]),
                '400' => new Response('No club/season in context'),
                '401' => new Response('Unauthorized (missing/expired JWT)'),
                '403' => new Response('Not a management member'),
                '422' => new Response('The club has no FFBB code'),
                '502' => new Response('FFBB unreachable — retry later'),
            ],
            summary: 'List the club\'s FFBB engagements to pair (league data — corrections happen with the league)',
        )));

        $paths->addPath('/api/ffbb/engagements/confirm', new PathItem(post: new Operation(
            operationId: 'confirmFfbbPairings',
            tags: ['Match'],
            responses: [
                '200' => $this->jsonResponse('Pairings written on each team\'s Competition (refs + frozen expectedMatchdays + poule opponents, all from a server-side re-read)', [
                    'type' => 'object',
                    'properties' => [
                        'confirmed' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                            'competitionId' => ['type' => 'string'],
                            'teamId' => ['type' => 'string'],
                            'ffbbCompetitionId' => ['type' => 'string'],
                        ]]],
                    ],
                ]),
                '400' => new Response('No club/season in context'),
                '401' => new Response('Unauthorized (missing/expired JWT)'),
                '403' => new Response('Not a management member'),
                '409' => new Response('Season plan not chosen, or archived season'),
                '422' => new Response('Unknown engagement for this season, foreign/unknown team, or malformed pairing — nothing written'),
                '502' => new Response('FFBB unreachable — retry later'),
            ],
            summary: 'Confirm the FFBB pairings in block (re-paired at each phase — 1 click)',
        )));

        $paths->addPath('/api/ffbb/rencontres', new PathItem(get: new Operation(
            operationId: 'listFfbbRencontres',
            tags: ['Match'],
            responses: [
                '200' => $this->jsonResponse('The club\'s FFBB-published rencontres crossed with the app — on demand, never cached; the diff (deviations) plus the rencontres absent of the app (creatable, the amicaux), proposed for creation', [
                    'type' => 'object',
                    'properties' => [
                        'deviations' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                            'fixtureId' => ['type' => 'string'],
                            'externalRef' => ['type' => 'string'],
                            'division' => ['type' => 'string'],
                            'teamId' => ['type' => 'string'],
                            'status' => ['type' => 'string'],
                            'persisting' => ['type' => 'boolean'],
                            'fields' => ['type' => 'object', 'additionalProperties' => ['type' => 'object', 'properties' => [
                                'app' => ['type' => 'string', 'nullable' => true],
                                'file' => ['type' => 'string', 'nullable' => true],
                            ]]],
                        ]]],
                        'creatable' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                            'rencontreId' => ['type' => 'string'],
                            'competitionNom' => ['type' => 'string'],
                            'date' => ['type' => 'string'],
                            'kickoff' => ['type' => 'string', 'nullable' => true],
                            'homeAway' => ['type' => 'string'],
                            'opponentLabel' => ['type' => 'string'],
                            'venueLabel' => ['type' => 'string', 'nullable' => true],
                            'numeroJournee' => ['type' => 'string', 'nullable' => true],
                            'suggestedTeamId' => ['type' => 'string', 'nullable' => true],
                        ]]],
                        'fetchedAt' => ['type' => 'string', 'format' => 'date-time'],
                    ],
                ]),
                '400' => new Response('No club/season in context'),
                '401' => new Response('Unauthorized (missing/expired JWT)'),
                '403' => new Response('Not a management member'),
                '409' => new Response('Season plan not chosen'),
                '422' => new Response('The club has no FFBB code'),
                '502' => new Response('FFBB unreachable — retry later'),
            ],
            summary: 'Cross the club\'s FFBB-published rencontres with the app (FBI stays the truth — the API is a convenience)',
        )));

        $paths->addPath('/api/ffbb/rencontres/apply', new PathItem(post: new Operation(
            operationId: 'applyFfbbRencontres',
            tags: ['Match'],
            responses: [
                '200' => $this->jsonResponse('Decisions applied (SAME engine as the xlsx import) and the chosen rencontres created (idempotent); values come from a server re-fetch, never the client', [
                    'type' => 'object',
                    'properties' => [
                        'created' => ['type' => 'integer'],
                        'updated' => ['type' => 'integer'],
                        'unresolvedDeviations' => ['type' => 'array', 'items' => ['type' => 'object']],
                        'depositedAt' => ['type' => 'string', 'format' => 'date-time'],
                    ],
                ]),
                '400' => new Response('No club/season in context, or malformed decisions/creations'),
                '401' => new Response('Unauthorized (missing/expired JWT)'),
                '403' => new Response('Not a management member'),
                '409' => new Response('Season plan not chosen, archived season, or a concurrent create collided'),
                '422' => new Response('The club has no FFBB code'),
                '502' => new Response('FFBB unreachable — retry later'),
            ],
            summary: 'Apply the per-écart decisions and create the chosen rencontres (server re-fetch, never client values)',
            requestBody: $this->jsonBody([
                'type' => 'object',
                'properties' => [
                    'decisions' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                        'fixtureId' => ['type' => 'string'],
                        'field' => ['type' => 'string', 'enum' => ['date', 'kickoff', 'venue']],
                        'choice' => ['type' => 'string', 'enum' => ['keep_app', 'take_file']],
                    ]]],
                    'creations' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                        'rencontreId' => ['type' => 'string'],
                        'teamId' => ['type' => 'string'],
                    ]]],
                ],
            ]),
        )));

        $paths->addPath('/api/geocode', new PathItem(get: new Operation(
            operationId: 'geocodeAddress',
            tags: ['Venue'],
            responses: [
                '200' => $this->jsonResponse('BAN geocoding candidates for a free-text address (top 5) — used to set a venue\'s latitude/longitude', [
                    'type' => 'object',
                    'properties' => [
                        'candidates' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                            'label' => ['type' => 'string'],
                            'latitude' => ['type' => 'number', 'format' => 'float'],
                            'longitude' => ['type' => 'number', 'format' => 'float'],
                            'score' => ['type' => 'number', 'format' => 'float'],
                        ]]],
                    ],
                ]),
                '401' => new Response('Unauthorized (missing/expired JWT)'),
                '403' => new Response('Not a management member'),
                '422' => new Response('Missing or malformed query (3 to 200 characters)'),
                '502' => new Response('Geocoding service unreachable — retry later'),
            ],
            summary: 'Geocode a free-text address via the Base Adresse Nationale (management only, tenant from JWT)',
            parameters: [['name' => 'q', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string'], 'description' => 'Free-text address to geocode (3 to 200 characters)']],
        )));

        $paths->addPath('/api/venue-travel-times/autofill', new PathItem(post: new Operation(
            operationId: 'autofillVenueTravelTimes',
            tags: ['Venue'],
            responses: [
                '200' => $this->jsonResponse('Fills AUTO driving/walking minutes for every geolocated venue pair via IGN routing. A MANUAL value is NEVER overwritten; a pair with a missing geolocation or a routing failure comes back named (best-effort).', [
                    'type' => 'object',
                    'properties' => [
                        'filled' => ['type' => 'integer', 'description' => 'Pairs where at least one AUTO minute was written'],
                        'unresolved' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                            'venueAId' => ['type' => 'string'],
                            'venueBId' => ['type' => 'string'],
                            'reason' => ['type' => 'string', 'enum' => ['missing_geo', 'routing_failed']],
                        ]]],
                        'skippedManual' => ['type' => 'integer', 'description' => 'Pairs whose MANUAL value was preserved'],
                    ],
                ]),
                '400' => new Response('No club or season in context'),
                '401' => new Response('Unauthorized (missing/expired JWT)'),
                '403' => new Response('Not a management member'),
                '409' => new Response('The selected season is archived (read-only)'),
                '422' => new Response('Too many geolocated venue pairs for an automatic fill (fill by hand)'),
                '429' => new Response('Too many requests (per-user rate limit)'),
            ],
            summary: 'Autofill the venue travel-time matrix from IGN routing (management only; never overwrites a MANUAL value)',
        )));

        return $openApi;
    }

    /**
     * @return array<string, PathItem>
     */
    private function adminAuthPaths(): array
    {
        $credentialsBody = $this->jsonBody([
            'type' => 'object',
            'required' => ['email', 'password'],
            'properties' => [
                'email' => ['type' => 'string', 'format' => 'email'],
                'password' => ['type' => 'string'],
            ],
        ]);

        return [
            '/api/admin/auth/password' => new PathItem(post: new Operation(
                operationId: 'postAdminAuthPassword',
                tags: ['AdminAuth'],
                responses: [
                    '200' => $this->jsonResponse('Password accepted; TOTP challenge required within five minutes', [
                        'type' => 'object',
                        'properties' => ['mfaRequired' => ['type' => 'boolean', 'enum' => [true]]],
                    ]),
                    '401' => new Response('Invalid credentials'),
                    '429' => new Response('Too many attempts (per-IP rate limit)'),
                ],
                summary: 'Start the separate super-admin authentication flow',
                requestBody: $credentialsBody,
            )),
            '/api/admin/auth/totp' => new PathItem(post: new Operation(
                operationId: 'postAdminAuthTotp',
                tags: ['AdminAuth'],
                responses: [
                    '200' => $this->jsonResponse('MFA accepted; stateful admin session created', [
                        'type' => 'object',
                        'properties' => [
                            'authenticated' => ['type' => 'boolean', 'enum' => [true]],
                            'csrfToken' => ['type' => 'string'],
                        ],
                    ]),
                    '401' => new Response('Invalid or expired authentication challenge'),
                    '429' => new Response('Too many attempts (per-IP rate limit)'),
                ],
                summary: 'Complete super-admin authentication with the mandatory TOTP code',
                requestBody: $this->jsonBody([
                    'type' => 'object',
                    'required' => ['code'],
                    'properties' => ['code' => ['type' => 'string', 'pattern' => '^\\d{6}$']],
                ]),
            )),
            '/api/admin/auth/me' => new PathItem(get: new Operation(
                operationId: 'getAdminAuthMe',
                tags: ['AdminAuth'],
                responses: [
                    '200' => $this->jsonResponse('Authenticated super-admin identity', [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'string', 'format' => 'uuid'],
                            'email' => ['type' => 'string', 'format' => 'email'],
                            'csrfToken' => ['type' => 'string'],
                        ],
                    ]),
                    '401' => new Response('No authenticated super-admin session'),
                ],
                summary: 'Hydrate the separate super-admin session',
            )),
            '/api/admin/auth/logout' => new PathItem(post: new Operation(
                operationId: 'postAdminAuthLogout',
                tags: ['AdminAuth'],
                responses: [
                    '204' => new Response('Super-admin session invalidated'),
                    '401' => new Response('No authenticated super-admin session'),
                    '403' => new Response('Missing or invalid X-CSRF-Token header'),
                ],
                summary: 'Invalidate the super-admin session (CSRF-protected)',
                parameters: [[
                    'name' => 'X-CSRF-Token',
                    'in' => 'header',
                    'required' => true,
                    'schema' => ['type' => 'string'],
                ]],
            )),
        ];
    }

    /**
     * @return array<string, PathItem>
     */
    private function adminMonitoringPaths(): array
    {
        $solverSummary = [
            'type' => 'object',
            'properties' => [
                'generations' => ['type' => 'integer'],
                'infeasible' => ['type' => 'integer'],
                'infeasibleRate' => ['type' => 'number', 'format' => 'float'],
                'p50WallTimeMs' => ['type' => 'integer', 'nullable' => true],
                'p95WallTimeMs' => ['type' => 'integer', 'nullable' => true],
            ],
        ];

        return [
            '/api/admin/health' => new PathItem(get: new Operation(
                operationId: 'getAdminHealth',
                tags: ['AdminMonitoring'],
                responses: [
                    '200' => $this->jsonResponse('Bounded infrastructure probes; individual failures produce a degraded payload, never a probe exception', [
                        'type' => 'object',
                        'properties' => [
                            'status' => ['type' => 'string', 'enum' => ['healthy', 'degraded']],
                            'checkedAt' => ['type' => 'string', 'format' => 'date-time'],
                            'services' => ['type' => 'object', 'properties' => [
                                'database' => $this->healthProbeSchema(),
                                'redis' => $this->healthProbeSchema(),
                                'engine' => $this->healthProbeSchema(),
                                'worker' => ['type' => 'object', 'properties' => [
                                    'status' => ['type' => 'string', 'enum' => ['up', 'down', 'unknown']],
                                    'lastHeartbeatAt' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
                                    'ageSeconds' => ['type' => 'integer', 'nullable' => true],
                                ]],
                                'mercure' => $this->healthProbeSchema(),
                            ]],
                            'messenger' => ['type' => 'object', 'properties' => [
                                'status' => ['type' => 'string', 'enum' => ['up', 'degraded', 'unknown']],
                                'backlog' => ['type' => 'integer', 'nullable' => true],
                                'failed' => ['type' => 'integer', 'nullable' => true],
                                'retriesToday' => ['type' => 'integer', 'nullable' => true],
                                'backlogWarningThreshold' => ['type' => 'integer'],
                            ]],
                        ],
                    ]),
                    '401' => new Response('No authenticated super-admin session'),
                ],
                summary: 'Probe DB, Redis, engine, worker heartbeat, Mercure and Messenger queues',
            )),
            '/api/admin/freshness' => new PathItem(get: new Operation(
                operationId: 'getAdminFreshness',
                tags: ['AdminMonitoring'],
                responses: [
                    // « Jamais importé » est PÉRIMÉ, pas inconnu (fail-visible) : `lastUpdatedAt`
                    // null va toujours avec `stale` true. Le job d'alerte lit la même liste.
                    '200' => $this->jsonResponse('Reference-data freshness board (school holidays, public holidays, FFBB directory, DB backup)', [
                        'type' => 'object',
                        'properties' => [
                            'items' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                                'key' => ['type' => 'string', 'enum' => ['school-holidays', 'public-holidays', 'ffbb-directory', 'db-backup']],
                                'label' => ['type' => 'string'],
                                'lastUpdatedAt' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                                'staleAfterDays' => ['type' => 'integer'],
                                'stale' => ['type' => 'boolean'],
                            ]]],
                        ],
                    ]),
                    '401' => new Response('No authenticated super-admin session'),
                ],
                summary: 'Read the data-freshness board of the reference datasets',
            )),
            '/api/admin/overview' => new PathItem(get: new Operation(
                operationId: 'getAdminOverview',
                tags: ['AdminMonitoring'],
                responses: [
                    '200' => $this->jsonResponse('Cross-tenant fleet and 30-day solver aggregates', [
                        'type' => 'object',
                        'properties' => [
                            'clubs' => ['type' => 'object', 'properties' => [
                                'total' => ['type' => 'integer'],
                                'active7d' => ['type' => 'integer'],
                                'active30d' => ['type' => 'integer'],
                                'new7d' => ['type' => 'integer'],
                                'unsubscribed' => ['type' => 'integer'],
                            ]],
                            'solver' => [...$solverSummary, 'properties' => [
                                ...$solverSummary['properties'],
                                'windowDays' => ['type' => 'integer', 'enum' => [30]],
                                'completed' => ['type' => 'integer'],
                                'failed' => ['type' => 'integer'],
                                'daily' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                                    'date' => ['type' => 'string', 'format' => 'date'],
                                    'generations' => ['type' => 'integer'],
                                    'infeasible' => ['type' => 'integer'],
                                    'p50WallTimeMs' => ['type' => 'integer', 'nullable' => true],
                                    'p95WallTimeMs' => ['type' => 'integer', 'nullable' => true],
                                ]]],
                            ]],
                        ],
                    ]),
                    '401' => new Response('No authenticated super-admin session'),
                ],
                summary: 'Read the global fleet and solver monitoring overview',
            )),
            '/api/admin/capacity' => new PathItem(get: new Operation(
                operationId: 'getAdminCapacity',
                tags: ['AdminMonitoring'],
                responses: [
                    // Toutes les valeurs sont nullable : l'historique d'avant les colonnes de
                    // capacité et les chemins terminaux (échec, timeout) les laissent vides.
                    '200' => $this->jsonResponse('Cross-tenant 90-day capacity aggregates from solver telemetry', [
                        'type' => 'object',
                        'properties' => [
                            'windowDays' => ['type' => 'integer', 'enum' => [90]],
                            'totalSolves' => ['type' => 'integer'],
                            'volume' => ['type' => 'object', 'properties' => [
                                'perDay' => ['type' => 'object', 'properties' => [
                                    'p50' => ['type' => 'integer', 'nullable' => true],
                                    'max' => ['type' => 'integer', 'nullable' => true],
                                    'maxDate' => ['type' => 'string', 'format' => 'date', 'nullable' => true],
                                ]],
                                'hourly' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                                    'hour' => ['type' => 'integer'],
                                    'solves' => ['type' => 'integer'],
                                ]]],
                            ]],
                            'wait' => ['type' => 'object', 'properties' => [
                                'queueP50Ms' => ['type' => 'integer', 'nullable' => true],
                                'queueP95Ms' => ['type' => 'integer', 'nullable' => true],
                                'queueMaxMs' => ['type' => 'integer', 'nullable' => true],
                                'engineWaitP95Ms' => ['type' => 'integer', 'nullable' => true],
                            ]],
                            'bySize' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                                'bucket' => ['type' => 'string', 'enum' => ['small', 'medium', 'large']],
                                'solves' => ['type' => 'integer'],
                                'p50WallTimeMs' => ['type' => 'integer', 'nullable' => true],
                                'p95WallTimeMs' => ['type' => 'integer', 'nullable' => true],
                                'p95BudgetRatio' => ['type' => 'number', 'nullable' => true],
                                'unclosedProofRate' => ['type' => 'number'],
                            ]]],
                            'memory' => ['type' => 'object', 'properties' => [
                                'peakP50Mb' => ['type' => 'number', 'nullable' => true],
                                'peakP95Mb' => ['type' => 'number', 'nullable' => true],
                                'peakMaxMb' => ['type' => 'number', 'nullable' => true],
                                'lastBaselineMb' => ['type' => 'number', 'nullable' => true],
                            ]],
                            'issues' => ['type' => 'object', 'properties' => [
                                'byStatus' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                                    'status' => ['type' => 'string'],
                                    'solves' => ['type' => 'integer'],
                                ]]],
                                'payloadP95Bytes' => ['type' => 'integer', 'nullable' => true],
                            ]],
                        ],
                    ]),
                    '401' => new Response('No authenticated super-admin session'),
                ],
                summary: 'Read the 90-day capacity board (volume, queue wait, durations by problem size, engine memory, issues)',
            )),
            '/api/admin/clubs' => new PathItem(get: new Operation(
                operationId: 'getAdminClubs',
                tags: ['AdminMonitoring'],
                responses: [
                    '200' => $this->jsonResponse('Paginated cross-tenant club supervision list', [
                        'type' => 'object',
                        'properties' => [
                            'items' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                                'id' => ['type' => 'string', 'format' => 'uuid'],
                                'name' => ['type' => 'string'],
                                'slug' => ['type' => 'string'],
                                'ffbbClubCode' => ['type' => 'string', 'nullable' => true],
                                'plan' => ['type' => 'object', 'nullable' => true, 'properties' => [
                                    'code' => ['type' => 'string'],
                                    'name' => ['type' => 'string'],
                                ]],
                                'paidSeasonYear' => ['type' => 'integer', 'nullable' => true],
                                'effectivePlan' => ['type' => 'object', 'properties' => [
                                    'code' => ['type' => 'string'],
                                    'name' => ['type' => 'string'],
                                ]],
                                'billingCycle' => ['type' => 'string', 'nullable' => true],
                                'generationCountSeason' => ['type' => 'integer'],
                                'createdAt' => ['type' => 'string', 'format' => 'date-time'],
                                'lastActivityAt' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
                                'unsubscribed' => ['type' => 'boolean'],
                                'currentSeason' => ['type' => 'object', 'nullable' => true, 'properties' => [
                                    'id' => ['type' => 'string', 'format' => 'uuid'],
                                    'name' => ['type' => 'string'],
                                    'status' => ['type' => 'string'],
                                ]],
                                'volumes' => ['type' => 'object', 'properties' => [
                                    'teams' => ['type' => 'integer'],
                                    'venues' => ['type' => 'integer'],
                                    'coaches' => ['type' => 'integer'],
                                    'constraints' => ['type' => 'integer'],
                                ]],
                                'solver' => [...$solverSummary, 'properties' => [
                                    ...$solverSummary['properties'],
                                    'latestStatus' => ['type' => 'string', 'nullable' => true],
                                    'latestAt' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
                                ]],
                            ]]],
                            'pagination' => ['type' => 'object', 'properties' => [
                                'page' => ['type' => 'integer'],
                                'limit' => ['type' => 'integer'],
                                'total' => ['type' => 'integer'],
                                'pages' => ['type' => 'integer'],
                            ]],
                            'metricsWindowDays' => ['type' => 'integer', 'enum' => [30]],
                        ],
                    ]),
                    '400' => new Response('Invalid pagination or query parameters'),
                    '401' => new Response('No authenticated super-admin session'),
                ],
                summary: 'Search and paginate all clubs with current-season and solver indicators',
                parameters: [
                    ['name' => 'page', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer', 'minimum' => 1, 'default' => 1]],
                    ['name' => 'limit', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 25]],
                    ['name' => 'query', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string', 'maxLength' => 100]],
                ],
            )),
        ];
    }

    /**
     * @return array<string, PathItem>
     */
    private function adminJobPaths(): array
    {
        $latestRun = [
            'type' => 'object',
            'nullable' => true,
            'properties' => [
                'id' => ['type' => 'string', 'format' => 'uuid'],
                'status' => ['type' => 'string', 'enum' => AdminJobStatus::values()],
                'source' => ['type' => 'string', 'enum' => AdminJobSource::values()],
                'startedAt' => ['type' => 'string', 'format' => 'date-time'],
                'finishedAt' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
                'durationMs' => ['type' => 'integer', 'nullable' => true],
                'exitCode' => ['type' => 'integer', 'nullable' => true],
            ],
        ];

        return [
            '/api/admin/jobs' => new PathItem(get: new Operation(
                operationId: 'getAdminJobs',
                tags: ['AdminJobs'],
                responses: [
                    '200' => $this->jsonResponse('Closed operational-job catalog with schedule and latest execution', [
                        'type' => 'object',
                        'properties' => [
                            'items' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                                'key' => ['type' => 'string'],
                                'label' => ['type' => 'string'],
                                'command' => ['type' => 'string'],
                                'cadence' => ['type' => 'string', 'enum' => ['every_10_minutes', 'daily', 'quarterly']],
                                'manualTriggerAllowed' => ['type' => 'boolean'],
                                'nextRunAt' => ['type' => 'string', 'format' => 'date-time'],
                                'latestRun' => $latestRun,
                            ]]],
                        ],
                    ]),
                    '401' => new Response('No authenticated super-admin session'),
                ],
                summary: 'Monitor allowlisted operational jobs',
            )),
            '/api/admin/jobs/{key}/run' => new PathItem(post: new Operation(
                operationId: 'postAdminJobRun',
                tags: ['AdminJobs'],
                responses: [
                    '200' => $this->jsonResponse('Reference import completed', [
                        'type' => 'object',
                        'properties' => [
                            'key' => ['type' => 'string'],
                            'status' => ['type' => 'string', 'enum' => [AdminJobStatus::SUCCEEDED->value]],
                            'exitCode' => ['type' => 'integer', 'enum' => [0]],
                        ],
                    ]),
                    '401' => new Response('No authenticated super-admin session'),
                    '403' => new Response('Missing or invalid X-CSRF-Token header'),
                    '404' => new Response('Unknown job or job not manually triggerable'),
                    '409' => new Response('Job already running'),
                    '500' => new Response('Unexpected execution failure'),
                    '502' => new Response('The import command returned a non-zero exit code'),
                ],
                summary: 'Run one of the two manually triggerable reference imports',
                parameters: [
                    ['name' => 'key', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'enum' => ['import-school-holidays', 'import-public-holidays']]],
                    ['name' => 'X-CSRF-Token', 'in' => 'header', 'required' => true, 'schema' => ['type' => 'string']],
                ],
            )),
        ];
    }

    /**
     * SA4 — le catalogue d'actions support et les deux portes de déblocage (P3-4 PR B).
     *
     * ⚠ Les 404 de cette famille sont VOLONTAIREMENT indistincts : action inconnue, uuid
     * malformé et club inexistant rendent la même réponse (`AdminClubActionController:84-99`).
     * Documenter trois codes distincts inviterait un client à croire qu'il peut les
     * discriminer — et un jour quelqu'un l'implémenterait côté serveur pour « respecter le
     * contrat ».
     *
     * @return array<string, PathItem>
     */
    private function adminSupportPaths(): array
    {
        $csrfHeader = ['name' => 'X-CSRF-Token', 'in' => 'header', 'required' => true, 'schema' => ['type' => 'string']];
        $decisionBody = $this->jsonBody([
            'type' => 'object',
            'required' => ['decision'],
            'properties' => ['decision' => ['type' => 'string', 'enum' => ['approve', 'refuse']]],
        ]);

        return [
            '/api/admin/actions' => new PathItem(get: new Operation(
                operationId: 'getAdminActions',
                tags: ['AdminSupport'],
                responses: [
                    '200' => $this->jsonResponse('The CLOSED catalogue of support actions (never an arbitrary command)', [
                        'type' => 'object',
                        'properties' => [
                            'items' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                                'key' => ['type' => 'string'],
                                'label' => ['type' => 'string'],
                                'description' => ['type' => 'string'],
                                // Le client DOIT exiger une confirmation nominative dessus.
                                'dangerous' => ['type' => 'boolean'],
                                // Schéma FERMÉ des arguments runtime : le client rend ses pickers DEPUIS
                                // cette liste (choix + présence), jamais d'une liste en dur. `gate`
                                // présent = argument conditionnel (masqué quand le gate ∈ forbiddenValues).
                                'arguments' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                                    'key' => ['type' => 'string'],
                                    'label' => ['type' => 'string'],
                                    'required' => ['type' => 'boolean'],
                                    'choices' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                                        'value' => ['type' => 'string'],
                                        'label' => ['type' => 'string'],
                                    ]]],
                                    'gate' => ['type' => 'object', 'properties' => [
                                        'argument' => ['type' => 'string'],
                                        'forbiddenValues' => ['type' => 'array', 'items' => ['type' => 'string']],
                                    ]],
                                ]]],
                            ]]],
                        ],
                    ]),
                    '401' => new Response('No authenticated super-admin session'),
                ],
                summary: 'List the closed catalogue of per-club support actions',
            )),
            '/api/admin/clubs/{clubId}/actions/{key}' => new PathItem(post: new Operation(
                operationId: 'runAdminClubAction',
                tags: ['AdminSupport'],
                responses: [
                    '200' => $this->jsonResponse('The action ran and its command exited 0', [
                        'type' => 'object',
                        'properties' => [
                            'key' => ['type' => 'string'],
                            'clubId' => ['type' => 'string', 'format' => 'uuid'],
                            'status' => ['type' => 'string', 'enum' => [AdminJobStatus::SUCCEEDED->value]],
                            'exitCode' => ['type' => 'integer', 'enum' => [0]],
                        ],
                    ]),
                    '400' => new Response('The optional argument body violated the action\'s closed schema: unknown key, value outside the enum, a required argument missing, a forbidden argument present, or any body on a schema-less action'),
                    '401' => new Response('No authenticated super-admin session'),
                    '403' => new Response('Invalid CSRF token'),
                    '404' => new Response('Unknown action key, malformed club id, or unknown club (deliberately indistinct)'),
                    '409' => new Response('The same action (or its scheduled twin) is already running'),
                    '500' => new Response('Unexpected execution failure'),
                    '502' => new Response('The command returned a non-zero exit code (body carries exitCode)'),
                ],
                summary: 'Run one catalogue support action against one club (audited, locked, historised)',
                parameters: [
                    // Aucun `requirements` de route sur clubId côté Symfony, délibérément : un
                    // 404 AU ROUTEUR précéderait le firewall et renseignerait un probe anonyme
                    // sans laisser de trace. Le format est donc décrit ici, validé en controller.
                    ['name' => 'clubId', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'format' => 'uuid']],
                    ['name' => 'key', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                    $csrfHeader,
                ],
                // Body OPTIONNEL : présent seulement pour une action à schéma (ex. set-plan →
                // {plan, paidSeason}). Les clés/valeurs autorisées sont servies par GET /actions ;
                // toute dérive est un 400 fail-closed, jamais un argument libre vers la commande.
                requestBody: $this->jsonBody([
                    'type' => 'object',
                    'additionalProperties' => ['type' => 'string'],
                    'description' => 'Enum-valued arguments bounded by the action\'s closed schema (see GET /api/admin/actions). Empty/absent for schema-less actions.',
                ]),
            )),
            '/api/admin/club-requests' => new PathItem(get: new Operation(
                operationId: 'getAdminClubRequests',
                tags: ['AdminSupport'],
                responses: [
                    '200' => $this->jsonResponse('Club-creation requests still actionable — PENDING and EXPIRED alike (an expired one leaves the public link, not the console)', [
                        'type' => 'object',
                        'properties' => [
                            'items' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                                'id' => ['type' => 'string', 'format' => 'uuid'],
                                'clubName' => ['type' => 'string'],
                                'ara' => ['type' => 'string'],
                                'clubEmail' => ['type' => ['string', 'null']],
                                'status' => ['type' => 'string', 'enum' => ['pending', 'expired']],
                                'requesterName' => ['type' => 'string'],
                                'requesterEmail' => ['type' => 'string'],
                                'createdAt' => ['type' => 'string', 'format' => 'date'],
                                'expiresAt' => ['type' => 'string', 'format' => 'date'],
                            ]]],
                        ],
                    ]),
                    '401' => new Response('No authenticated super-admin session'),
                ],
                summary: 'List the club-creation requests awaiting a decision',
            )),
            '/api/admin/club-requests/{id}/decision' => new PathItem(post: new Operation(
                operationId: 'decideAdminClubRequest',
                tags: ['AdminSupport'],
                responses: [
                    '200' => $this->jsonResponse('Decision recorded — `clubId` is present on approve only', [
                        'type' => 'object',
                        'properties' => [
                            'status' => ['type' => 'string', 'enum' => ['approved', 'refused']],
                            'clubId' => ['type' => 'string', 'format' => 'uuid'],
                        ],
                    ]),
                    '401' => new Response('No authenticated super-admin session'),
                    '403' => new Response('Invalid CSRF token'),
                    '404' => new Response('Unknown request, malformed id, or already decided'),
                    '422' => new Response('decision must be "approve" or "refuse"'),
                ],
                summary: 'Approve or refuse a club-creation request from the console (fallback when the FFBB mail path is dead)',
                parameters: [
                    ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'format' => 'uuid']],
                    $csrfHeader,
                ],
                requestBody: $decisionBody,
            )),
            '/api/admin/pending-memberships' => new PathItem(get: new Operation(
                operationId: 'getAdminPendingMemberships',
                tags: ['AdminSupport'],
                responses: [
                    '200' => $this->jsonResponse('Memberships awaiting approval, all clubs', [
                        'type' => 'object',
                        'properties' => [
                            'items' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                                'id' => ['type' => 'string', 'format' => 'uuid'],
                                'clubName' => ['type' => 'string'],
                                'ara' => ['type' => ['string', 'null']],
                                'userName' => ['type' => 'string'],
                                'userEmail' => ['type' => 'string'],
                                'createdAt' => ['type' => 'string', 'format' => 'date'],
                            ]]],
                        ],
                    ]),
                    '401' => new Response('No authenticated super-admin session'),
                ],
                summary: 'List the pending club memberships across all clubs',
            )),
            '/api/admin/pending-memberships/{id}/activate' => new PathItem(post: new Operation(
                operationId: 'activateAdminPendingMembership',
                tags: ['AdminSupport'],
                responses: [
                    '200' => $this->jsonResponse('Membership activated', [
                        'type' => 'object',
                        'properties' => ['status' => ['type' => 'string', 'enum' => ['activated']]],
                    ]),
                    '401' => new Response('No authenticated super-admin session'),
                    '403' => new Response('Invalid CSRF token'),
                    // Un membre DÉSACTIVÉ par son club tombe ici aussi : cette porte n'ouvre
                    // que le PENDING, jamais une réactivation (elle contournerait le club).
                    '404' => new Response('Unknown, malformed, already active, or deactivated-by-the-club membership'),
                ],
                summary: 'Activate a pending membership when the club hand-over never happened',
                parameters: [
                    ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'format' => 'uuid']],
                    $csrfHeader,
                ],
            )),
        ];
    }

    /**
     * Les trois journaux read-only de la console (livrés avec les onglets, 2026-07-25).
     *
     * @return array<string, PathItem>
     */
    private function adminJournalPaths(): array
    {
        $pageParameters = [
            ['name' => 'page', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer', 'minimum' => 1, 'default' => 1]],
        ];

        return [
            '/api/admin/audit-log' => new PathItem(get: new Operation(
                operationId: 'getAdminAuditLog',
                tags: ['AdminJournal'],
                responses: [
                    '200' => $this->jsonResponse('Paginated super-admin audit trail (who did what on the cross-tenant surface)', [
                        'type' => 'object',
                        'properties' => [
                            'items' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                                'id' => ['type' => 'string'],
                                'actorId' => ['type' => ['string', 'null'], 'format' => 'uuid'],
                                'actorEmail' => ['type' => ['string', 'null']],
                                'route' => ['type' => ['string', 'null']],
                                // `details` du modèle : le contexte fusionné par
                                // AdminAuditSubscriber (clubId, actionKey…), forme libre.
                                'context' => ['type' => 'object', 'additionalProperties' => true],
                                'status' => ['type' => ['integer', 'null']],
                                'createdAt' => ['type' => 'string', 'format' => 'date-time'],
                            ]]],
                            'pagination' => $this->paginationSchema(),
                        ],
                    ]),
                    '400' => new Response('Invalid page, limit, actor UUID or since date'),
                    '401' => new Response('No authenticated super-admin session'),
                ],
                summary: 'Read the paginated super-admin audit log',
                parameters: [
                    ...$pageParameters,
                    ['name' => 'limit', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50]],
                    ['name' => 'actor', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string', 'format' => 'uuid']],
                    ['name' => 'route', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string'], 'description' => 'Substring match on the audited route'],
                    ['name' => 'since', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string', 'format' => 'date'], 'description' => 'ISO date (YYYY-MM-DD)'],
                ],
            )),
            '/api/admin/messenger/failed' => new PathItem(get: new Operation(
                operationId: 'getAdminMessengerFailed',
                tags: ['AdminJournal'],
                responses: [
                    // ⚠ JAMAIS le body du message — il porte de la donnée club (PII). Seuls la
                    // classe, l'horodatage et le message d'erreur sortent.
                    '200' => $this->jsonResponse('Paginated Messenger failure queue — metadata only, never the message body (PII)', [
                        'type' => 'object',
                        'properties' => [
                            'items' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                                'id' => ['type' => 'string'],
                                'class' => ['type' => 'string', 'description' => 'Message FQCN, or "(classe inconnue)" when the class no longer exists in the code'],
                                'failedAt' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                                'lastErrorMessage' => ['type' => 'string'],
                            ]]],
                            'pagination' => $this->paginationSchema(),
                        ],
                    ]),
                    '401' => new Response('No authenticated super-admin session'),
                ],
                summary: 'List the failed Messenger envelopes (metadata only)',
                parameters: [
                    ...$pageParameters,
                    ['name' => 'limit', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 10]],
                ],
            )),
            '/api/admin/system-errors' => new PathItem(get: new Operation(
                operationId: 'getAdminSystemErrors',
                tags: ['AdminJournal'],
                responses: [
                    '200' => $this->jsonResponse('Paginated union of failed job runs and auth failures, deduplicated per (message, hour)', [
                        'type' => 'object',
                        'properties' => [
                            'items' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                                'source' => ['type' => 'string', 'enum' => ['job', 'audit']],
                                'message' => ['type' => 'string'],
                                'severity' => ['type' => 'string', 'enum' => ['error']],
                                'createdAt' => ['type' => 'string', 'format' => 'date-time'],
                            ]]],
                            'pagination' => $this->paginationSchema(),
                        ],
                    ]),
                    '400' => new Response('Invalid page, limit or since date'),
                    '401' => new Response('No authenticated super-admin session'),
                ],
                summary: 'Read the deduplicated system-error journal',
                parameters: [
                    ...$pageParameters,
                    ['name' => 'limit', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50]],
                    ['name' => 'since', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string', 'format' => 'date'], 'description' => 'ISO date (YYYY-MM-DD)'],
                ],
            )),
        ];
    }

    /**
     * Les pages PUBLIQUES à token : approbation de club et doléances coach.
     *
     * ⚠ **Le token EST l'identité** — il n'y a pas de JWT sur ces pages. Ne cherchez pas ça
     * dans l'absence de `security` sur l'opération : ce document déclare `security: []` au
     * GLOBAL et aucune opération ne porte de scheme, authentifiée ou non — l'absence n'y
     * distingue donc rien. Ce que le contrat doit porter, sous peine d'induire un client en
     * erreur, ce sont les trois conséquences OBSERVABLES : le 404 est BYTE-IDENTIQUE pour un
     * token inconnu, malformé ou déjà consommé (rien ne distingue une ressource close d'un
     * token inventé) ; le rate-limit par IP passe AVANT toute résolution, donc un 429 ne dit
     * rien de l'existence du token ; et l'expiration répond 410, seul code de cette famille
     * qui admette l'existence de la ressource — elle ne fuite rien, le lien ayant été envoyé
     * à son destinataire. L'accès réel se lit dans `config/packages/security.yaml`
     * (`^/api/coach-wishes/public/` et `^/api/club-approvals/` en `PUBLIC_ACCESS`, GET+POST).
     *
     * @return array<string, PathItem>
     */
    private function publicTokenPaths(): array
    {
        $tokenParameter = [
            'name' => 'token',
            'in' => 'path',
            'required' => true,
            'schema' => ['type' => 'string', 'pattern' => '^[0-9a-f]{64}$'],
            'description' => 'The raw 64-hex token from the emailed link — it IS the identity',
        ];
        $notFound = new Response('Not found — identical for an unknown, malformed or already-consumed token');
        $tooMany = new Response('Too many attempts from this IP (rate limited before any resolution)');

        return [
            '/api/club-approvals/{token}' => new PathItem(
                get: new Operation(
                    operationId: 'getPublicClubApproval',
                    tags: ['PublicToken'],
                    responses: [
                        '200' => $this->jsonResponse('What the institutional recipient must decide on', [
                            'type' => 'object',
                            'properties' => [
                                'clubName' => ['type' => 'string'],
                                'ara' => ['type' => 'string'],
                                'requesterName' => ['type' => 'string'],
                                'expiresAt' => ['type' => 'string', 'format' => 'date'],
                            ],
                        ]),
                        '404' => $notFound,
                        '410' => new Response('The request expired — the super-admin console remains the fallback'),
                        '429' => $tooMany,
                    ],
                    summary: 'Read a pending club-creation request from its public approval link (no account, no JWT)',
                    parameters: [$tokenParameter],
                ),
                post: new Operation(
                    operationId: 'decidePublicClubApproval',
                    tags: ['PublicToken'],
                    responses: [
                        // La décision est UNIQUE : le premier POST gagne, les suivants voient 404.
                        '200' => $this->jsonResponse('Decision recorded (single-shot — a second call sees the 404)', [
                            'type' => 'object',
                            'properties' => ['status' => ['type' => 'string', 'enum' => ['approved', 'refused']]],
                        ]),
                        '404' => $notFound,
                        '410' => new Response('The request expired'),
                        '422' => new Response('decision must be "approve" or "refuse"'),
                        '429' => $tooMany,
                    ],
                    summary: 'Approve or refuse a club creation from the public link',
                    parameters: [$tokenParameter],
                    requestBody: $this->jsonBody([
                        'type' => 'object',
                        'required' => ['decision'],
                        'properties' => ['decision' => ['type' => 'string', 'enum' => ['approve', 'refuse']]],
                    ]),
                ),
            ),
            '/api/coach-wishes/public/{token}' => new PathItem(
                get: new Operation(
                    operationId: 'getPublicCoachWish',
                    tags: ['PublicToken'],
                    responses: [
                        '200' => $this->jsonResponse('The coach perimeter and the current value of its wishes (pre-fill)', [
                            'type' => 'object',
                            'properties' => [
                                'coachFirstName' => ['type' => 'string'],
                                'periodTitle' => ['type' => 'string'],
                                'deadline' => ['type' => 'string', 'format' => 'date'],
                                'weeks' => ['type' => 'array', 'items' => ['type' => 'string', 'format' => 'date']],
                                // Le périmètre du coach SEUL — jamais les équipes d'un autre.
                                'teams' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                                    'id' => ['type' => 'string', 'format' => 'uuid'],
                                    'name' => ['type' => 'string'],
                                ]]],
                                'wishes' => ['type' => 'array', 'items' => $this->coachWishSchema()],
                                'respondedAt' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                            ],
                        ]),
                        '404' => $notFound,
                        '410' => new Response('Campaign past its deadline, or its season is archived (read-only)'),
                        '429' => $tooMany,
                    ],
                    summary: 'Read a coach wish form from its public link (no account, no JWT)',
                    parameters: [$tokenParameter],
                ),
                post: new Operation(
                    operationId: 'submitPublicCoachWish',
                    tags: ['PublicToken'],
                    responses: [
                        '200' => $this->jsonResponse('Wishes upserted (idempotent — the last line of a duplicated team+week wins)', [
                            'type' => 'object',
                            'properties' => ['deadline' => ['type' => 'string', 'format' => 'date']],
                        ]),
                        '404' => $notFound,
                        '410' => new Response('Campaign past its deadline, or its season is archived (read-only)'),
                        // Validation COMPLÈTE avant toute écriture : un 422 n'écrit rien.
                        '422' => new Response('Missing/oversized submissions, team outside the perimeter, week outside the collection, or invalid slot/day'),
                        '429' => $tooMany,
                    ],
                    summary: 'Submit the coach wishes for the weeks of the campaign',
                    parameters: [$tokenParameter],
                    requestBody: $this->jsonBody([
                        'type' => 'object',
                        'required' => ['submissions'],
                        'properties' => [
                            'submissions' => ['type' => 'array', 'items' => $this->coachWishSchema()],
                        ],
                    ]),
                ),
            ),
        ];
    }

    /**
     * Le proxy FFBB — le frontend n'appelle JAMAIS la fédération (frontière §2), et seuls
     * les champs utiles sont relayés, jamais le hit brut.
     *
     * @return array<string, PathItem>
     */
    private function ffbbPaths(): array
    {
        $salleList = ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
            'name' => ['type' => 'string'],
            'address' => ['type' => ['string', 'null']],
            'city' => ['type' => ['string', 'null']],
            'externalRef' => ['type' => ['string', 'null']],
            // Décimaux rendus en STRING : `Venue` les stocke ainsi, on normalise à la source.
            'latitude' => ['type' => ['string', 'null']],
            'longitude' => ['type' => ['string', 'null']],
        ]]];
        $unavailable = new Response('FFBB unreachable — best effort, never a broken gesture');
        $forbidden = new Response('Management role required');

        return [
            '/api/ffbb-logos/{scope}/{code}' => new PathItem(get: new Operation(
                operationId: 'getFfbbLogo',
                tags: ['Ffbb'],
                responses: [
                    '200' => new Response('The rehosted logo bytes (public brand asset, no personal data)', new ArrayObject([
                        'image/*' => ['schema' => ['type' => 'string', 'format' => 'binary']],
                    ])),
                    '404' => new Response('No logo stored under this scope+code'),
                ],
                summary: 'Serve a rehosted FFBB league or committee logo',
                parameters: [
                    ['name' => 'scope', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'enum' => ['league', 'committee']]],
                    ['name' => 'code', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9]{1,24}$']],
                ],
            )),
            '/api/ffbb/salles' => new PathItem(get: new Operation(
                operationId: 'getFfbbSalles',
                tags: ['Ffbb'],
                responses: [
                    // Ni le param ni le club ne donnent un CP exploitable ⇒ liste VIDE et
                    // `postalCode` null, jamais une erreur : le wizard garde la saisie libre.
                    '200' => $this->jsonResponse('The FFBB venues of a postal code, sorted by name (empty list when no usable postal code)', [
                        'type' => 'object',
                        'properties' => [
                            'postalCode' => ['type' => ['string', 'null']],
                            'salles' => $salleList,
                        ],
                    ]),
                    '401' => new Response('Unauthorized'),
                    '403' => $forbidden,
                    '502' => $unavailable,
                ],
                summary: 'Search the FFBB venues of a postal code (defaults to the club\'s)',
                parameters: [
                    ['name' => 'postalCode', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string', 'pattern' => '^\d{5}$'], 'description' => 'Defaults to the current club\'s postal code'],
                ],
            )),
            '/api/ffbb/salles-proches' => new PathItem(get: new Operation(
                operationId: 'getFfbbSallesNearby',
                tags: ['Ffbb'],
                responses: [
                    // Club sans géoloc ⇒ liste vide et `radiusKm` null : la combobox par CP
                    // reste le chemin. `radiusKm` rend le palier RETENU, pas le demandé —
                    // sans `radius`, la recherche s'élargit 3→5→10→20 tant qu'elle est maigre.
                    '200' => $this->jsonResponse('The FFBB venues near the club, sorted by distance (empty when the club has no geolocation)', [
                        'type' => 'object',
                        'properties' => [
                            'radiusKm' => ['type' => ['integer', 'null'], 'enum' => [3, 5, 10, 20, null]],
                            'salles' => $salleList,
                        ],
                    ]),
                    '401' => new Response('Unauthorized'),
                    '403' => $forbidden,
                    '502' => $unavailable,
                ],
                summary: 'List the FFBB venues near the club, auto-widening the radius until the result is useful',
                parameters: [
                    ['name' => 'radius', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer', 'enum' => [3, 5, 10, 20]], 'description' => 'Manual radius step in km; absent = auto-widening from 3 km'],
                ],
            )),
        ];
    }

    /**
     * La forme d'une doléance — LUE au pré-remplissage et ÉCRITE à la soumission. Une seule
     * définition : les deux sens partagent réellement la même forme (`PublicCoachWishController`
     * lit ce qu'il vient d'écrire), et deux copies divergeraient au premier champ ajouté.
     *
     * @return array<string, mixed>
     */
    private function coachWishSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['teamId', 'weekStart'],
            'properties' => [
                'teamId' => ['type' => 'string', 'format' => 'uuid'],
                'weekStart' => ['type' => 'string', 'format' => 'date', 'description' => 'Must be one of the campaign weeks AND still intersect the parent period'],
                'slotsWanted' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 7],
                'unavailableDays' => ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 7]],
                'comment' => ['type' => ['string', 'null'], 'maxLength' => 2000],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function paginationSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'page' => ['type' => 'integer'],
            'limit' => ['type' => 'integer'],
            'total' => ['type' => 'integer'],
            'pages' => ['type' => 'integer'],
        ]];
    }

    /** @return array<string, mixed> */
    private function healthProbeSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'status' => ['type' => 'string', 'enum' => ['up', 'down', 'unknown']],
                'latencyMs' => ['type' => 'integer'],
            ],
        ];
    }

    /**
     * @return array<string, PathItem>
     */
    private function holidayPaths(): array
    {
        $windowParameters = [
            ['name' => 'from', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string', 'format' => 'date'], 'description' => 'Window start (YYYY-MM-DD) — defaults to the active season start'],
            ['name' => 'to', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string', 'format' => 'date'], 'description' => 'Window end (YYYY-MM-DD) — defaults to the active season end'],
        ];

        return [
            // Statistiques d'utilisation des gymnases : lecture pure, agrégée SERVEUR (le front
            // n'agrège aucune règle métier). Le total par JOUR est le chiffre que le gestionnaire
            // porte devant sa mairie pour négocier ses créneaux.
            '/api/venue-usage-stats' => new PathItem(get: new Operation(
                operationId: 'getVenueUsageStats',
                tags: ['Clubs'],
                responses: [
                    '200' => $this->jsonResponse('Heures par gymnase et par niveau, ventilées par jour de semaine, réalisées (≤ aujourd\'hui) vs à venir', [
                        'type' => 'object',
                        'properties' => [
                            'range' => ['type' => 'object', 'properties' => [
                                'from' => ['type' => 'string', 'format' => 'date'],
                                'to' => ['type' => 'string', 'format' => 'date'],
                                'today' => ['type' => 'string', 'format' => 'date'],
                            ]],
                            'zone' => ['type' => 'string', 'nullable' => true, 'description' => 'Zone scolaire du club (null → les vacances ne neutralisent rien)'],
                            'venues' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                                'venueId' => ['type' => 'string'],
                                'name' => ['type' => 'string'],
                                'byDay' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                                    'day' => ['type' => 'integer', 'description' => 'Jour ISO (1 = lundi)'],
                                    'real' => ['type' => 'number'],
                                    'projected' => ['type' => 'number'],
                                    'total' => ['type' => 'number'],
                                ]]],
                                'real' => ['type' => 'number'],
                                'projected' => ['type' => 'number'],
                                'total' => ['type' => 'number'],
                            ]]],
                            'totalByDay' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                                'day' => ['type' => 'integer'],
                                'real' => ['type' => 'number'],
                                'projected' => ['type' => 'number'],
                                'total' => ['type' => 'number'],
                            ]]],
                            'byLevel' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                                'level' => ['type' => 'string'],
                                'label' => ['type' => 'string'],
                                'byDay' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                                    'day' => ['type' => 'integer'],
                                    'real' => ['type' => 'number'],
                                    'projected' => ['type' => 'number'],
                                    'total' => ['type' => 'number'],
                                ]]],
                                'real' => ['type' => 'number'],
                                'projected' => ['type' => 'number'],
                                'total' => ['type' => 'number'],
                            ]]],
                            'grandTotal' => ['type' => 'object', 'properties' => [
                                'real' => ['type' => 'number'],
                                'projected' => ['type' => 'number'],
                                'total' => ['type' => 'number'],
                            ]],
                        ],
                    ]),
                    '400' => new Response('No club in context, or invalid from/to, or no window (no active season)'),
                    '401' => new Response('Unauthorized (missing/expired JWT)'),
                ],
                summary: 'Heures d\'utilisation des gymnases par jour (réalisées vs à venir), et par niveau',
                parameters: $windowParameters,
            )),
            '/api/school-holidays' => new PathItem(get: new Operation(
                operationId: 'getSchoolHolidays',
                tags: ['Calendars'],
                responses: [
                    '200' => $this->jsonResponse('School holidays of the club zone within the window (zone null → empty items)', [
                        'type' => 'object',
                        'properties' => [
                            'zone' => ['type' => 'string', 'nullable' => true],
                            'items' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                                'id' => ['type' => 'string'],
                                'label' => ['type' => 'string'],
                                'holidayType' => ['type' => 'string'],
                                'startDate' => ['type' => 'string', 'format' => 'date'],
                                'endDate' => ['type' => 'string', 'format' => 'date'],
                                'schoolYear' => ['type' => 'string'],
                            ]]],
                        ],
                    ]),
                    '400' => new Response('No club in context, or (when the club zone is set) invalid from/to or no window (no active season) — a null zone short-circuits to 200 with empty items'),
                    '401' => new Response('Unauthorized (missing/expired JWT)'),
                ],
                summary: 'School holidays of the club academic zone (display feed, read-only)',
                parameters: $windowParameters,
            )),
            '/api/public-holidays' => new PathItem(get: new Operation(
                operationId: 'getPublicHolidays',
                tags: ['Calendars'],
                responses: [
                    '200' => $this->jsonResponse('NATIONAL public holidays ∪ the club territory extras within the window (zone null → NATIONAL only)', [
                        'type' => 'object',
                        'properties' => [
                            'zone' => ['type' => 'string', 'nullable' => true],
                            'items' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                                'id' => ['type' => 'string'],
                                'date' => ['type' => 'string', 'format' => 'date'],
                                'label' => ['type' => 'string'],
                                'national' => ['type' => 'boolean'],
                            ]]],
                        ],
                    ]),
                    '400' => new Response('No club in context, invalid from/to, or no window (no active season) — a null zone still returns the NATIONAL fériés (no short-circuit)'),
                    '401' => new Response('Unauthorized (missing/expired JWT)'),
                ],
                summary: 'Public holidays (jours fériés) applying to the club (display-only, never feeds the solver)',
                parameters: $windowParameters,
            )),
        ];
    }

    /**
     * P4-47 — les routes `#[Route]` custom qui n'apparaissaient nulle part dans le contrat.
     *
     * ⚑ Une route absente d'OpenAPI n'existe pour personne : le frontend écrit ses types à
     * partir de ce contrat, et un agent qui planifie le lit comme la vérité. Ces 18-là
     * étaient invisibles alors qu'elles portent des gestes centraux — valider un planning,
     * régénérer, réordonner les équipes, approuver une adhésion.
     *
     * ⚠ Les codes de réponse sont relevés DANS les contrôleurs, pas devinés : c'est la
     * moitié utile de la documentation d'une route. Une entrée qui annonce un 200 là où le
     * serveur rend 409 est pire que pas d'entrée — elle est crue.
     *
     * @return array<string, PathItem>
     */
    private function uncoveredCustomPaths(): array
    {
        $unauthorized = new Response('Unauthorized');
        $notFound = new Response('Resource not found');

        return [
            '/api/health' => new PathItem(get: new Operation(
                operationId: 'getApiHealth',
                tags: ['Ops'],
                responses: ['200' => new Response('Service is up (public, no authentication)')],
                summary: 'Liveness probe',
            )),
            '/api/schedules/{id}/validate' => new PathItem(post: new Operation(
                operationId: 'postApiScheduleValidate',
                tags: ['Schedules'],
                responses: [
                    '200' => new Response('Version pointed as the plan in force; COMPLETED siblings are archived'),
                    '401' => $unauthorized,
                    '409' => new Response('A version is already in force for this plan, or the schedule is not COMPLETED'),
                ],
                summary: 'Point this version as the plan in force',
            )),
            '/api/schedules/{id}/validate-impact' => new PathItem(get: new Operation(
                operationId: 'getApiScheduleValidateImpact',
                tags: ['Schedules'],
                responses: [
                    '200' => $this->jsonResponse('What validating this version will make lose its venue: home matches pointing at a venue no longer affiliated to the club (deleted venue, or a dangling pointer left by exploration). Same predicate as the validation trigger — read-only, open to any member.', [
                        'type' => 'object',
                        'properties' => [
                            'orphanedFixtures' => ['type' => 'integer', 'description' => 'Home matches that will go back to « à placer » on validation'],
                            'declaredOrphanedFixtures' => ['type' => 'integer', 'description' => 'Subset already declared to the federation (SUBMITTED/VALIDATED) — to re-submit'],
                        ],
                    ]),
                    '401' => $unauthorized,
                    '403' => new Response('Version belongs to another club'),
                    '404' => $notFound,
                ],
                summary: 'Venue-loss impact of validating this version (read-only, open to any member)',
            )),
            '/api/schedules/{id}/reopen' => new PathItem(post: new Operation(
                operationId: 'postApiScheduleReopen',
                tags: ['Schedules'],
                responses: [
                    '200' => new Response('Plan un-pointed: the season has no version in force again'),
                    '401' => $unauthorized,
                    '409' => new Response('This version is not the one in force'),
                ],
                summary: 'Un-point the plan so it can be regenerated',
            )),
            '/api/schedule_plans/{id}/transcribe-from-socle' => new PathItem(post: new Operation(
                operationId: 'postApiSchedulePlanTranscribeFromSocle',
                tags: ['Schedules'],
                responses: [
                    '201' => $this->jsonResponse('V1 created as a COMPLETED transcription of the season plan pointed version, filtered by the period settings (disabled teams/venues, effective closed weekdays, reduced sessions). Sessions that no longer fit are NOT copied and are returned as the to-replace list (team, weekday, start time, origin venue, reason). No solver call, the plan is NOT pointed.', [
                        'scheduleId' => ['type' => 'string', 'format' => 'uuid'],
                        'toReplace' => ['type' => 'array', 'items' => ['type' => 'object']],
                    ]),
                    '401' => $unauthorized,
                    '404' => $notFound,
                    '409' => new Response('The plan already has versions, is a SEASON plan, or the season plan has no pointed version to transcribe'),
                ],
                summary: 'Birth the period plan V1 as a copy of the season baseline',
            )),
            '/api/schedules/{id}/regenerate' => new PathItem(post: new Operation(
                operationId: 'postApiScheduleRegenerate',
                tags: ['Schedules'],
                responses: [
                    '202' => new Response('Regeneration queued (async, progress on the Mercure topic)'),
                    '401' => $unauthorized,
                    '409' => new Response('Version in force, or a generation is already running for this club'),
                    '429' => new Response('Club generation quota reached'),
                ],
                summary: 'Regenerate this version in place',
            )),
            '/api/schedules/{id}/regenerate-from' => new PathItem(post: new Operation(
                operationId: 'postApiScheduleRegenerateFrom',
                tags: ['Schedules'],
                responses: [
                    '202' => new Response('New version queued from the source version structure'),
                    '401' => $unauthorized,
                    '409' => new Response('Version in force, or a generation is already running'),
                    '429' => new Response('Club generation quota reached'),
                ],
                summary: 'Create a new version from an existing one and regenerate it',
            )),
            '/api/schedules/{id}/fill' => new PathItem(post: new Operation(
                operationId: 'postApiScheduleFill',
                tags: ['Schedules'],
                responses: [
                    '202' => new Response('Fill queued — a new period version that pins the placed sessions and only places the ones to relocate'),
                    '401' => $unauthorized,
                    '409' => new Response('Not a period version, version in force, or a generation is already running'),
                    '422' => new Response('Problem too complex, or a pinned session has no matching slot'),
                    '429' => new Response('Club generation quota reached'),
                ],
                summary: 'Fill the sessions to relocate on a period version (partial solve, placed sessions kept)',
            )),
            '/api/schedules/{id}/socle-deviation' => new PathItem(get: new Operation(
                operationId: 'getApiScheduleSocleDeviation',
                tags: ['Schedules'],
                responses: [
                    '200' => new Response('Named deviations of a CLOSURE period version vs the pointed season plan: `moved` sessions (paired chronologically, season placement → period placement) and `unplaced` sessions (the season remainder, each with a `reason` SERVED from the period selection — team_reduced/venue_disabled/venue_closed, or null when the selection does not explain it). New and unchanged sessions are never reported. Read-only, re-callable.'),
                    '401' => $unauthorized,
                    '403' => new Response('Version belongs to another club'),
                    '404' => $notFound,
                    '409' => new Response('Version not completed, or the season plan has no pointed version to compare against'),
                    '422' => new Response('Not a period plan (SEASON), or the period is not a CLOSURE'),
                ],
                summary: 'Named deviations of a closure period version vs the season plan, computed server-side',
            )),
            '/api/planned-windows' => new PathItem(get: new Operation(
                operationId: 'getApiPlannedWindows',
                tags: ['Cockpit'],
                responses: [
                    '200' => new Response('The period windows another plan ALREADY governs inside `[start, end]`, sorted by start date — served so the UI never offers a week whose plan creation the server would refuse with 409. Same predicate as the write guard, by construction. Each `windows[]` item: `entryId`, `title`, `startDate`, `endDate`, `label`, and `reason` — the ready-to-display sentence, composed server-side by the same helper that names the 409 refusal, and shown as-is (the UI adds only the shortcut to the conflicting planning; it never composes a domain sentence). With `entryId` the querying family is excluded (mother and sibling weeks are legitimate); with `seasonId` (mother not yet created) nothing is excluded. Read-only, open to members.'),
                    '400' => new Response('No club in context'),
                    '401' => $unauthorized,
                    '404' => new Response('Unknown entryId/seasonId, or belonging to another club (never an existence oracle)'),
                    '422' => new Response('Missing or malformed start/end (YYYY-MM-DD), or neither entryId nor seasonId given'),
                ],
                summary: 'Period windows already planned inside a date range, served for the UI',
                parameters: [
                    ['name' => 'start', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string', 'format' => 'date'], 'description' => 'Window lower bound (YYYY-MM-DD)'],
                    ['name' => 'end', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string', 'format' => 'date'], 'description' => 'Window upper bound (YYYY-MM-DD)'],
                    ['name' => 'entryId', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string'], 'description' => 'The materialised period entry; its season is used and its root family excluded. Provide this OR seasonId'],
                    ['name' => 'seasonId', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string'], 'description' => 'The season, for the "mother not yet created" path (no family excluded). Provide this OR entryId'],
                ],
            )),
            '/api/reservations/group' => new PathItem(post: new Operation(
                operationId: 'postApiReservationsGroup',
                tags: ['Reservation'],
                responses: [
                    '201' => $this->jsonResponse('Shared-training group reserved on one slot: N reservations (one per member) written in a single flush; the response carries their ids', [
                        'type' => 'object',
                        'properties' => [
                            'ids' => ['type' => 'array', 'items' => ['type' => 'string', 'format' => 'uuid']],
                            'count' => ['type' => 'integer'],
                        ],
                    ]),
                    '400' => new Response('Invalid JSON, or a missing/malformed field'),
                    '401' => $unauthorized,
                    '403' => new Response('Not a management member'),
                    '404' => new Response('Unknown shared-training group (or another club\'s — never an existence oracle)'),
                    '409' => new Response('Archived season (read-only)'),
                    '422' => new Response('Scope mismatch (group not declared for this plan), closed venue, occupied slot (exclusivity), shared-sessions ceiling reached, a member over its weekly sessions, or unknown schedule plan'),
                ],
                summary: 'Reserve a shared-training group on one slot (atomic batch write of one HARD reservation per member)',
                requestBody: $this->jsonBody([
                    'type' => 'object',
                    'required' => ['sharedTrainingGroupId', 'venueId', 'dayOfWeek', 'startTime'],
                    'properties' => [
                        'sharedTrainingGroupId' => ['type' => 'string', 'format' => 'uuid'],
                        'venueId' => ['type' => 'string', 'format' => 'uuid'],
                        'dayOfWeek' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 7],
                        'startTime' => ['type' => 'string', 'description' => 'HH:MM'],
                        'durationMinutes' => ['type' => 'integer', 'default' => 90],
                        'schedulePlanId' => ['type' => 'string', 'format' => 'uuid', 'nullable' => true, 'description' => 'NULL = base (season) plan; set = a period overlay — must match the group\'s own scope'],
                    ],
                ]),
            )),
            '/api/teams/reorder' => new PathItem(post: new Operation(
                operationId: 'postApiTeamsReorder',
                tags: ['Teams'],
                responses: [
                    '200' => new Response('New tier order persisted for every team, in ONE transaction'),
                    '401' => $unauthorized,
                    '422' => new Response('Unknown team, or a team outside the current club'),
                ],
                summary: 'Bulk reorder — atomic, so concurrent PUTs never race on the optimistic-lock version',
            )),
            '/api/constraints/validate' => new PathItem(post: new Operation(
                operationId: 'postApiConstraintsValidate',
                tags: ['Constraints'],
                responses: [
                    '200' => new Response('Advisory pre-solve report: blocking errors and warnings'),
                    '401' => $unauthorized,
                ],
                summary: 'Advisory gate — never writes, and does NOT run on generate/regenerate',
            )),
            '/api/venue_training_slots/{id}/deletion-impact' => new PathItem(get: new Operation(
                operationId: 'getApiVenueTrainingSlotDeletionImpact',
                tags: ['Deletion'],
                responses: [
                    '200' => new Response('What deleting this availability slot would destroy — announced BEFORE confirmation. Its children never cite the slot id: reservations and the HARD locks they materialised attach by (venue, weekday, start time) AND by LAYER, so the counts are bounded to the slot own layer (season grid vs a period copy). Solver-chosen SOFT/NONE placements are results, never touched. Read-only.'),
                    '401' => $unauthorized,
                    '403' => new Response('Slot belongs to another club'),
                    '404' => $notFound,
                ],
                summary: 'Impact of deleting this availability slot, computed server-side',
            )),
            '/api/venues/{id}/deletion-impact' => new PathItem(get: new Operation(
                operationId: 'getApiVenueDeletionImpact',
                tags: ['Deletion'],
                responses: [
                    '200' => new Response('What deleting this entity would destroy — announced BEFORE confirmation. `lines` are counted by walking the SAME cascade plan the delete executes (App\\Deletion\\CascadePlan), labels included, so a destruction can never be added without its announcement. `blocked`/`reason` carry the engaged-perimeter refusal (the UI must not offer a gesture the server will 409). `slotsInForce` = touched sessions living in a version the plan POINTS AT. `declaredFixtures` = matches already SUBMITTED/VALIDATED to the federation that will lose their venue — announced, never blocking. Read-only.'),
                    '401' => $unauthorized,
                    '403' => new Response('Entity belongs to another club'),
                    '404' => $notFound,
                ],
                summary: 'Impact of deleting this venue, computed server-side',
            )),
            '/api/teams/{id}/deletion-impact' => new PathItem(get: new Operation(
                operationId: 'getApiTeamDeletionImpact',
                tags: ['Deletion'],
                responses: [
                    '200' => new Response('What deleting this entity would destroy — announced BEFORE confirmation. `lines` are counted by walking the SAME cascade plan the delete executes (App\\Deletion\\CascadePlan), labels included, so a destruction can never be added without its announcement. `blocked`/`reason` carry the engaged-perimeter refusal (the UI must not offer a gesture the server will 409). `slotsInForce` = touched sessions living in a version the plan POINTS AT. `declaredFixtures` = matches already SUBMITTED/VALIDATED to the federation that will lose their venue — announced, never blocking. Read-only.'),
                    '401' => $unauthorized,
                    '403' => new Response('Entity belongs to another club'),
                    '404' => $notFound,
                ],
                summary: 'Impact of deleting this team, computed server-side',
            )),
            '/api/coaches/{id}/deletion-impact' => new PathItem(get: new Operation(
                operationId: 'getApiCoachDeletionImpact',
                tags: ['Deletion'],
                responses: [
                    '200' => new Response('What deleting this entity would destroy — announced BEFORE confirmation. `lines` are counted by walking the SAME cascade plan the delete executes (App\\Deletion\\CascadePlan), labels included, so a destruction can never be added without its announcement. `blocked`/`reason` carry the engaged-perimeter refusal (the UI must not offer a gesture the server will 409). `slotsInForce` = touched sessions living in a version the plan POINTS AT. `declaredFixtures` = matches already SUBMITTED/VALIDATED to the federation that will lose their venue — announced, never blocking. Read-only.'),
                    '401' => $unauthorized,
                    '403' => new Response('Entity belongs to another club'),
                    '404' => $notFound,
                ],
                summary: 'Impact of deleting this coach, computed server-side',
            )),
            '/api/calendar-entries/{id}/conflicts' => new PathItem(get: new Operation(
                operationId: 'getApiCalendarEntryConflicts',
                tags: ['Cockpit'],
                responses: [
                    '200' => new Response('Venue closures declared over this period (venue, title, dates, closed weekdays) plus the baseline sessions that fall on a closed venue during the window; sessions require a chosen season plan, closures do not'),
                    '401' => $unauthorized,
                    '404' => $notFound,
                ],
                summary: 'Venue closures and clashing baseline sessions for a period',
            )),
            '/api/reset-season' => new PathItem(post: new Operation(
                operationId: 'postApiResetSeason',
                tags: ['Season'],
                responses: [
                    '200' => new Response('Season workspace emptied'),
                    '401' => $unauthorized,
                    '403' => new Response('Member but not a management role'),
                    '409' => new Response('A generation is running, or the season is archived'),
                ],
                summary: 'Destructive — empties the season workspace (management only)',
            )),
            '/api/club/appearance' => new PathItem(patch: new Operation(
                operationId: 'patchApiClubAppearance',
                tags: ['Club'],
                responses: [
                    '200' => new Response('Appearance updated'),
                    '400' => new Response('No club in context, or invalid JSON'),
                    '404' => new Response('Club not found'),
                ],
                summary: 'Club colours and display preferences',
            )),
            '/api/club/logo' => new PathItem(
                post: new Operation(
                    operationId: 'postApiClubLogo',
                    tags: ['Club'],
                    responses: [
                        '200' => new Response('Logo stored; returns its URL'),
                        '400' => new Response('No club in context, or no file in the "file" field'),
                        '404' => new Response('Club not found'),
                        '422' => new Response('Larger than 500 KB, or not PNG/JPEG/WebP'),
                    ],
                    summary: 'Upload the club logo (multipart, MIME and size validated server-side)',
                ),
                delete: new Operation(
                    operationId: 'deleteApiClubLogo',
                    tags: ['Club'],
                    responses: [
                        '200' => new Response('Logo removed'),
                        '400' => new Response('No club in context'),
                        '404' => new Response('Club not found'),
                    ],
                    summary: 'Remove the club logo',
                ),
            ),
            '/api/clubs/{clubId}/logo' => new PathItem(get: new Operation(
                operationId: 'getApiClubLogo',
                tags: ['Club'],
                responses: [
                    '200' => new Response('Logo bytes (PUBLIC_ACCESS — read-only, no tenant)'),
                    '404' => new Response('No logo for this club'),
                ],
                summary: 'Serve a club logo',
            )),
            '/api/club/ffbb-import' => new PathItem(post: new Operation(
                operationId: 'postApiClubFfbbImport',
                tags: ['Club'],
                responses: [
                    '200' => new Response('Club refreshed from the FFBB public API (best-effort)'),
                    '401' => $unauthorized,
                    '403' => new Response('Member but not a management role'),
                ],
                summary: 'Re-import the club identity from FFBB (hosts hard-coded, SSRF-safe)',
            )),
            '/api/memberships' => new PathItem(get: new Operation(
                operationId: 'getApiMembershipsList',
                tags: ['Memberships'],
                responses: [
                    '200' => new Response('Active members of the current club (id, userId, email, firstName, lastName, role, isSelf); with `?includeDeactivated=1`, also a `deactivated` array of the same shape'),
                    '401' => $unauthorized,
                    '403' => new Response('Member but not a management role'),
                ],
                summary: 'List the active members of the current club (management-only)',
                parameters: [
                    ['name' => 'includeDeactivated', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'boolean'], 'description' => 'When truthy (1/true), also return a `deactivated` array of deactivated (reactivable) memberships alongside `members`'],
                ],
            )),
            '/api/memberships/pending' => new PathItem(get: new Operation(
                operationId: 'getApiMembershipsPending',
                tags: ['Memberships'],
                responses: [
                    '200' => new Response('Pending membership requests for the current club (deactivated members excluded)'),
                    '401' => $unauthorized,
                    '403' => new Response('Member but not a management role'),
                ],
                summary: 'Membership requests awaiting a decision',
            )),
            '/api/memberships/{id}/approve' => new PathItem(post: new Operation(
                operationId: 'postApiMembershipApprove',
                tags: ['Memberships'],
                responses: [
                    '200' => new Response('Membership activated with the required role (body {"role":"admin"|"member"})'),
                    '401' => $unauthorized,
                    '403' => new Response('Member but not a management role'),
                    '404' => $notFound,
                    '409' => new Response('Target is not genuinely pending (already active, or deactivated — use reactivate)'),
                    '422' => new Response('Role missing, or outside the assignable set (admin|member)'),
                ],
                summary: 'Approve a membership request, setting its role (required body {"role":"admin"|"member"})',
            )),
            '/api/memberships/{id}/role' => new PathItem(post: new Operation(
                operationId: 'postApiMembershipRole',
                tags: ['Memberships'],
                responses: [
                    '200' => new Response('Role changed'),
                    '401' => $unauthorized,
                    '403' => new Response('Member but not a management role'),
                    '404' => $notFound,
                    '409' => new Response('Would leave the club without an active manager'),
                    '422' => new Response('Role missing, or outside the assignable set (admin|member)'),
                ],
                summary: 'Change a member role (required body {"role":"admin"|"member"}, management-only)',
            )),
            '/api/memberships/{id}/deactivate' => new PathItem(post: new Operation(
                operationId: 'postApiMembershipDeactivate',
                tags: ['Memberships'],
                responses: [
                    '200' => new Response('Membership deactivated'),
                    '401' => $unauthorized,
                    '403' => new Response('Member but not a management role'),
                    '404' => $notFound,
                    '409' => new Response('Not an active membership, or would leave the club without an active manager'),
                ],
                summary: 'Deactivate an active membership (management-only)',
            )),
            '/api/memberships/{id}/reactivate' => new PathItem(post: new Operation(
                operationId: 'postApiMembershipReactivate',
                tags: ['Memberships'],
                responses: [
                    '200' => new Response('Membership reactivated with its prior role'),
                    '401' => $unauthorized,
                    '403' => new Response('Member but not a management role'),
                    '404' => $notFound,
                    '409' => new Response('Not a deactivated membership (a pending one activates only via approve)'),
                ],
                summary: 'Reactivate a deactivated membership (management-only)',
            )),
            '/api/memberships/{id}/reject' => new PathItem(post: new Operation(
                operationId: 'postApiMembershipReject',
                tags: ['Memberships'],
                responses: [
                    '204' => new Response('Membership request rejected'),
                    '401' => $unauthorized,
                    '403' => new Response('Member but not a management role'),
                    '404' => $notFound,
                    '409' => new Response('Target is not genuinely pending (an active member is deactivated, never rejected)'),
                ],
                summary: 'Reject a membership request',
            )),
            '/api/password/forgot' => new PathItem(post: new Operation(
                operationId: 'postApiPasswordForgot',
                tags: ['Auth'],
                responses: [
                    '200' => new Response('Always 200 — the response never reveals whether the email exists (anti-enumeration)'),
                    '429' => new Response('Per-IP rate limit'),
                ],
                summary: 'Request a password reset link (PUBLIC_ACCESS)',
            )),
            '/api/password/reset' => new PathItem(post: new Operation(
                operationId: 'postApiPasswordReset',
                tags: ['Auth'],
                responses: [
                    '200' => new Response('Password changed'),
                    '400' => new Response('Token unknown, expired or already used, or password too short'),
                ],
                summary: 'Consume a password reset token (PUBLIC_ACCESS)',
            )),
        ];
    }

    /**
     * @return array<string, PathItem>
     */
    private function manualEditPaths(): array
    {
        $messageResponse = static fn (string $description): Response => new Response(
            $description,
            new ArrayObject(['application/json' => ['schema' => [
                'type' => 'object', 'properties' => ['message' => ['type' => 'string']],
            ]]]),
        );

        // P2-32 — le DELTA de confort d'un candidat ACCEPTÉ (leçon P4-101 : forme COMPLÈTE, pas
        // un tableau nu). Aucun identifiant interne dans les descriptions.
        $compromises = [
            'type' => 'array',
            'description' => 'Named comfort trade-offs of an ACCEPTED candidate (empty on refusal): what the move BREAKS or GAINS, ready to display',
            'items' => ['type' => 'object', 'properties' => [
                'family' => ['type' => 'string', 'enum' => ['chaining', 'venue_preference', 'day_preference', 'time_preference', 'match_rest', 'spacing', 'coach_day_cap', 'implicit_rule'], 'description' => 'Which comfort family the trade-off belongs to'],
                'effect' => ['type' => 'string', 'enum' => ['broken', 'gained'], 'description' => 'broken = a preference honored before no longer is; gained = the reverse'],
                'message' => ['type' => 'string', 'description' => 'Ready-to-display sentence naming the team/coach/venue (no internal identifier)'],
                'teamId' => ['type' => 'string', 'nullable' => true, 'description' => 'Team the trade-off is about (grid highlighting)'],
                'coachId' => ['type' => 'string', 'nullable' => true, 'description' => 'Coach involved, when the family concerns one'],
                'venueId' => ['type' => 'string', 'nullable' => true, 'description' => 'Venue involved, when the family concerns one'],
                'dayOfWeek' => ['type' => 'integer', 'nullable' => true, 'description' => 'ISO day (1-7) involved, when relevant'],
                'startTime' => ['type' => 'string', 'nullable' => true, 'description' => 'Start time (HH:MM) involved, when relevant'],
            ]],
        ];
        // Les règles violées, telles que renvoyées dans l'essai (dryRun) d'un candidat refusé.
        $dryViolations = ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
            'rule' => ['type' => 'string', 'description' => 'Machine code of the broken rule (UI branches on it)'],
            'message' => ['type' => 'string', 'description' => 'Human sentence naming the coach/venue/time in conflict'],
            'teamId' => ['type' => 'string', 'nullable' => true],
            'coachId' => ['type' => 'string', 'nullable' => true],
            'venueId' => ['type' => 'string', 'nullable' => true],
            'dayOfWeek' => ['type' => 'integer', 'nullable' => true],
            'startTime' => ['type' => 'string', 'nullable' => true],
            'conflictingTeamId' => ['type' => 'string', 'nullable' => true],
        ]]];
        // `dryRun` : un ESSAI. Même chemin jusqu'au verdict inclus, mais RIEN écrit.
        $dryRunProperty = ['type' => 'boolean', 'nullable' => true, 'description' => 'When true, run the full verdict (pre-engine guards included) but write NOTHING; the response carries the verdict and its named trade-offs'];

        return [
            '/api/schedule-slots/{id}/manual-edit/lock' => new PathItem(post: new Operation(
                operationId: 'postManualEditLock',
                tags: ['ManualEdit'],
                responses: [
                    '200' => $messageResponse('Lock applied'),
                    '400' => new Response('Missing/invalid lockLevel'),
                    '404' => new Response('Slot not found'),
                    '409' => new Response('Schedule is validated (read-only)'),
                ],
                summary: 'Set the lock level of a schedule slot',
                requestBody: $this->jsonBody([
                    'type' => 'object',
                    'required' => ['lockLevel'],
                    'properties' => ['lockLevel' => ['type' => 'string', 'enum' => ['NONE', 'HARD']]],
                ]),
            )),
            '/api/schedule-slots/{id}/move' => new PathItem(post: new Operation(
                operationId: 'postScheduleSlotMove',
                tags: ['ManualEdit'],
                responses: [
                    '200' => $this->jsonResponse('Move accepted by the solver and written (schedule flagged manually edited, score now stale) — OR a dryRun essai (valid may be false, nothing written). Both carry the named comfort trade-offs', [
                        'type' => 'object',
                        'properties' => [
                            'message' => ['type' => 'string'],
                            'valid' => ['type' => 'boolean'],
                            'dryRun' => $dryRunProperty,
                            'violations' => array_merge($dryViolations, ['nullable' => true, 'description' => 'Present on a dryRun essai that the solver refused (valid=false); absent on a written move']),
                            'compromises' => $compromises,
                            'evicted' => ['type' => 'object', 'nullable' => true, 'description' => 'Present only when an occupant was evicted from the target (or WOULD be, on a dryRun): its state BEFORE deletion, so the UI can offer to re-place it', 'properties' => [
                                'slotId' => ['type' => 'string'],
                                'teamId' => ['type' => 'string'],
                                'dayOfWeek' => ['type' => 'integer'],
                                'startTime' => ['type' => 'string', 'example' => '20:00'],
                                'venueId' => ['type' => 'string'],
                                'durationMinutes' => ['type' => 'integer'],
                            ]],
                        ],
                    ]),
                    '400' => new Response('Missing or invalid field (dayOfWeek, startTime or venueId)'),
                    '404' => new Response('Slot not found'),
                    '409' => new Response('Schedule is validated (read-only), or a generation is running for the club (body carries code=generation_in_progress)'),
                    '422' => $this->jsonResponse('Refused with nothing written. Either the solver refused the move (broken rules named for display), OR no venue window is open at the destination — nonexistent target or closed day (body carries code=slot_unavailable), OR the eviction target is invalid (body carries code=evict_target_mismatch), OR the eviction target is locked (body carries code=target_locked)', [
                        'type' => 'object',
                        'properties' => [
                            'valid' => ['type' => 'boolean', 'enum' => [false]],
                            'code' => ['type' => 'string', 'nullable' => true, 'description' => 'slot_unavailable, evict_target_mismatch or target_locked when a server pre-check refused (no engine call)'],
                            'error' => ['type' => 'string', 'nullable' => true, 'description' => 'Human message for the eviction pre-check refusal'],
                            'violations' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                                'rule' => ['type' => 'string', 'description' => 'Machine code of the broken rule (UI branches on it)'],
                                'message' => ['type' => 'string', 'description' => 'Human sentence naming the coach/venue/time in conflict'],
                                'teamId' => ['type' => 'string', 'nullable' => true, 'description' => 'Team the violation is about (grid highlighting)'],
                                'coachId' => ['type' => 'string', 'nullable' => true, 'description' => 'Coach in conflict, when the rule involves one'],
                                'venueId' => ['type' => 'string', 'nullable' => true, 'description' => 'Venue involved in the violation'],
                                'dayOfWeek' => ['type' => 'integer', 'nullable' => true, 'description' => 'ISO day (1-7) of the conflicting occupation'],
                                'startTime' => ['type' => 'string', 'nullable' => true, 'description' => 'Start time (HH:MM) of the conflicting occupation'],
                                'conflictingTeamId' => ['type' => 'string', 'nullable' => true, 'description' => 'Team already occupying the target slot'],
                            ]]],
                        ],
                    ]),
                    '502' => new Response('Engine unreachable — nothing was written, retry'),
                    '504' => $this->jsonResponse('The engine answered too slowly and the verdict was dropped — nothing was written. Distinct from 502: the engine works, it merely exceeded the transport ceiling. Retrying is the right move (body carries code=engine_timeout)', [
                        'type' => 'object',
                        'properties' => [
                            'code' => ['type' => 'string', 'enum' => ['engine_timeout']],
                            'error' => ['type' => 'string', 'description' => 'Human message naming the timeout'],
                        ],
                    ]),
                ],
                summary: 'Move a slot (day / time / venue) under the solver verdict: written only if the engine accepts it, otherwise refused with the named broken rules. Optional evictSlotId frees an occupied target (a locked occupant refuses eviction before any engine call)',
                requestBody: $this->jsonBody([
                    'type' => 'object',
                    'required' => ['dayOfWeek', 'startTime', 'venueId'],
                    'properties' => [
                        'dayOfWeek' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 7],
                        'startTime' => ['type' => 'string', 'example' => '20:00'],
                        'venueId' => ['type' => 'string'],
                        'evictSlotId' => ['type' => 'string', 'nullable' => true, 'description' => 'Optional: id of the slot occupying the target, to evict (deleted in the same transaction) once the move is accepted'],
                        'dryRun' => $dryRunProperty,
                    ],
                ]),
            )),
            '/api/schedules/{id}/place-slot' => new PathItem(post: new Operation(
                operationId: 'postSchedulePlaceSlot',
                tags: ['ManualEdit'],
                responses: [
                    '200' => $this->jsonResponse('Placement accepted by the solver and written (schedule flagged manually edited, score now stale) — OR a dryRun essai (valid may be false, nothing created). Both carry the named comfort trade-offs', [
                        'type' => 'object',
                        'properties' => [
                            'valid' => ['type' => 'boolean'],
                            'dryRun' => $dryRunProperty,
                            'slotId' => ['type' => 'string', 'nullable' => true, 'description' => 'Id of the newly created (unlocked) slot; absent on a dryRun essai'],
                            'violations' => array_merge($dryViolations, ['nullable' => true, 'description' => 'Present on a dryRun essai that the solver refused (valid=false); absent on a written placement']),
                            'compromises' => $compromises,
                        ],
                    ]),
                    '400' => new Response('Missing or invalid field (teamId, dayOfWeek, startTime, venueId; durationMinutes if present must be a positive integer)'),
                    '404' => new Response('Schedule not found'),
                    '409' => new Response('Schedule is validated (read-only), or a generation is running for the club (body carries code=generation_in_progress)'),
                    '422' => $this->jsonResponse('Refused with nothing written: unknown team for this schedule, OR no venue window at that slot (code=slot_unavailable), OR the asserted durationMinutes contradicts the venue window (code=duration_mismatch), OR the solver refused the placement (broken rules named for display)', [
                        'type' => 'object',
                        'properties' => [
                            'valid' => ['type' => 'boolean', 'enum' => [false]],
                            'code' => ['type' => 'string', 'nullable' => true, 'description' => 'slot_unavailable or duration_mismatch when a server pre-check refused (no engine call)'],
                            'error' => ['type' => 'string', 'nullable' => true, 'description' => 'Human message for an unknown team or a server pre-check refusal'],
                            'violations' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                                'rule' => ['type' => 'string', 'description' => 'Machine code of the broken rule (UI branches on it)'],
                                'message' => ['type' => 'string', 'description' => 'Human sentence naming the coach/venue/time in conflict'],
                                'teamId' => ['type' => 'string', 'nullable' => true],
                                'coachId' => ['type' => 'string', 'nullable' => true],
                                'venueId' => ['type' => 'string', 'nullable' => true],
                                'dayOfWeek' => ['type' => 'integer', 'nullable' => true],
                                'startTime' => ['type' => 'string', 'nullable' => true],
                                'conflictingTeamId' => ['type' => 'string', 'nullable' => true],
                            ]]],
                        ],
                    ]),
                    '502' => new Response('Engine unreachable — nothing was written, retry'),
                    '504' => $this->jsonResponse('The engine answered too slowly and the verdict was dropped — nothing was written. Distinct from 502: the engine works, it merely exceeded the transport ceiling. Retrying is the right move (body carries code=engine_timeout)', [
                        'type' => 'object',
                        'properties' => [
                            'code' => ['type' => 'string', 'enum' => ['engine_timeout']],
                            'error' => ['type' => 'string', 'description' => 'Human message naming the timeout'],
                        ],
                    ]),
                ],
                summary: 'Place a NEW slot (a drift session, e.g. a make-up) under the solver verdict: created only if the engine accepts it. No count guard — the verdict is the sole judge; the slot is created unlocked. Its duration is the venue window\'s, resolved server-side; durationMinutes in the body is only an assertion',
                requestBody: $this->jsonBody([
                    'type' => 'object',
                    'required' => ['teamId', 'dayOfWeek', 'startTime', 'venueId'],
                    'properties' => [
                        'teamId' => ['type' => 'string'],
                        'dayOfWeek' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 7],
                        'startTime' => ['type' => 'string', 'example' => '20:00'],
                        'venueId' => ['type' => 'string'],
                        'durationMinutes' => ['type' => 'integer', 'minimum' => 1, 'example' => 90, 'description' => 'Optional assertion: the persisted duration is always the venue window\'s (resolved server-side). If provided and it differs from the window, the request is refused with code=duration_mismatch'],
                        'dryRun' => $dryRunProperty,
                    ],
                ]),
            )),
        ];
    }

    /**
     * P5-12 — le journal de nouveautés côté membre (lecture des notes publiées +
     * marquage « vu »). `release_note` est GLOBALE ; seule la surface est décrite.
     *
     * @return array<string, PathItem>
     */
    private function releaseNotePaths(): array
    {
        return [
            '/api/release-notes' => new PathItem(get: new Operation(
                operationId: 'getReleaseNotes',
                tags: ['ReleaseNotes'],
                responses: [
                    '200' => $this->jsonResponse('Published release notes (drafts excluded), newest first, plus how far the member has read', [
                        'type' => 'object',
                        'properties' => [
                            // ISO instant up to which the member marked the journal read, or null (never marked).
                            'seenUpTo' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                            'items' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                                'id' => ['type' => 'string'],
                                // Editorial date (antedatable) — display only, never the "what's new" gate.
                                'date' => ['type' => 'string', 'format' => 'date'],
                                'title' => ['type' => 'string'],
                                'body' => ['type' => 'string'],
                                // Publication instant — drives the "what's new" modal (publishedAt > seenUpTo).
                                'publishedAt' => ['type' => 'string', 'format' => 'date-time'],
                            ]]],
                        ],
                    ]),
                    '401' => new Response('Unauthorized'),
                ],
                summary: 'Read the published release notes and the member read watermark',
            )),
            '/api/release-notes/seen' => new PathItem(post: new Operation(
                operationId: 'markReleaseNotesSeen',
                tags: ['ReleaseNotes'],
                responses: [
                    '204' => new Response('Read watermark set to now (self-only) — no body'),
                    '401' => new Response('Unauthorized'),
                ],
                summary: 'Mark the release-notes journal read up to now (self-only)',
            )),
        ];
    }

    /**
     * Member feedback channel: any authenticated member reports a bug, a missing
     * constraint or an idea. Heavy context (a named schedule's snapshot +
     * diagnostics) is copied server-side under the tenant filter.
     *
     * @return array<string, PathItem>
     */
    private function feedbackPaths(): array
    {
        return [
            '/api/feedback' => new PathItem(post: new Operation(
                operationId: 'submitFeedback',
                tags: ['Feedback'],
                responses: [
                    '201' => $this->jsonResponse('Feedback stored; an acknowledgement email is queued to the author', [
                        'type' => 'object',
                        'properties' => ['id' => ['type' => 'string', 'format' => 'uuid']],
                    ]),
                    '401' => new Response('Unauthorized'),
                    '404' => new Response('No club in context, or the referenced schedule is unknown/foreign (nothing is stored)'),
                    '422' => new Response('Invalid topic, empty message, or message too long'),
                    '429' => new Response('Too many feedback submissions (per-user rate limit)'),
                ],
                summary: 'Submit a member feedback report (bug, missing constraint or idea)',
                requestBody: $this->jsonBody([
                    'type' => 'object',
                    'required' => ['topic', 'message'],
                    'properties' => [
                        'topic' => ['type' => 'string', 'enum' => ['bug', 'missing_constraint', 'idea']],
                        'message' => ['type' => 'string', 'maxLength' => 5000],
                        // Optional light context. When `scheduleId` names a schedule of
                        // the current club, the server copies its snapshot + diagnostics
                        // into the stored context; a foreign/unknown id is a 404.
                        'context' => ['type' => 'object', 'properties' => [
                            'screen' => ['type' => 'string'],
                            'url' => ['type' => 'string'],
                            'scheduleId' => ['type' => 'string', 'format' => 'uuid'],
                            'requestId' => ['type' => 'string'],
                            'userAgent' => ['type' => 'string'],
                        ]],
                    ],
                ]),
            )),
        ];
    }

    /**
     * P5-12 — l'atelier superadmin du journal : lister (brouillons inclus), créer,
     * éditer, publier, supprimer. Écritures protégées par CSRF (X-CSRF-Token).
     *
     * @return array<string, PathItem>
     */
    private function adminReleaseNotePaths(): array
    {
        $csrfHeader = ['name' => 'X-CSRF-Token', 'in' => 'header', 'required' => true, 'schema' => ['type' => 'string']];
        $noteSchema = [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'string', 'format' => 'uuid'],
                'title' => ['type' => 'string'],
                'body' => ['type' => 'string'],
                'date' => ['type' => 'string', 'format' => 'date'],
                'publishedAt' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                'createdAt' => ['type' => 'string', 'format' => 'date-time'],
            ],
        ];
        $writeBody = $this->jsonBody([
            'type' => 'object',
            'required' => ['title', 'body', 'noteDate'],
            'properties' => [
                'title' => ['type' => 'string', 'maxLength' => 160],
                'body' => ['type' => 'string'],
                'noteDate' => ['type' => 'string', 'format' => 'date', 'description' => 'Editorial date (YYYY-MM-DD), antedatable'],
            ],
        ]);
        $idParameter = ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'format' => 'uuid']];

        return [
            '/api/admin/release-notes' => new PathItem(
                get: new Operation(
                    operationId: 'getAdminReleaseNotes',
                    tags: ['AdminReleaseNotes'],
                    responses: [
                        '200' => $this->jsonResponse('Every release note, drafts included, newest editorial date first', [
                            'type' => 'object',
                            'properties' => ['items' => ['type' => 'array', 'items' => $noteSchema]],
                        ]),
                        '401' => new Response('No authenticated super-admin session'),
                    ],
                    summary: 'List every release note (drafts included)',
                ),
                post: new Operation(
                    operationId: 'createAdminReleaseNote',
                    tags: ['AdminReleaseNotes'],
                    responses: [
                        '201' => $this->jsonResponse('Release note created (as a draft — publishedAt null)', $noteSchema),
                        '400' => new Response('Validation error (title required ≤160, body required, invalid date)'),
                        '401' => new Response('No authenticated super-admin session'),
                        '403' => new Response('Invalid CSRF token'),
                    ],
                    summary: 'Create a release note (draft)',
                    parameters: [$csrfHeader],
                    requestBody: $writeBody,
                ),
            ),
            '/api/admin/release-notes/{id}' => new PathItem(
                delete: new Operation(
                    operationId: 'deleteAdminReleaseNote',
                    tags: ['AdminReleaseNotes'],
                    responses: [
                        '204' => new Response('Release note deleted — no body'),
                        '401' => new Response('No authenticated super-admin session'),
                        '403' => new Response('Invalid CSRF token'),
                        '404' => new Response('Note not found'),
                    ],
                    summary: 'Delete a release note',
                    parameters: [$idParameter, $csrfHeader],
                ),
                patch: new Operation(
                    operationId: 'updateAdminReleaseNote',
                    tags: ['AdminReleaseNotes'],
                    responses: [
                        '200' => $this->jsonResponse('Release note updated', $noteSchema),
                        '400' => new Response('Validation error'),
                        '401' => new Response('No authenticated super-admin session'),
                        '403' => new Response('Invalid CSRF token'),
                        '404' => new Response('Note not found'),
                    ],
                    summary: 'Update a release note (title, body, editorial date)',
                    parameters: [$idParameter, $csrfHeader],
                    requestBody: $writeBody,
                ),
            ),
            '/api/admin/release-notes/{id}/publish' => new PathItem(post: new Operation(
                operationId: 'publishAdminReleaseNote',
                tags: ['AdminReleaseNotes'],
                responses: [
                    '200' => $this->jsonResponse('Release note published (publishedAt set to now)', $noteSchema),
                    '401' => new Response('No authenticated super-admin session'),
                    '403' => new Response('Invalid CSRF token'),
                    '404' => new Response('Note not found'),
                    '409' => new Response('The note is already published'),
                ],
                summary: 'Publish a release note (makes it visible to members)',
                parameters: [$idParameter, $csrfHeader],
            )),
        ];
    }

    /**
     * P5-6 — le rail superadmin du canal de signalement : lister (cross-tenant, +
     * QoS), lire le détail (contexte lourd inclus), marquer traité / non-traité.
     * Écritures protégées par CSRF (X-CSRF-Token).
     *
     * @return array<string, PathItem>
     */
    private function adminFeedbackPaths(): array
    {
        $csrfHeader = ['name' => 'X-CSRF-Token', 'in' => 'header', 'required' => true, 'schema' => ['type' => 'string']];
        $idParameter = ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'format' => 'uuid']];
        $listItemSchema = [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'string', 'format' => 'uuid'],
                'clubId' => ['type' => 'string', 'format' => 'uuid'],
                'clubName' => ['type' => ['string', 'null']],
                'topic' => ['type' => 'string'],
                'message' => ['type' => 'string'],
                'createdAt' => ['type' => 'string', 'format' => 'date-time'],
                'status' => ['type' => 'string', 'enum' => ['untreated', 'treated']],
                'treatedAt' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                // The heavy context (schedule snapshot + diagnostics) is NOT returned
                // by the list — only a flag; read the detail to get it.
                'hasHeavyContext' => ['type' => 'boolean'],
            ],
        ];
        $qosSchema = [
            'type' => 'object',
            'properties' => [
                'treatDelayByMonth' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                    'month' => ['type' => 'string', 'description' => 'YYYY-MM'],
                    'avgHours' => ['type' => 'number', 'nullable' => true],
                    'p95Hours' => ['type' => 'number', 'nullable' => true],
                ]]],
                'volumeByTopicMonth' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                    'month' => ['type' => 'string', 'description' => 'YYYY-MM'],
                    'topic' => ['type' => 'string'],
                    'count' => ['type' => 'integer'],
                ]]],
                'treatedShare' => ['type' => 'number', 'description' => 'Treated fraction over all reports (0 when none)'],
                'oldestUntreatedAgeHours' => ['type' => 'number', 'nullable' => true],
            ],
        ];
        $writeResult = [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'string', 'format' => 'uuid'],
                'status' => ['type' => 'string', 'enum' => ['untreated', 'treated']],
                'treatedAt' => ['type' => ['string', 'null'], 'format' => 'date-time'],
            ],
        ];

        return [
            '/api/admin/feedback' => new PathItem(get: new Operation(
                operationId: 'getAdminFeedback',
                tags: ['AdminSupport'],
                responses: [
                    '200' => $this->jsonResponse('Paginated cross-tenant feedback list (heavy context excluded) with a quality-of-service block', [
                        'type' => 'object',
                        'properties' => [
                            'items' => ['type' => 'array', 'items' => $listItemSchema],
                            'pagination' => ['type' => 'object', 'properties' => [
                                'page' => ['type' => 'integer'],
                                'limit' => ['type' => 'integer'],
                                'total' => ['type' => 'integer'],
                                'pages' => ['type' => 'integer'],
                            ]],
                            'qos' => $qosSchema,
                        ],
                    ]),
                    '400' => new Response('Invalid pagination or status filter'),
                    '401' => new Response('No authenticated super-admin session'),
                ],
                summary: 'List every club feedback report (newest first) with treatment quality-of-service aggregates',
                parameters: [
                    ['name' => 'page', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer', 'minimum' => 1, 'default' => 1]],
                    ['name' => 'limit', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 25]],
                    ['name' => 'status', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string', 'enum' => ['untreated', 'treated']]],
                ],
            )),
            '/api/admin/feedback/{id}' => new PathItem(get: new Operation(
                operationId: 'getAdminFeedbackDetail',
                tags: ['AdminSupport'],
                responses: [
                    '200' => $this->jsonResponse('One feedback report with its full stored context (schedule snapshot + diagnostics when present)', [
                        ...$listItemSchema,
                        'properties' => [
                            ...$listItemSchema['properties'],
                            'context' => ['type' => ['object', 'null'], 'description' => 'Full stored context: light fields + the server-copied schedule snapshot and diagnostics when a schedule was named'],
                        ],
                    ]),
                    '401' => new Response('No authenticated super-admin session'),
                    '404' => new Response('Feedback not found'),
                ],
                summary: 'Read one feedback report in full (heavy context included)',
                parameters: [$idParameter],
            )),
            '/api/admin/feedback/{id}/treat' => new PathItem(post: new Operation(
                operationId: 'treatAdminFeedback',
                tags: ['AdminSupport'],
                responses: [
                    '200' => $this->jsonResponse('Marked treated (treatedAt set to now); a "handled + thank you" email is queued to the author when still reachable', $writeResult),
                    '401' => new Response('No authenticated super-admin session'),
                    '403' => new Response('Invalid CSRF token'),
                    '404' => new Response('Feedback not found'),
                    '409' => new Response('The feedback is already treated'),
                ],
                summary: 'Mark a feedback report treated (emails the author)',
                parameters: [$idParameter, $csrfHeader],
            )),
            '/api/admin/feedback/{id}/untreat' => new PathItem(post: new Operation(
                operationId: 'untreatAdminFeedback',
                tags: ['AdminSupport'],
                responses: [
                    '200' => $this->jsonResponse('Reverted to untreated (treatedAt cleared); no email is sent', $writeResult),
                    '401' => new Response('No authenticated super-admin session'),
                    '403' => new Response('Invalid CSRF token'),
                    '404' => new Response('Feedback not found'),
                    '409' => new Response('The feedback is not treated'),
                ],
                summary: 'Revert a feedback report to untreated (no email)',
                parameters: [$idParameter, $csrfHeader],
            )),
        ];
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function jsonBody(array $schema): RequestBody
    {
        return new RequestBody(content: new ArrayObject([
            'application/json' => ['schema' => $schema],
        ]));
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function jsonResponse(string $description, array $schema): Response
    {
        return new Response($description, new ArrayObject([
            'application/json' => ['schema' => $schema],
        ]));
    }
}
