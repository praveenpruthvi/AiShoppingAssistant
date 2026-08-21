<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Setup\Patch\Data;

use Aavirbhava\AiShoppingAssistant\Api\Catalog\AttributeIndexingSelectionRepositoryInterface;
use Aavirbhava\AiShoppingAssistant\Api\Catalog\ProductAttributePolicyInterface;
use Aavirbhava\AiShoppingAssistant\Model\Config\Path;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Seeds aavirbhava_ai_attribute_indexing_selection with whatever this
 * install was already, implicitly, indexing before admin-controlled
 * attribute selection existed — the free-text `ai_shopping_assistant/
 * indexing/searchable_attribute_codes` field this task replaces. This
 * task must not silently drop coverage a merchant already relies on, so
 * this reads the REAL, live default-scope value at patch-apply time
 * (never a hardcoded snapshot of any one install's own data — this
 * module is composer-installable, and a different install may have a
 * different customized list), normalizes it the same way
 * ConfigurationReader::readIndexing() used to, and only ever seeds
 * `is_indexed = true` for a code that also survives the same
 * ProductAttributePolicyInterface denylist SearchableAttributeValueResolver
 * already independently re-checks at read time — a denylisted code was
 * never REALLY being indexed in effect, so it isn't seeded as if it was.
 *
 * A code with no row after this patch defaults to NOT indexed (see
 * AttributeIndexingSelectionRepositoryInterface::all()'s own docblock) —
 * this patch only needs to write the TRUE rows; it does not need to
 * enumerate and write `false` for every other attribute in the catalog.
 */
class SeedAttributeIndexingSelection implements DataPatchInterface
{
    /**
     * The exact default this module has shipped since Task 1 — kept
     * here (not read from Model\Config\ConfigurationReader, which no
     * longer has a searchable-attribute-list concept once this patch's
     * own feature replaces it) purely as this patch's own fallback for
     * an install that never had any value configured at all.
     *
     * @var list<string>
     */
    private const FALLBACK_CODES = ['manufacturer', 'color', 'size', 'material'];

    public function __construct(
        private readonly \Magento\Framework\Setup\ModuleDataSetupInterface $moduleDataSetup,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly ProductAttributePolicyInterface $attributePolicy,
        private readonly AttributeIndexingSelectionRepositoryInterface $repository
    ) {
    }

    public function apply(): void
    {
        $this->moduleDataSetup->getConnection()->startSetup();

        $codes = $this->currentlyIndexedCodes();

        if ($codes !== []) {
            $this->repository->setIndexed($codes, true);
        }

        $this->moduleDataSetup->getConnection()->endSetup();
    }

    /**
     * @return list<string>
     */
    private function currentlyIndexedCodes(): array
    {
        // The literal path of the free-text field this task removes —
        // no Path::* constant exists for it any more (removed alongside
        // the system.xml field itself), but this one-time migration
        // still needs to read whatever was stored there before it's gone.
        $raw = $this->scopeConfig->getValue(Path::PREFIX . 'indexing/searchable_attribute_codes');
        $raw = is_string($raw) ? trim($raw) : '';

        $codes = $raw === ''
            ? self::FALLBACK_CODES
            : array_filter(array_map('trim', explode(',', strtolower($raw))), static fn (string $c): bool => $c !== '');

        $allowed = [];
        foreach ($codes as $code) {
            if ($this->attributePolicy->isAllowed($code)) {
                $allowed[] = $code;
            }
        }

        return array_values(array_unique($allowed));
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
