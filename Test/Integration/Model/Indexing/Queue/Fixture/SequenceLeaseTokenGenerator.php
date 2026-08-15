<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Integration\Model\Indexing\Queue\Fixture;

use Aavirbhava\AiShoppingAssistant\Model\Indexing\Queue\LeaseTokenGeneratorInterface;

final class SequenceLeaseTokenGenerator implements LeaseTokenGeneratorInterface
{
    private string $next = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private int $counter = 0;

    public function generate(): string
    {
        if ($this->next !== '') {
            $token = $this->next;
            $this->next = '';

            return $token;
        }

        ++$this->counter;

        return str_pad((string)$this->counter, 64, 'b', STR_PAD_LEFT);
    }

    public function setNext(string $token): void
    {
        $this->next = $token;
    }
}
