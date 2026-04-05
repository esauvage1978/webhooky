<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccountStatusException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;

final class LoginFailureHandler implements AuthenticationFailureHandlerInterface
{
    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        $prev = $exception->getPrevious();
        if ($prev instanceof AccountStatusException) {
            return new JsonResponse(
                ['error' => $prev->getMessage()],
                Response::HTTP_FORBIDDEN,
            );
        }

        if ($exception instanceof AccountStatusException) {
            return new JsonResponse(
                ['error' => $exception->getMessage()],
                Response::HTTP_FORBIDDEN,
            );
        }

        return new JsonResponse(
            ['error' => 'Identifiants invalides'],
            Response::HTTP_UNAUTHORIZED,
        );
    }
}
