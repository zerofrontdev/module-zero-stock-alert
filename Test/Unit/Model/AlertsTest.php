<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\StockAlert\Test\Unit\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\TestCase;
use Zero\StockAlert\Model\Alerts;

/**
 * What is waited for: (product, store view) pairs of waiting alerts on
 * active store views with the product's SKU, oldest wait first; then a
 * pair's waiting alerts, oldest first, up to a limit.
 */
class AlertsTest extends TestCase
{
    /** @var list<array> select builder calls */
    private array $select = [];
    private int $queries = 0;

    public function testWaitingPairsJoinActiveStoresAndTheSkuOldestFirst(): void
    {
        $pairs = $this->alerts([['product_id' => '11', 'store_id' => '2', 'website_id' => '1', 'sku' => 'MB01', 'first' => '4']])->waitingPairs();

        self::assertSame([['product_id' => 11, 'store_id' => 2, 'website_id' => 1, 'sku' => 'MB01']], $pairs);
        self::assertContains(['join', ['s' => 'store'], 's.store_id = a.store_id AND s.is_active = 1', ['website_id']], $this->select);
        self::assertContains(['join', ['p' => 'catalog_product_entity'], 'p.entity_id = a.product_id', ['sku']], $this->select);
        self::assertContains(['where', 'a.status = ?', Alerts::WAITING], $this->select);
        self::assertContains(['group', ['a.product_id', 'a.store_id', 's.website_id', 'p.sku']], $this->select);
        self::assertContains(['order', 'first ASC'], $this->select);
    }

    public function testWaitingForReadsOnePairsAlertsUpToTheLimit(): void
    {
        $rows = [['alert_id' => '7']];

        self::assertSame($rows, $this->alerts($rows)->waitingFor(11, 2, 40));
        self::assertContains(['where', 'product_id = ?', 11], $this->select);
        self::assertContains(['where', 'store_id = ?', 2], $this->select);
        self::assertContains(['where', 'status = ?', Alerts::WAITING], $this->select);
        self::assertContains(['order', 'alert_id ASC'], $this->select);
        self::assertContains(['limit', 40], $this->select);
    }

    public function testAnExhaustedBudgetReadsNothing(): void
    {
        self::assertSame([], $this->alerts([['alert_id' => '7']])->waitingFor(11, 2, 0));
        self::assertSame(0, $this->queries);
    }

    private function alerts(array $rows): Alerts
    {
        $select = $this->createStub(Select::class);
        foreach (['from', 'join', 'where', 'group', 'order', 'limit'] as $method) {
            $select->method($method)->willReturnCallback(function (...$args) use ($method, $select) {
                while ($args && end($args) === null) {
                    array_pop($args);
                }
                if ($method === 'join' && count($args) > 3) {
                    $args = array_slice($args, 0, 3);
                }
                $this->select[] = [$method, ...$args];

                return $select;
            });
        }
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchAll')->willReturnCallback(function () use ($rows) {
            $this->queries++;

            return $rows;
        });
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        return new Alerts($resource);
    }
}
