<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Point d'entrée HTTP pour json_login : le firewall intercepte POST avant ce contrôleur.
 */
final class ApiLoginController
{
    #[Route('/api/login', name: 'api_login', methods: ['POST'])]
    public function __invoke(): never
    {
        throw new \LogicException('Authentification gérée par le firewall security (json_login).');
    }
}
