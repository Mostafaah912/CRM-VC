<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Exceptions;

use RuntimeException;

/**
 * Woo's data cannot be mirrored without breaking a catalog invariant, and the spec defines no policy for
 * it (which of two variations owns a SKU; a Woo variation showing up under another product). The service
 * therefore refuses and writes nothing, rather than reassign or guess. `reason` is a stable code.
 */
final class CatalogIntegrityException extends RuntimeException
{
    public const SKU_CONFLICT = 'sku_conflict';

    public const VARIATION_PARENT_MISMATCH = 'variation_parent_mismatch';

    private function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function skuAlreadyOwned(int $wooVariationId, string $sku, int $ownerVariationId): self
    {
        return new self(self::SKU_CONFLICT, "Woo variation {$wooVariationId} wants SKU '{$sku}', which local variation {$ownerVariationId} already owns.");
    }

    public static function skuRepeatedInPayload(int $wooVariationId, string $sku): self
    {
        return new self(self::SKU_CONFLICT, "Woo variation {$wooVariationId} repeats SKU '{$sku}' already used earlier in the same product payload.");
    }

    public static function variationUnderAnotherProduct(int $wooVariationId, int $wooProductId): self
    {
        return new self(self::VARIATION_PARENT_MISMATCH, "Woo variation {$wooVariationId} already belongs to a different local product than Woo product {$wooProductId}.");
    }
}
