<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\StockAlert\Model;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Framework\App\Area;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Pricing\Helper\Data as PriceHelper;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Sends "it's back" for every waiting alert whose product is in stock again:
 * one email each, in the store view it was asked in (its language, its
 * links), then the alert is done. The product shown is the configurable
 * one for a variant, its option named ("M / Black").
 *
 * Only what can be bought is read: the products and store views waited for
 * (store views with the module off are dropped first), their stock asked
 * once per store view for 500 products at a time, then the alerts of those
 * that are back, up to $perRun emails (sent or failed) per run.
 */
class Notifier
{
    private const CHUNK = 500;

    public function __construct(
        private readonly Alerts $alerts,
        private readonly Stock $stock,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly TransportBuilder $transportBuilder,
        private readonly Emulation $emulation,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly UrlInterface $url,
        private readonly ImageHelper $imageHelper,
        private readonly PriceHelper $priceHelper,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger,
        private readonly int $perRun = 1000
    ) {
    }

    /**
     * @return array{sent: int, failed: int}
     */
    public function sendDue(): array
    {
        $result = ['sent' => 0, 'failed' => 0];
        $budget = $this->perRun;
        $byStore = [];
        foreach ($this->alerts->waitingPairs() as $pair) {
            $byStore[$pair['store_id']][$pair['product_id']] = $pair['sku'];
        }
        foreach ($byStore as $storeId => $skus) {
            if (!$this->scopeConfig->isSetFlag('zero_stock_alert/general/enabled', ScopeInterface::SCOPE_STORE, $storeId)) {
                continue;
            }
            foreach (array_chunk($skus, self::CHUNK, true) as $chunk) {
                if ($budget <= 0) {
                    return $result;
                }
                $buyable = $this->stock->buyable($chunk, $storeId);
                foreach (array_keys($chunk) as $productId) {
                    if (empty($buyable[$productId])) {
                        continue;
                    }
                    foreach ($this->alerts->waitingFor($productId, $storeId, $budget) as $alert) {
                        $budget--;
                        try {
                            $this->send($alert);
                            $this->alerts->markSent((int) $alert['alert_id']);
                            $result['sent']++;
                        } catch (\Throwable $e) {
                            $result['failed']++;
                            $this->logger->error('Zero_StockAlert: alert ' . $alert['alert_id'] . ': ' . $e->getMessage());
                        }
                    }
                    if ($budget <= 0) {
                        return $result;
                    }
                }
            }
        }

        return $result;
    }

    private function send(array $alert): void
    {
        $storeId = (int) $alert['store_id'];
        $this->emulation->startEnvironmentEmulation($storeId, Area::AREA_FRONTEND, true);
        try {
            $variant = $this->productRepository->getById((int) $alert['product_id'], false, $storeId);
            $shown = $alert['parent_id'] ? $this->productRepository->getById((int) $alert['parent_id'], false, $storeId) : $variant;
            $option = $shown !== $variant ? ($this->stock->soldOutLabel($shown, (int) $variant->getId()) ?? '') : '';
            $vars = [
                'product_name' => (string) $shown->getName(),
                'option' => $option,
                'product_url' => (string) $shown->getProductUrl(),
                'image_url' => $this->imageHelper->init($shown, 'product_small_image')->getUrl(),
                'price' => (string) $this->priceHelper->currency((float) $variant->getFinalPrice(), true, false),
                'store' => $this->storeManager->getStore($storeId),
                'cancel_url' => $this->url->getUrl('stockalert/alert/cancel', ['token' => $alert['token'], '_nosid' => true]),
            ];
            $this->transportBuilder
                ->setTemplateIdentifier((string) $this->value('template', $storeId))
                ->setTemplateOptions(['area' => Area::AREA_FRONTEND, 'store' => $storeId])
                ->setTemplateVars($vars)
                ->setFromByScope((string) $this->value('identity', $storeId), $storeId)
                ->addTo((string) $alert['email'])
                ->getTransport()
                ->sendMessage();
        } finally {
            $this->emulation->stopEnvironmentEmulation();
        }
    }

    private function value(string $field, int $storeId): mixed
    {
        return $this->scopeConfig->getValue('zero_stock_alert/general/' . $field, ScopeInterface::SCOPE_STORE, $storeId);
    }
}
