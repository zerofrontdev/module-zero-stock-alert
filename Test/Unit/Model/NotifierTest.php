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
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Zero\StockAlert\Model\Alerts;
use Zero\StockAlert\Model\Notifier;
use Zero\StockAlert\Model\Stock;

class NotifierTest extends TestCase
{
    private const ALERT = [
        'alert_id' => 7,
        'product_id' => 11,
        'parent_id' => null,
        'store_id' => 2,
        'email' => 'buyer@example.com',
        'token' => 'abc',
    ];

    private Alerts&MockObject $alerts;
    private Stock&MockObject $stock;
    private ScopeConfigInterface&MockObject $scopeConfig;
    private TransportInterface&MockObject $transport;
    private Emulation&MockObject $emulation;
    private LoggerInterface&MockObject $logger;
    private Notifier $notifier;

    protected function setUp(): void
    {
        $this->alerts = $this->createMock(Alerts::class);
        $this->alerts->method('waitingByProduct')->willReturn([11 => [self::ALERT]]);
        $this->stock = $this->createMock(Stock::class);
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->emulation = $this->createMock(Emulation::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->transport = $this->createMock(TransportInterface::class);

        $product = $this->createMock(Product::class);
        $product->method('getName')->willReturn('Mona Hoodie');
        $product->method('getProductUrl')->willReturn('https://example.com/mona.html');
        $product->method('getFinalPrice')->willReturn(49.0);
        $product->method('getId')->willReturn(11);
        $repository = $this->createMock(ProductRepositoryInterface::class);
        $repository->method('getById')->willReturn($product);

        $builder = $this->createMock(TransportBuilder::class);
        foreach (['setTemplateIdentifier', 'setTemplateOptions', 'setTemplateVars', 'setFromByScope', 'addTo'] as $method) {
            $builder->method($method)->willReturnSelf();
        }
        $builder->method('getTransport')->willReturn($this->transport);

        $image = $this->createMock(ImageHelper::class);
        $image->method('init')->willReturnSelf();
        $image->method('getUrl')->willReturn('https://example.com/mona.jpg');
        $price = $this->createMock(PriceHelper::class);
        $price->method('currency')->willReturn('$49.00');
        $url = $this->createMock(UrlInterface::class);
        $url->method('getUrl')->willReturn('https://example.com/stockalert/alert/cancel');
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($this->createMock(StoreInterface::class));

        $this->notifier = new Notifier(
            $this->alerts,
            $this->stock,
            $repository,
            $builder,
            $this->emulation,
            $this->scopeConfig,
            $url,
            $image,
            $price,
            $storeManager,
            $this->logger
        );
    }

    public function testMailsAnAlertOnceTheProductIsBackAndMarksItSent(): void
    {
        $this->stock->method('isInStock')->willReturn(true);
        $this->scopeConfig->method('isSetFlag')->willReturn(true);
        $this->transport->expects(self::once())->method('sendMessage');
        $this->alerts->expects(self::once())->method('markSent')->with(7);
        $this->emulation->expects(self::once())->method('startEnvironmentEmulation');
        $this->emulation->expects(self::once())->method('stopEnvironmentEmulation');

        self::assertSame(['sent' => 1, 'failed' => 0], $this->notifier->sendDue());
    }

    public function testNothingIsSentWhileTheProductIsStillSoldOut(): void
    {
        $this->stock->method('isInStock')->willReturn(false);
        $this->transport->expects(self::never())->method('sendMessage');
        $this->alerts->expects(self::never())->method('markSent');

        self::assertSame(['sent' => 0, 'failed' => 0], $this->notifier->sendDue());
    }

    public function testNothingIsSentWhenTheStoreSwitchedTheModuleOff(): void
    {
        $this->stock->method('isInStock')->willReturn(true);
        $this->scopeConfig->method('isSetFlag')->willReturn(false);
        $this->transport->expects(self::never())->method('sendMessage');
        $this->alerts->expects(self::never())->method('markSent');

        self::assertSame(['sent' => 0, 'failed' => 0], $this->notifier->sendDue());
    }

    public function testAFailedMailIsCountedLoggedAndLeftWaitingAndEmulationEnds(): void
    {
        $this->stock->method('isInStock')->willReturn(true);
        $this->scopeConfig->method('isSetFlag')->willReturn(true);
        $this->transport->method('sendMessage')->willThrowException(new \RuntimeException('SES down'));
        $this->alerts->expects(self::never())->method('markSent');
        $this->emulation->expects(self::once())->method('stopEnvironmentEmulation');
        $this->logger->expects(self::once())->method('error')->with(self::stringContains('SES down'));

        self::assertSame(['sent' => 0, 'failed' => 1], $this->notifier->sendDue());
    }
}
