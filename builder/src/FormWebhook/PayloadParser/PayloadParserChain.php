<?php

declare(strict_types=1);

namespace App\FormWebhook\PayloadParser;

use Symfony\Component\HttpFoundation\Request;

/**
 * Chaîne de parsers : premier compatible, sinon corps vide.
 */
final class PayloadParserChain
{
    /** @var list<PayloadParserInterface> */
    private array $parsers;

    /**
     * @param iterable<PayloadParserInterface> $parsers
     */
    public function __construct(iterable $parsers)
    {
        $list = $parsers instanceof \Traversable ? iterator_to_array($parsers, false) : $parsers;
        $this->parsers = array_values($list);
        usort($this->parsers, static fn (PayloadParserInterface $a, PayloadParserInterface $b) => $b->getPriority() <=> $a->getPriority());
    }

    /**
     * @return array<string, string>
     */
    public function parse(Request $request): array
    {
        foreach ($this->parsers as $parser) {
            if ($parser->supports($request)) {
                return $parser->parse($request);
            }
        }

        return [];
    }
}
