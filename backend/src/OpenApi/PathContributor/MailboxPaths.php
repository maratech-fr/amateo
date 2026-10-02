<?php

declare(strict_types=1);

namespace App\OpenApi\PathContributor;

use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\PathItem;
use ApiPlatform\OpenApi\Model\Paths;
use ApiPlatform\OpenApi\Model\Response;
use App\OpenApi\CustomPathContributor;
use App\OpenApi\OpenApiSchemas;

/**
 * La « boîte aux lettres » d'un club à horloge simulée (P4-16) — e-mails interceptés, en
 * lecture seule. Deux routes Symfony tenant (`MailboxController`), hors API Platform, décrites
 * ici pour qu'elles entrent au contrat et au snapshot (EveryCustomRouteIsDocumentedTest).
 */
final readonly class MailboxPaths implements CustomPathContributor
{
    public function __construct(private OpenApiSchemas $schemas) {}

    public function contribute(Paths $paths): void
    {
        $summary = [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'string', 'format' => 'uuid'],
                'from' => ['type' => 'string'],
                'to' => ['type' => 'string'],
                'subject' => ['type' => 'string'],
                'simulatedDate' => ['type' => 'string', 'format' => 'date', 'description' => 'The club simulated day the message was boxed on.'],
                'createdAt' => ['type' => 'string', 'format' => 'date-time', 'description' => 'Real wall-clock write instant (sort order).'],
            ],
        ];

        $paths->addPath('/api/mailbox', new PathItem(get: new Operation(
            operationId: 'getMailbox',
            tags: ['Mailbox'],
            responses: [
                '200' => $this->schemas->jsonResponse('The intercepted e-mails of the current club, newest first, with a count for the nav badge. Empty for a club without a simulated clock.', [
                    'type' => 'object',
                    'properties' => [
                        'messages' => ['type' => 'array', 'items' => $summary],
                        'count' => ['type' => 'integer'],
                    ],
                ]),
                '401' => new Response('Unauthorized (missing/expired JWT)'),
                '403' => new Response('No club in context'),
            ],
            summary: 'List the current club intercepted e-mails (simulated clock)',
        )));

        $detail = $summary;
        $detail['properties']['bodyText'] = ['type' => ['string', 'null'], 'description' => 'Business text body, captured before the brand signature.'];
        $detail['properties']['bodyHtml'] = ['type' => ['string', 'null'], 'description' => 'HTML body when present (usually null at enqueue — the signature is added by the worker, which never runs for a boxed e-mail).'];

        $paths->addPath('/api/mailbox/{id}', new PathItem(get: new Operation(
            operationId: 'getMailboxMessage',
            tags: ['Mailbox'],
            responses: [
                '200' => $this->schemas->jsonResponse('One intercepted e-mail, with its body.', $detail),
                '401' => new Response('Unauthorized (missing/expired JWT)'),
                '403' => new Response('No club in context'),
                '404' => new Response('Unknown message, or it belongs to another club'),
            ],
            summary: 'Read one intercepted e-mail of the current club',
        )));
    }
}
