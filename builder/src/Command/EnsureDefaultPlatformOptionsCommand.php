<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Option;
use App\Repository\OptionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:ensure-default-platform-options',
    description: 'Ajoute ou met à jour les options plateforme par défaut (catégorie « Options », option_category / option_domaine).',
)]
final class EnsureDefaultPlatformOptionsCommand extends Command
{
    private const CATEGORY_OPTIONS = 'Options';
    private const CATEGORY_HOOK = 'Hook';
    private const CATEGORY_ERROR = 'Error';
    private const CATEGORY_USERS = 'Users';
    private const CATEGORY_SEO = 'SEO';

    private const DOMAINE = 'WebHooky';

    /**
     * Origines CORS (une entrée = un site) ; consommées par {@see \App\FormWebhook\FormWebhookCorsOriginsResolver}.
     *
     * @var list<string>
     */
    private const DEFAULT_WEBHOOK_CORS_ORIGINS = [
        'https://webhooky.fr',
        'https://emmanuesauvage.fr',
        'https://robot-educatif.info',
        'https://alertjet.fr',
        'https://alertjet.builders',
        'https://1piece1ampoule.fr',
        'http://localhost:4321',
        'http://127.0.0.1:4321',
    ];

    /**
     * @return list<array{optionName: string, optionValue: string, createOnly?: bool, domain?: string, category?: string}>
     */
    private static function defaultRows(): array
    {
        return [
            [
                'optionName' => 'option_category',
                 'optionValue' => self::CATEGORY_OPTIONS . ';' . self::CATEGORY_HOOK . ';' . self::CATEGORY_ERROR . ';' . self::CATEGORY_USERS . ';' . self::CATEGORY_SEO,
                 'category' => self::CATEGORY_OPTIONS,
                 'domain' => self::DOMAINE
            ],
            [
                'optionName' => 'option_domaine',
                'optionValue' => self::DOMAINE,
                'domain' => self::DOMAINE,
                'category' => self::CATEGORY_OPTIONS
            ],
            [
                'optionName' => 'webhooky_public_url',
                'optionValue' => 'https://webhooky.builders',
                'domain' => self::DOMAINE,
                'category' => self::CATEGORY_HOOK,
            ],
            [
                'optionName' => 'webhooky_contact_from',
                'optionValue' => 'contact@webhooky.builders',
                'domain' => self::DOMAINE,
                'category' => self::CATEGORY_HOOK,
            ],
            [
                'optionName' => 'webhooky_register_verify_webhook_url',
                'optionValue' => 'https://webhooky.builders/webhook/form/c6b74536-bac5-4442-b250-a9d3b1085002',
                'domain' => self::DOMAINE,
                'category' => self::CATEGORY_USERS,
            ],
            [
                'optionName' => 'webhooky_user_invite_webhook_url',
                'optionValue' => 'https://webhooky.builders/webhook/form/8a5aed88-22ac-4c00-955f-357410595f1b',
                'domain' => self::DOMAINE,
                'category' => self::CATEGORY_USERS,
            ],
            [
                'optionName' => 'webhooky_error_notify_webhook_url',
                'optionValue' => 'https://webhooky.builders/webhook/form/ed1f0664-9437-489d-9579-31bda85b8d92',
                'createOnly' => true,
                'domain' => self::DOMAINE,
                'category' => self::CATEGORY_ERROR,
            ],
            [
                'optionName' => 'webhooky_error_notify_webhook_from',
                'optionValue' => 'notification@webhooky.builders',
                'createOnly' => true,
                'domain' => self::DOMAINE,
                'category' => self::CATEGORY_ERROR,
            ],
            [
                'optionName' => 'webhooky_webhook_cors_origins',
                'optionValue' => implode(';', self::DEFAULT_WEBHOOK_CORS_ORIGINS),
                'domain' => self::DOMAINE,
                'category' => self::CATEGORY_HOOK,
            ],
        ];
    }

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly OptionRepository $optionRepository,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $created = 0;
        $updated = 0;

        foreach (self::defaultRows() as $row) {
            $option = $this->optionRepository->findFirstByOptionName($row['optionName']);

            if ($option instanceof Option) {
                if (!empty($row['createOnly'])) {
                    continue;
                }
                if ($option->getOptionValue() !== $row['optionValue']) {
                    $option->setOptionValue($row['optionValue']);
                    ++$updated;
                }
                continue;
            }

            $option = (new Option())
                ->setCategory($row['category'] ?? self::CATEGORY_OPTIONS)
                ->setOptionName($row['optionName'])
                ->setOptionValue($row['optionValue'])
                ->setDomain($row['domain'] ?? null);
            $this->entityManager->persist($option);
            ++$created;
        }

        $this->entityManager->flush();

        $io->success(sprintf(
            'Options « %s » : %d créée(s), %d valeur(s) mise(s) à jour.',
            self::CATEGORY_OPTIONS,
            $created,
            $updated,
        ));

        return Command::SUCCESS;
    }
}
