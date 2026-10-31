<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\StockAlert\Controller\Alert;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Escaper;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Validator\EmailAddress;
use Magento\Store\Model\StoreManagerInterface;
use Zero\Core\Model\Captcha\Check as CaptchaCheck;
use Zero\StockAlert\Model\Alerts;
use Zero\StockAlert\Model\Stock;

/**
 * "Tell me when it's back": the email and what it waits for (the product,
 * or one of its variants). With HTMX the form is replaced by the answer;
 * without, back to the product page with a message. A filled honeypot
 * field (bots) is answered as a success and ignored.
 */
class Subscribe implements HttpPostActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly Configurable $configurable,
        private readonly Stock $stock,
        private readonly Alerts $alerts,
        private readonly StoreManagerInterface $storeManager,
        private readonly CustomerSession $customerSession,
        private readonly EmailAddress $emailValidator,
        private readonly RawFactory $rawFactory,
        private readonly RedirectFactory $redirectFactory,
        private readonly ManagerInterface $messageManager,
        private readonly Escaper $escaper,
        private readonly CaptchaCheck $captchaCheck
    ) {
    }

    public function execute()
    {
        [$ok, $message] = $this->subscribe();
        if (!$this->request->getHeader('HX-Request')) {
            $ok ? $this->messageManager->addSuccessMessage($message) : $this->messageManager->addErrorMessage($message);

            return $this->redirectFactory->create()->setRefererUrl();
        }

        return $this->rawFactory->create()->setContents(sprintf(
            '<p class="zero-stock-alert-answer text-sm %s" role="status">%s</p>',
            $ok ? 'text-success' : 'text-error',
            $this->escaper->escapeHtml($message)
        ));
    }

    /**
     * @return array{0: bool, 1: string}
     */
    private function subscribe(): array
    {
        $websiteParam = $this->request->getParam('website');
        if ((is_scalar($websiteParam) ? (string) $websiteParam : '') !== '') {
            return [true, (string) __('Thanks: we\'ll email you when it\'s back.')];
        }
        try {
            $this->captchaCheck->verify($this->request, 'zero_stock_alert');
        } catch (LocalizedException $e) {
            return [false, $e->getMessage() . ' ' . __('Reload the page and try again.')];
        }
        $email = $this->request->getParam('email');
        $email = trim(is_scalar($email) ? (string) $email : '');
        if (!$this->emailValidator->isValid($email)) {
            return [false, (string) __('Please enter a valid email address.')];
        }
        $storeId = (int) $this->storeManager->getStore()->getId();
        $productId = (int) $this->request->getParam('product');
        try {
            $product = $this->productRepository->getById($productId, false, $storeId);
        } catch (\Throwable) {
            return [false, (string) __('This product is no longer available.')];
        }
        $parentIds = $this->configurable->getParentIdsByChild($productId);
        $parentId = (int) $this->request->getParam('parent') ?: null;
        if ($parentId && !in_array($parentId, array_map('intval', $parentIds), true)) {
            $parentId = null;
        }
        if ($this->stock->isInStock((int) $product->getId(), $storeId)) {
            return [false, (string) __('Good news: it\'s in stock now.')];
        }
        if ($this->alerts->waitingCount($email) >= Alerts::MAX_PER_EMAIL) {
            return [false, (string) __('This address already waits for many products.')];
        }
        $customerId = $this->customerSession->isLoggedIn() ? (int) $this->customerSession->getCustomerId() : null;
        $added = $this->alerts->add((int) $product->getId(), $parentId, $storeId, $email, $customerId);

        return [true, $added
            ? (string) __('Thanks: we\'ll email %1 when it\'s back.', $email)
            : (string) __('You\'re on the list already: we\'ll email %1 when it\'s back.', $email)];
    }
}
