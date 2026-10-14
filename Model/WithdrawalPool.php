<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Model;

use CommerceKings\Consent\Api\WithdrawalHandlerInterface;

/** Modules register named cleanup handlers by purpose through the handlers argument in di.xml. */
class WithdrawalPool
{
    /** @param array<string, array<string, WithdrawalHandlerInterface>> $handlers */
    public function __construct(private readonly array $handlers = [])
    {
        foreach ($handlers as $purposeHandlers) {
            foreach ($purposeHandlers as $name => $handler) {
                if (!$handler instanceof WithdrawalHandlerInterface || $name === '' || strlen((string)$name) > 80) {
                    throw new \InvalidArgumentException('Withdrawal handlers need a stable name and WithdrawalHandlerInterface.');
                }
            }
        }
    }

    /** @return string[] */
    public function getNames(string $purpose): array
    {
        return array_keys($this->handlers[$purpose] ?? []);
    }

    public function get(string $purpose, string $name): ?WithdrawalHandlerInterface
    {
        return $this->handlers[$purpose][$name] ?? null;
    }
}
