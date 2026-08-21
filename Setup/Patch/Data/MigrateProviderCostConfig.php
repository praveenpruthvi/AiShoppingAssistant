<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Setup\Patch\Data;

use Aavirbhava\AiShoppingAssistant\Api\Config\ProviderCostRepositoryInterface;
use Aavirbhava\AiShoppingAssistant\Model\Config\Path;
use Aavirbhava\AiShoppingAssistant\Model\Provider\ProviderIdentifiers;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Migrates Task 35's static, two-provider `provider_cost` system.xml
 * fields (openai/openai_compatible only) into the new, dynamic
 * `aavirbhava_ai_provider_cost` table this task replaces them with. Reads
 * the REAL, live default-scope values at patch-apply time (never a
 * hardcoded snapshot — this module is composer-installable, and a
 * different install may have real, non-zero pricing configured) and
 * migrates them exactly as found, including an explicit 0.0 — a real,
 * already-saved 0.0 is this install's genuine current value, not an
 * absent one, and must be preserved as such rather than silently dropped.
 *
 * Anthropic/xAI/Google (Task 40) have no prior static config to migrate
 * and are deliberately NOT seeded here with a guessed price — an absent
 * row already correctly defaults to 0.0 via ProviderCostConfigInterface,
 * matching this task's own "do not hardcode guessed real-world pricing"
 * requirement.
 *
 * The two `Path::PROVIDER_COST_*` constants this patch reads no longer
 * exist once this task's own system.xml removal ships alongside it, so
 * the literal paths are hardcoded here — the same one-time-migration
 * convention SeedAttributeIndexingSelection (Task 38) already
 * established for a removed field.
 */
class MigrateProviderCostConfig implements DataPatchInterface
{
    private const OLD_OPENAI_INPUT_PATH = Path::PREFIX . 'provider_cost/openai_price_per_1k_input_tokens';
    private const OLD_OPENAI_OUTPUT_PATH = Path::PREFIX . 'provider_cost/openai_price_per_1k_output_tokens';
    private const OLD_OPENAI_COMPATIBLE_INPUT_PATH
        = Path::PREFIX . 'provider_cost/openai_compatible_price_per_1k_input_tokens';
    private const OLD_OPENAI_COMPATIBLE_OUTPUT_PATH
        = Path::PREFIX . 'provider_cost/openai_compatible_price_per_1k_output_tokens';

    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly ProviderCostRepositoryInterface $repository
    ) {
    }

    public function apply(): void
    {
        $this->moduleDataSetup->getConnection()->startSetup();

        $this->repository->setPrice(
            ProviderIdentifiers::LLM_OPENAI,
            $this->readOldPrice(self::OLD_OPENAI_INPUT_PATH),
            $this->readOldPrice(self::OLD_OPENAI_OUTPUT_PATH)
        );

        $this->repository->setPrice(
            ProviderIdentifiers::LLM_OPENAI_COMPATIBLE,
            $this->readOldPrice(self::OLD_OPENAI_COMPATIBLE_INPUT_PATH),
            $this->readOldPrice(self::OLD_OPENAI_COMPATIBLE_OUTPUT_PATH)
        );

        $this->moduleDataSetup->getConnection()->endSetup();
    }

    private function readOldPrice(string $path): float
    {
        $raw = $this->scopeConfig->getValue($path);

        return is_numeric($raw) ? (float) $raw : 0.0;
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }
}
