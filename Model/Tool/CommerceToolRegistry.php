<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Tool;

use Aavirbhava\AiShoppingAssistant\Api\Tool\CommerceToolInterface;
use Aavirbhava\AiShoppingAssistant\Api\Tool\CommerceToolRegistryInterface;
use Aavirbhava\AiShoppingAssistant\Model\Tool\Exception\ToolNotFoundException;
use InvalidArgumentException;
use Magento\Framework\Phrase;

/**
 * Registry of commerce tools contributed through Magento DI.
 *
 * Mirrors Model\Provider\LlmProviderRegistry's validation shape: the DI
 * array key must be a syntactically valid name, the tool's own name()
 * must match it exactly, and every entry must implement
 * CommerceToolInterface. Installed Magento modules are trusted
 * application code; their DI contributions define the allowlist.
 */
final class CommerceToolRegistry implements CommerceToolRegistryInterface
{
    private const NAME_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/';

    /**
     * @var array<string, CommerceToolInterface>
     */
    private array $tools = [];

    /**
     * @param array<string, CommerceToolInterface> $tools
     */
    public function __construct(array $tools = [])
    {
        foreach ($tools as $name => $tool) {
            if (!is_string($name) || preg_match(self::NAME_PATTERN, $name) !== 1) {
                throw new InvalidArgumentException('Commerce tool keys must be valid lowercase snake_case names.');
            }

            if (!$tool instanceof CommerceToolInterface) {
                throw new InvalidArgumentException('A registered commerce tool does not implement CommerceToolInterface.');
            }

            if ($tool->name() !== $name) {
                throw new InvalidArgumentException('A registered commerce tool name does not match its declaration.');
            }

            $this->tools[$name] = $tool;
        }
    }

    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    public function get(string $name): CommerceToolInterface
    {
        if (!$this->has($name)) {
            throw new ToolNotFoundException(
                new Phrase('The requested tool is not available.')
            );
        }

        return $this->tools[$name];
    }

    public function all(): array
    {
        return $this->tools;
    }
}
