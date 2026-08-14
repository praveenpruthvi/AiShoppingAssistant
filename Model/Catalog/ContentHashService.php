<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Catalog;

use Aavirbhava\AiShoppingAssistant\Api\Catalog\ContentHashServiceInterface;
use Aavirbhava\AiShoppingAssistant\Model\Catalog\Exception\CatalogException;

/**
 * Deterministic SHA-256 hashing of normalized document payloads.
 *
 * Recursively sorts associative arrays (while preserving list order) before
 * JSON encoding with no escaping beyond what is required, so equivalent payloads
 * always hash identically regardless of key insertion order.
 */
final class ContentHashService implements ContentHashServiceInterface
{
    public function hash(array $data): string
    {
        try {
            $canonical = $this->canonicalize($data);
            $json = json_encode(
                $canonical,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
        } catch (\JsonException $exception) {
            throw new CatalogException(__('Unable to hash the product document payload.'), $exception);
        }

        return hash('sha256', $json);
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<mixed>
     */
    private function canonicalize(array $data): array
    {
        $canonical = [];

        foreach ($data as $key => $value) {
            $canonical[$key] = is_array($value) ? $this->canonicalize($value) : $value;
        }

        if (array_is_list($canonical)) {
            return array_values($canonical);
        }

        ksort($canonical);

        return $canonical;
    }
}
