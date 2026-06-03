<?php

declare(strict_types=1);

namespace Drupal\moderation_helpers\EventSubscriber;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Redirects /node/{nid}/latest to /node/{nid} when access is denied.
 *
 * This handles the case where an authenticated user visits the "latest version"
 * tab but no forward revision under moderation exists.
 */
class LatestNodeRedirectSubscriber implements EventSubscriberInterface {

  public function __construct(
    protected readonly AccountInterface $currentUser,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      KernelEvents::EXCEPTION => 'onException',
    ];
  }

  /**
   * Redirects access-denied on /node/{nid}/latest to the canonical page.
   */
  public function onException(ExceptionEvent $event): void {
    $request = $event->getRequest();
    $exception = $event->getThrowable();

    if (!$exception instanceof AccessDeniedHttpException) {
      return;
    }

    if ($this->currentUser->isAnonymous()) {
      return;
    }

    $path = $request->getPathInfo();
    if (!preg_match('#^/node/(\d+)/latest$#', $path, $matches)) {
      return;
    }

    $nid = $matches[1];
    $url = Url::fromRoute('entity.node.canonical', ['node' => $nid])->toString();
    $event->setResponse(new RedirectResponse($url));
  }

}
