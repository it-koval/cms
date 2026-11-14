<?php

declare(strict_types=1);

namespace Itk\Cms\Controller\Admin;

use Itk\Cms\Admin\AdminPath;
use Itk\Cms\Content\ContentException;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Security\Access;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

/**
 * Every error of the admin API (/admin/api/*, wherever the admin stands) is JSON: { "error": "plain text in the language of the person", "details"?: … } with the HTTP code of the problem.
 * 401 = not signed in (the client sends the person to /admin/login), 403 = no right, 404, 409, 422 = a rule of the content model.
 *
 * @internal
 */
#[AsEventListener(event: KernelEvents::EXCEPTION, priority: 10)]
final class ApiErrorListener
{
    public function __construct(
        private readonly Access $access,
        private readonly AdminPath $admin,
        private readonly LoggerInterface $logger,
        #[Autowire(param: 'kernel.debug')]
        private readonly bool $debug,
    ) {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        if (!$this->admin->isApi($event->getRequest())) {
            return;
        }
        $e = $event->getThrowable();
        $details = null;

        if ($e instanceof ContentException) {
            $status = $e->status();
            $message = $e->getMessage();
            $details = $e->details();
        } elseif ($e instanceof AccessDeniedException) {
            $signedIn = null !== $this->access->user();
            $status = $signedIn ? Response::HTTP_FORBIDDEN : Response::HTTP_UNAUTHORIZED;
            $message = I18n::t($signedIn ? 'http.no_permission' : 'http.sign_in_required');
        } elseif ($e instanceof AuthenticationException) {
            $status = Response::HTTP_UNAUTHORIZED;
            $message = I18n::t('http.sign_in_required');
        } elseif ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();
            $message = match ($status) {
                404 => I18n::t('http.not_found'),
                405 => I18n::t('http.not_allowed'),
                401 => I18n::t('http.sign_in_required'),
                403 => I18n::t('http.no_permission'),
                default => $status >= 500 ? I18n::t('http.something_wrong') : ($e->getMessage() ?: I18n::t('http.request_failed')),
            };
        } else {
            $status = Response::HTTP_INTERNAL_SERVER_ERROR;
            $message = I18n::t('http.something_wrong');
            $this->logger->error('Admin API error: {message}', ['message' => $e->getMessage(), 'exception' => $e]);
            if ($this->debug) {
                $details = ['exception' => $e::class . ': ' . $e->getMessage()];
            }
        }
        if ($status < 400 || $status > 599) {
            $status = Response::HTTP_UNPROCESSABLE_ENTITY;
        }

        $body = ['error' => $message] + (null !== $details ? ['details' => $details] : []);
        $response = new JsonResponse($body, $status);
        $response->setEncodingOptions(\JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        $response->headers->set('Cache-Control', 'no-store');
        $event->setResponse($response);
    }
}
