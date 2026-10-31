<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\StockAlert\Test\Unit\Model;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Framework\ObjectManagerInterface;
use Magento\InventorySalesApi\Api\AreProductsSalableInterface;
use Magento\InventorySalesApi\Api\Data\IsProductSalableResultInterface;
use Magento\InventorySalesApi\Api\StockResolverInterface;
use Magento\InventoryApi\Api\Data\StockInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\Website;
use PHPUnit\Framework\TestCase;
use Zero\StockAlert\Model\Stock;

/**
 * Whether products can be bought now in a store view: one collection for
 * "enabled there and on its website", then one bulk salable question for
 * the website's stock; without the inventory modules, one product load per
 * enabled product.
 */
class StockTest extends TestCase
{
    /** @var list<array> collection builder calls */
    private array $collectionCalls = [];
    /** @var list<array> AreProductsSalable::execute() calls */
    private array $salableAsked = [];
    /** @var list<array> product loads */
    private array $loaded = [];
    private bool $withInventory = true;

    protected function setUp(): void
    {
        if (!interface_exists(AreProductsSalableInterface::class)) {
            self::markTestSkipped('Magento_InventorySalesApi is not installed');
        }
    }

    public function testBuyableAsksTheStockOnceForTheEnabledProducts(): void
    {
        $result = $this->stock([11])->buyable([11 => 'A', 12 => 'B'], 1);

        self::assertSame([11 => true, 12 => false], $result);
        self::assertSame([
            ['setFlag', 'has_stock_status_filter', true],
            ['setStoreId', 1],
            ['addIdFilter', [11, 12]],
            ['addWebsiteFilter', [3]],
            ['addAttributeToFilter', 'status', 1],
        ], $this->collectionCalls);
        self::assertSame([[['A'], 2]], $this->salableAsked, 'only the enabled product, in the website\'s stock');
        self::assertSame([], $this->loaded);
    }

    public function testWithoutTheBulkApiEachProductIsLoadedOnce(): void
    {
        $this->withInventory = false;

        $result = $this->stock([11, 12])->buyable([11 => 'A', 12 => 'B', 13 => 'C'], 1);

        self::assertSame([11 => true, 12 => false, 13 => false], $result);
        self::assertSame([[11, false, 1, true], [12, false, 1, true]], $this->loaded, 'the disabled product 13 is not loaded');
    }

    public function testNothingEnabledAsksNoStock(): void
    {
        self::assertSame([11 => false], $this->stock([])->buyable([11 => 'A'], 1));
        self::assertSame([], $this->salableAsked);
    }

    public function testNoProductsAskNothing(): void
    {
        self::assertSame([], $this->stock([11])->buyable([], 1));
        self::assertSame([], $this->collectionCalls);
    }

    /**
     * @param int[] $enabledIds what the collection finds
     */
    private function stock(array $enabledIds): Stock
    {
        $collection = $this->createStub(Collection::class);
        foreach (['setFlag', 'setStoreId', 'addIdFilter', 'addWebsiteFilter', 'addAttributeToFilter'] as $method) {
            $collection->method($method)->willReturnCallback(function (...$args) use ($method, $collection) {
                while ($args && end($args) === null) {
                    array_pop($args);
                }
                if ($method === 'addAttributeToFilter') {
                    $args = array_slice($args, 0, 2);
                }
                if ($method === 'addIdFilter') {
                    $args = array_slice($args, 0, 1);
                }
                $this->collectionCalls[] = [$method, ...$args];

                return $collection;
            });
        }
        $collection->method('getAllIds')->willReturn(array_map('strval', $enabledIds));
        $factory = $this->createStub(CollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        $store = $this->createStub(Store::class);
        $store->method('getWebsiteId')->willReturn(3);
        $website = $this->createStub(Website::class);
        $website->method('getCode')->willReturn('edge');
        $stores = $this->createStub(StoreManagerInterface::class);
        $stores->method('getStore')->willReturn($store);
        $stores->method('getWebsite')->willReturnCallback(static fn ($id = null) => $id === 3 ? $website : throw new \LogicException("website $id"));

        $stockRow = $this->createStub(StockInterface::class);
        $stockRow->method('getStockId')->willReturn(2);
        $resolver = $this->createStub(StockResolverInterface::class);
        $resolver->method('execute')->willReturnCallback(static fn ($type, $code) => $type === 'website' && $code === 'edge'
            ? $stockRow
            : throw new \LogicException("$type $code"));
        $salable = $this->createStub(AreProductsSalableInterface::class);
        $salable->method('execute')->willReturnCallback(function (array $skus, int $stockId) {
            $this->salableAsked[] = [$skus, $stockId];

            return array_map(function (string $sku) {
                $result = $this->createStub(IsProductSalableResultInterface::class);
                $result->method('getSku')->willReturn($sku);
                $result->method('isSalable')->willReturn($sku === 'A');

                return $result;
            }, $skus);
        });
        $objectManager = $this->createStub(ObjectManagerInterface::class);
        $objectManager->method('get')->willReturnCallback(fn (string $type) => match (true) {
            !$this->withInventory => throw new \ReflectionException("Class $type does not exist"),
            ltrim($type, '\\') === StockResolverInterface::class => $resolver,
            ltrim($type, '\\') === AreProductsSalableInterface::class => $salable,
            default => throw new \LogicException($type),
        });

        $products = $this->createStub(ProductRepositoryInterface::class);
        $products->method('getById')->willReturnCallback(function (...$args) {
            $this->loaded[] = $args;
            $product = $this->createStub(Product::class);
            $product->method('isSalable')->willReturn($args[0] === 11);

            return $product;
        });

        return new Stock($products, $stores, $factory, $objectManager);
    }
}
