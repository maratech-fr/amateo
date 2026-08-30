<?php

declare(strict_types=1);

namespace App\OpenApi\PathContributor;

use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\PathItem;
use ApiPlatform\OpenApi\Model\Paths;
use ApiPlatform\OpenApi\Model\Response;
use App\OpenApi\CustomPathContributor;
use App\OpenApi\OpenApiSchemas;
use ArrayObject;

/**
 * Auth, account and session custom routes: registration + email verification,
 * `/api/me` (profile, email change, password, GDPR export), login/logout, the
 * Mercure authorization cookie and the club export.
 */
final readonly class AccountSessionPaths implements CustomPathContributor
{
    public function __construct(private OpenApiSchemas $schemas) {}

    public function contribute(Paths $paths): void
    {
        $paths->addPath('/api/register', new PathItem(post: new Operation(
            operationId: 'postApiRegister',
            tags: ['Auth'],
            responses: [
                // A3: never authenticates and never reveals account existence — an identical
                // 202 for a fresh or an already-registered email. The JWT is issued only by
                // /api/register/verify once the emailed link is followed.
                '202' => $this->schemas->jsonResponse('Verification pending — an email was sent (identical for a fresh or an already-registered address)', [
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
            requestBody: $this->schemas->jsonBody([
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
                '200' => $this->schemas->jsonResponse('Public registration configuration', [
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
                '200' => $this->schemas->jsonResponse('Verified — materialises the club and logs in (the JWT is set as the httpOnly BEARER cookie, NOT returned here)', [
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
            requestBody: $this->schemas->jsonBody([
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
                    '200' => $this->schemas->jsonResponse('Account anonymized (RGPD erasure)', [
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
                requestBody: $this->schemas->jsonBody([
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
                    '200' => $this->schemas->jsonResponse('Updated profile', [
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
                requestBody: $this->schemas->jsonBody([
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
                    '200' => $this->schemas->jsonResponse('Confirmation link sent to the new address; the current address stays active until confirmed', [
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
                requestBody: $this->schemas->jsonBody([
                    'type' => 'object',
                    'required' => ['email'],
                    'properties' => ['email' => ['type' => 'string', 'format' => 'email']],
                ]),
            ),
            delete: new Operation(
                operationId: 'deleteApiMeEmail',
                tags: ['Auth'],
                responses: [
                    '200' => $this->schemas->jsonResponse('Pending e-mail change cancelled', [
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
                '200' => $this->schemas->jsonResponse('E-mail switched; a fresh httpOnly JWT cookie is set for the new identity (the account stays verified/connectable)', [
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
            requestBody: $this->schemas->jsonBody([
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
            requestBody: $this->schemas->jsonBody([
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
                '200' => $this->schemas->jsonResponse('Session ended: the auth cookie is cleared server-side', [
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
                '200' => $this->schemas->jsonResponse('Mercure subscriber JWT set as an httpOnly cookie (path /.well-known/mercure); the body exposes the topic template to subscribe to', [
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
            requestBody: $this->schemas->jsonBody([
                'type' => 'object',
                'required' => ['currentPassword', 'newPassword'],
                'properties' => [
                    'currentPassword' => ['type' => 'string'],
                    'newPassword' => ['type' => 'string', 'minLength' => 8],
                ],
            ]),
        )));
    }
}
