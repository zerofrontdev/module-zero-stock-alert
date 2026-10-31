<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\StockAlert\Test\Unit\Model;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Catalog\Model\Product;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Mail\TransportInterface;
use Magento\Framework\Pricing\Helper\Data as PriceHelper;
use Magento\Framework\UrlInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Zero\StockAlert\Model\Alerts;
use Zero\StockAlert\Model\Notifier;
use Zero\StockAlert\Model\Stock;

/**
 * The cron reads only what can be bought: the products and store views
 * waited for, store views with the module off dropped before any stock
 * question, the stock asked once per store view for each 500 products, then
 * the alerts of the products that are back, up to the run's budget (sent
 * plus failed). Mail, emulation, markSent and the failure log as before.
 */
class NotifierTest extends TestCase
{
    /** @var list<array{product_id: int, store_id: int, website_id: int, sku: string}> */
    private array $pairs = [];
    /** @var array<string, list<array>> "product:store" => waiting alerts, oldest first */
    private array $waiting = [];
    /** @var array<int, array<int, bool>> store => product => buyable */
    private array $buyable = [];
    /** @var array<int, bool> store => module on (default on) */
    private array $enabled = [];
    /** @var list<array{0: array<int, string>, 1: int}> buyable() calls */
    private array $stockAsked = [];
    /** @var list<array{0: int, 1: int, 2: int}> waitingFor() calls */
    private array $alertsRead = [];
    /** @var list<int> */
    private array $marked = [];
    /** @var list<string> addresses mailed */
    private array $mailed = [];
    /** @var list<string> addresses whose mail fails */
    private array $failing = [];
    /** @var list<string> */
    private array $errors = [];
    /** @var list<string> start / stop */
    private array $emulation = [];

    public function testOnlyRestockedProductsAreMailedAndStockIsAskedOncePerStore(): void
    {
        $this->pair(11, 1, 'MB01');
        $this->pair(12, 1, 'MB02');
        $this->pair(11, 2, 'MB01');
        $this->alert(7, 11, 1, 'ann@example.com');
        $this->alert(8, 12, 1, 'bob@example.com');
        $this->alert(9, 11, 2, 'cat@example.com');
        $this->buyable = [1 => [11 => true, 12 => false], 2 => [11 => true]];

        self::assertSame(['sent' => 2, 'failed' => 0], $this->notifier()->sendDue());

        self::assertSame([[[11 => 'MB01', 12 => 'MB02'], 1], [[11 => 'MB01'], 2]], $this->stockAsked);
        self::assertSame([[11, 1, 1000], [11, 2, 999]], $this->alertsRead, 'the sold-out product\'s alerts are never read');
        self::assertSame(['ann@example.com', 'cat@example.com'], $this->mailed);
        self::assertSame([7, 9], $this->marked);
        self::assertSame(['start 1', 'stop', 'start 2', 'stop'], $this->emulation);
    }

    public function testOldSoldOutAlertsDoNotHoldBackARestockedProduct(): void
    {
        // 2001 old alerts wait for product 11 (sold out); one newer alert for 12 (back).
        $this->pair(11, 1, 'MB01');
        $this->pair(12, 1, 'MB02');
        for ($id = 1; $id <= 2001; $id++) {
            $this->alert($id, 11, 1, "old$id@example.com");
        }
        $this->alert(3000, 12, 1, 'new@example.com');
        $this->buyable = [1 => [11 => false, 12 => true]];

        self::assertSame(['sent' => 1, 'failed' => 0], $this->notifier()->sendDue());
        self::assertSame(['new@example.com'], $this->mailed);
        self::assertSame([[12, 1, 1000]], $this->alertsRead);
    }

    public function testAStoreWithTheModuleOffIsNotAsked(): void
    {
        $this->pair(11, 1, 'MB01');
        $this->pair(11, 2, 'MB01');
        $this->alert(7, 11, 1, 'ann@example.com');
        $this->alert(9, 11, 2, 'cat@example.com');
        $this->buyable = [1 => [11 => true], 2 => [11 => true]];
        $this->enabled = [2 => false];

        self::assertSame(['sent' => 1, 'failed' => 0], $this->notifier()->sendDue());
        self::assertSame([1], array_column($this->stockAsked, 1), 'store 2 is never asked for stock');
        self::assertSame(['ann@example.com'], $this->mailed);
    }

    public function testTheRunStopsAtItsBudget(): void
    {
        $this->pair(11, 1, 'MB01');
        $this->pair(12, 1, 'MB02');
        $this->pair(13, 2, 'MB03');
        $this->alert(7, 11, 1, 'a@example.com');
        $this->alert(8, 11, 1, 'b@example.com');
        $this->alert(9, 11, 1, 'c@example.com');
        $this->alert(10, 12, 1, 'd@example.com');
        $this->alert(11, 13, 2, 'e@example.com');
        $this->buyable = [1 => [11 => true, 12 => true], 2 => [13 => true]];

        self::assertSame(['sent' => 2, 'failed' => 0], $this->notifier(2)->sendDue());
        self::assertSame([[11, 1, 2]], $this->alertsRead, 'read up to the budget, then stopped');
        self::assertSame(['a@example.com', 'b@example.com'], $this->mailed);
        self::assertCount(1, $this->stockAsked, 'the next store is not asked');
    }

    public function testFailedMailsCountAgainstTheBudget(): void
    {
        $this->pair(11, 1, 'MB01');
        $this->pair(12, 1, 'MB02');
        $this->alert(7, 11, 1, 'a@example.com');
        $this->alert(8, 12, 1, 'b@example.com');
        $this->alert(9, 12, 1, 'c@example.com');
        $this->buyable = [1 => [11 => true, 12 => true]];
        $this->failing = ['a@example.com'];

        self::assertSame(['sent' => 1, 'failed' => 1], $this->notifier(2)->sendDue());
        self::assertSame([[11, 1, 2], [12, 1, 1]], $this->alertsRead);
        self::assertSame([8], $this->marked);
    }

    public function testStockIsAskedFor500ProductsAtATime(): void
    {
        for ($id = 1; $id <= 501; $id++) {
            $this->pair($id, 1, "SKU$id");
        }

        self::assertSame(['sent' => 0, 'failed' => 0], $this->notifier()->sendDue());

        self::assertCount(2, $this->stockAsked);
        self::assertCount(500, $this->stockAsked[0][0]);
        self::assertSame([501 => 'SKU501'], $this->stockAsked[1][0], 'product ids kept as keys');
        self::assertSame([], $this->alertsRead);
    }

    public function testNothingWaitingAsksNothing(): void
    {
        self::assertSame(['sent' => 0, 'failed' => 0], $this->notifier()->sendDue());
        self::assertSame([], $this->stockAsked);
    }

    public function testAFailedMailIsCountedLoggedAndLeftWaitingAndEmulationEnds(): void
    {
        $this->pair(11, 2, 'MB01');
        $this->alert(7, 11, 2, 'buyer@example.com');
        $this->buyable = [2 => [11 => true]];
        $this->failing = ['buyer@example.com'];

        self::assertSame(['sent' => 0, 'failed' => 1], $this->notifier()->sendDue());
        self::assertSame([], $this->marked);
        self::assertSame(['start 2', 'stop'], $this->emulation);
        self::assertSame(['Zero_StockAlert: alert 7: SES down'], $this->errors);
    }

    private function pair(int $productId, int $storeId, string $sku): void
    {
        $this->pairs[] = ['product_id' => $productId, 'store_id' => $storeId, 'website_id' => 1, 'sku' => $sku];
    }

    private function alert(int $id, int $productId, int $storeId, string $email): void
    {
        $this->waiting["$productId:$storeId"][] = [
            'alert_id' => (string) $id, 'product_id' => (string) $productId, 'parent_id' => null,
            'store_id' => (string) $storeId, 'email' => $email, 'token' => 'abc',
        ];
    }

    private function notifier(int $perRun = 1000): Notifier
    {
        $alerts = $this->createStub(Alerts::class);
        $alerts->method('waitingPairs')->willReturnCallback(fn () => $this->pairs);
        $alerts->method('waitingFor')->willReturnCallback(function (int $productId, int $storeId, int $limit) {
            $this->alertsRead[] = [$productId, $storeId, $limit];

            return array_slice($this->waiting["$productId:$storeId"] ?? [], 0, $limit);
        });
        $alerts->method('markSent')->willReturnCallback(function (int $id): void {
            $this->marked[] = $id;
        });
        $stock = $this->createStub(Stock::class);
        $stock->method('buyable')->willReturnCallback(function (array $skus, int $storeId) {
            $this->stockAsked[] = [$skus, $storeId];

            return array_intersect_key($this->buyable[$storeId] ?? [], $skus) + array_fill_keys(array_keys($skus), false);
        });
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('isSetFlag')->willReturnCallback(fn ($path, $scope, $storeId) => $this->enabled[(int) $storeId] ?? true);
        $scopeConfig->method('getValue')->willReturn('zero_stock_alert_general_template');
        $emulation = $this->createStub(Emulation::class);
        $emulation->method('startEnvironmentEmulation')->willReturnCallback(function ($storeId): void {
            $this->emulation[] = "start $storeId";
        });
        $emulation->method('stopEnvironmentEmulation')->willReturnCallback(function () use ($emulation) {
            $this->emulation[] = 'stop';

            return $emulation;
        });
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('error')->willReturnCallback(function ($message): void {
            $this->errors[] = $message;
        });

        $product = $this->createStub(Product::class);
        $product->method('getName')->willReturn('Mona Hoodie');
        $product->method('getProductUrl')->willReturn('https://example.com/mona.html');
        $product->method('getFinalPrice')->willReturn(49.0);
        $product->method('getId')->willReturn(11);
        $repository = $this->createStub(ProductRepositoryInterface::class);
        $repository->method('getById')->willReturn($product);

        $to = '';
        $transport = $this->createStub(TransportInterface::class);
        $transport->method('sendMessage')->willReturnCallback(function () use (&$to): void {
            if (in_array($to, $this->failing, true)) {
                throw new \RuntimeException('SES down');
            }
            $this->mailed[] = $to;
        });
        $builder = $this->createStub(TransportBuilder::class);
        foreach (['setTemplateIdentifier', 'setTemplateOptions', 'setTemplateVars', 'setFromByScope'] as $method) {
            $builder->method($method)->willReturnSelf();
        }
        $builder->method('addTo')->willReturnCallback(function ($address) use (&$to, $builder) {
            $to = $address;

            return $builder;
        });
        $builder->method('getTransport')->willReturn($transport);

        $image = $this->createStub(ImageHelper::class);
        $image->method('init')->willReturnSelf();
        $image->method('getUrl')->willReturn('https://example.com/mona.jpg');
        $price = $this->createStub(PriceHelper::class);
        $price->method('currency')->willReturn('$49.00');
        $url = $this->createStub(UrlInterface::class);
        $url->method('getUrl')->willReturn('https://example.com/stockalert/alert/cancel');
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($this->createStub(StoreInterface::class));

        return new Notifier(
            $alerts,
            $stock,
            $repository,
            $builder,
            $emulation,
            $scopeConfig,
            $url,
            $image,
            $price,
            $storeManager,
            $logger,
            $perRun
        );
    }
}
