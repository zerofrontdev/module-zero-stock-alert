<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\StockAlert\Model;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Framework\ObjectManagerInterface;

/**
 * What can be waited for on a product page: the product itself when it can't
 * be bought, a configurable product's variants that can't (named by their
 * options, "M / Black").
 */
class Stock
{
    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly StoreManagerInterface $storeManager,
        private readonly CollectionFactory $collectionFactory,
        private readonly ObjectManagerInterface $objectManager
    ) {
    }

    /**
     * Whether it can be bought now in the store view (default: the current
     * one): Magento's own answer, so with multi-source inventory its
     * reservations count too, as on the product page.
     */
    public function isInStock(int $productId, ?int $storeId = null): bool
    {
        $storeId ??= (int) $this->storeManager->getStore()->getId();
        try {
            return (bool) $this->productRepository->getById($productId, false, $storeId, true)->isSalable();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array<int, string> Product id => what it is ("" for the product itself)
     */
    public function soldOut(Product $product): array
    {
        if ($product->getTypeId() !== Configurable::TYPE_CODE) {
            // The product the page loaded says it; isInStock() would load it a second time.
            return in_array($product->getTypeId(), ['simple', 'virtual'], true) && !$product->isSalable()
                ? [(int) $product->getId() => '']
                : [];
        }
        $all = array_filter($this->variants($product), static fn (array $variant) => $variant['enabled']);
        // One question for all the variants (multi-source inventory); without it, one product load each.
        $salable = $this->salable(array_column($all, 'sku'), null);
        $variants = [];
        foreach ($all as $id => $variant) {
            $inStock = $salable !== null ? ($salable[$variant['sku']] ?? false) : $this->isInStock($id);
            if (!$inStock) {
                $variants[$id] = $variant['label'];
            }
        }
        asort($variants, SORT_NATURAL);

        return $variants;
    }

    /**
     * Whether each product can be bought now in a store view: enabled there
     * and on its website (one collection), then salable in the website's stock
     * (one bulk question, reservations counted; without the inventory
     * modules, one product load each).
     *
     * @param array<int, string> $skus Product id => SKU
     * @return array<int, bool> Product id => can be bought
     */
    public function buyable(array $skus, int $storeId): array
    {
        $result = array_fill_keys(array_keys($skus), false);
        if (!$skus) {
            return $result;
        }
        $websiteId = (int) $this->storeManager->getStore($storeId)->getWebsiteId();
        $collection = $this->collectionFactory->create();
        // Sold-out products are wanted here; the storefront would hide them.
        $collection->setFlag('has_stock_status_filter', true);
        $collection->setStoreId($storeId);
        $collection->addIdFilter(array_keys($skus));
        $collection->addWebsiteFilter([$websiteId]);
        $collection->addAttributeToFilter('status', 1);
        $enabled = array_intersect_key($skus, array_flip(array_map('intval', $collection->getAllIds())));
        if (!$enabled) {
            return $result;
        }
        $salable = $this->salable(array_values($enabled), (string) $this->storeManager->getWebsite($websiteId)->getCode());
        foreach ($enabled as $productId => $sku) {
            $result[$productId] = $salable !== null ? ($salable[$sku] ?? false) : $this->isInStock((int) $productId, $storeId);
        }

        return $result;
    }

    /**
     * Whether each SKU can be bought now in a website's stock (default: the
     * current website), in one call (reservations counted, as on the product
     * page); null where the inventory modules' bulk API is not there.
     *
     * @param string[] $skus
     * @return array<string, bool>|null
     */
    private function salable(array $skus, ?string $websiteCode = null): ?array
    {
        $areSalable = '\\Magento\\InventorySalesApi\\Api\\AreProductsSalableInterface';
        $resolver = '\\Magento\\InventorySalesApi\\Api\\StockResolverInterface';
        if (!$skus || !interface_exists($areSalable) || !interface_exists($resolver)) {
            return null;
        }
        try {
            $websiteCode ??= (string) $this->storeManager->getWebsite()->getCode();
            $stockId = (int) $this->objectManager->get($resolver)->execute('website', $websiteCode)->getStockId();
            $salable = [];
            foreach ($this->objectManager->get($areSalable)->execute(array_values($skus), $stockId) as $result) {
                $salable[(string) $result->getSku()] = (bool) $result->isSalable();
            }

            return $salable;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * A configurable product's variant named by its options ("M / Black").
     */
    public function soldOutLabel(Product $product, int $variantId): ?string
    {
        return $product->getTypeId() === Configurable::TYPE_CODE ? ($this->variants($product)[$variantId]['label'] ?? null) : null;
    }

    /**
     * Every variant, sold out or not (Magento's own list of a configurable
     * product's variants leaves sold-out ones out), named by its options.
     *
     * @return array<int, array{label: string, enabled: bool, sku: string}>
     */
    private function variants(Product $product): array
    {
        /** @var Configurable $type */
        $type = $product->getTypeInstance();
        $ids = array_map('intval', array_values($type->getChildrenIds((int) $product->getId())[0] ?? []));
        if (!$ids) {
            return [];
        }
        $codes = [];
        foreach ($type->getConfigurableAttributes($product) as $attribute) {
            $codes[] = $attribute->getProductAttribute()->getAttributeCode();
        }
        $collection = $this->collectionFactory->create();
        // On the storefront Magento hides sold-out products from collections: these are wanted.
        $collection->setFlag('has_stock_status_filter', true);
        $collection->addIdFilter($ids)->addAttributeToSelect([...$codes, 'status']);
        $variants = [];
        foreach ($collection as $child) {
            $labels = array_map(static fn ($code) => (string) $child->getAttributeText($code), $codes);
            $variants[(int) $child->getId()] = [
                'label' => implode(' / ', array_filter($labels)),
                'enabled' => (int) $child->getStatus() === 1,
                'sku' => (string) $child->getSku(),
            ];
        }

        return $variants;
    }
}
