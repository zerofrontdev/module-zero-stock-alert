<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\StockAlert\Controller\Alert;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface;
use Zero\StockAlert\Model\Alerts;

/**
 * The email's "Didn't ask for it?" link: no more emails for that alert.
 */
class Cancel implements HttpGetActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly Alerts $alerts,
        private readonly RedirectFactory $redirectFactory,
        private readonly ManagerInterface $messageManager
    ) {
    }

    public function execute()
    {
        $token = $this->request->getParam('token');
        $token = is_scalar($token) ? (string) $token : '';
        $alert = preg_match('/^[a-f0-9]{32}$/', $token) ? $this->alerts->cancel($token) : null;
        $alert
            ? $this->messageManager->addSuccessMessage(__('Done: we won\'t email you about this product.'))
            : $this->messageManager->addErrorMessage(__('This link is no longer valid.'));

        return $this->redirectFactory->create()->setPath('');
    }
}
