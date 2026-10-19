<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Test\Unit\Console;

use PHPUnit\Framework\TestCase;

/**
 * Magento's CommandList builds every registered command on each bin/magento run - setup:install on an empty database
 * too, where there is no website yet. So a command's constructor may build nothing that reads store or session
 * config: etc/di.xml gives each command its dependencies as proxies, built only when the command runs.
 */
class CommandListProxyTest extends TestCase
{
    private \DOMXPath $di;

    protected function setUp(): void
    {
        $document = new \DOMDocument();
        $document->load(__DIR__ . '/../../../etc/di.xml');
        $this->di = new \DOMXPath($document);
    }

    /** @return string[] the command classes etc/di.xml adds to Magento's CommandList */
    private function commands(): array
    {
        $items = $this->di->query('//type[@name="Magento\Framework\Console\CommandList"]'
            . '/arguments/argument[@name="commands"]/item');
        return array_map(fn (\DOMElement $item) => trim($item->textContent), iterator_to_array($items));
    }

    public function testEveryCommandGetsItsDependenciesAsProxies(): void
    {
        $commands = $this->commands();
        $this->assertNotEmpty($commands, 'the module registers commands');
        foreach ($commands as $command) {
            $constructor = (new \ReflectionClass($command))->getConstructor();
            foreach ($constructor ? $constructor->getParameters() : [] as $parameter) {
                $type = $parameter->getType();
                if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
                    continue;
                }
                $argument = $this->di->query(sprintf('//type[@name="%s"]/arguments/argument[@name="%s"]', $command,
                    $parameter->getName()))->item(0);
                $this->assertNotNull($argument, sprintf('%s $%s: no argument in etc/di.xml', $command,
                    $parameter->getName()));
                $this->assertSame($type->getName() . '\Proxy', trim($argument->textContent),
                    sprintf('%s $%s is built with the command, on every bin/magento run', $command, $parameter->getName()));
            }
        }
    }
}
