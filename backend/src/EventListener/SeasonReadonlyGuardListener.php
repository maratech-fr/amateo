<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Controller\SeasonScopedWriteInterface;
use App\Service\SeasonAccessGuard;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Refuses (409) a write to an archived season on the custom controllers marked
 * SeasonScopedWriteInterface — the counterpart of the SeasonAccessGuard call
 * in AbstractStateProcessor, which only covers API Platform mutations. Runs on
 * kernel.controller (after TenantFilterListener has stamped _season_readonly).
 * Every HTTP method of a marked controller is refused, GET included (e.g. the preview of the
 * conflicts mailing to coaches, ConflictCoachMailController::preview).
 */
final class SeasonReadonlyGuardListener implements EventSubscriberInterface
{
    public function __construct(
        private readonly SeasonAccessGuard $seasonAccessGuard,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::CONTROLLER => 'onKernelController'];
    }

    public function onKernelController(ControllerEvent $event): void
    {
        $controller = $event->getController();
        // Symfony passes invokable controllers as [object, '__invoke'] or the object itself.
        $instance = \is_array($controller) ? $controller[0] : $controller;

        if ($instance instanceof SeasonScopedWriteInterface) {
            $request = $event->getRequest();
            // SEC-13 : la saison de la CIBLE (résolue par le contrôleur hors filtres),
            // pas seulement celle du header — le garde prend la plus stricte des deux.
            $this->seasonAccessGuard->assertWritable($request, $instance->writeTargetSeasonId($request));
        }
    }
}
