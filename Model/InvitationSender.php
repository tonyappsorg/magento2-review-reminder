<?php
declare(strict_types=1);

namespace Tonyapps\ReviewReminder\Model;

use Magento\Catalog\Helper\Image;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Framework\App\Area;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Math\Random;
use Magento\Framework\Url;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Item;
use Magento\Sales\Model\OrderFactory;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Tonyapps\ReviewReminder\Helper\Config;
use Tonyapps\ReviewReminder\Model\ResourceModel\Invitation;

class InvitationSender
{
    public function __construct(
        private readonly Invitation $invitation,
        private readonly Config $config,
        private readonly OrderFactory $orderFactory,
        private readonly Random $random,
        private readonly StoreManagerInterface $storeManager,
        private readonly Emulation $emulation,
        private readonly TransportBuilder $transportBuilder,
        private readonly LoggerInterface $logger,
        private readonly Image $imageHelper,
        // Storefront URL builder: the injected UrlInterface is the admin one in admin requests (links get the admin path and a secret key).
        private readonly Url $frontendUrl
    ) {
    }

    public function send(): void
    {
        foreach ($this->storeManager->getStores() as $store) {
            $storeId = (int) $store->getId();
            if (!$this->config->enabled($storeId) || !$this->config->reviewsEnabled($storeId)) {
                continue;
            }

            // Process stores individually because delay is store scoped.
            foreach ($this->invitation->getDueOrders([$storeId], $this->config->delayDays($storeId)) as $row) {
                $orderId = (int) $row['entity_id'];
                $token = (string) ($row['token'] ?? '');
                if ($token === '') {
                    $token = $this->random->getUniqueHash();
                    $this->invitation->create($orderId, $storeId, (string) $row['customer_email'], $token);
                }
                $this->emulation->startEnvironmentEmulation($storeId, Area::AREA_FRONTEND, true);
                try {
                    if ($this->sendOrder($row, $token, $storeId)) {
                        $this->invitation->markSent($orderId);
                    } else {
                        $this->invitation->markSkipped($orderId);
                    }
                } catch (\Throwable $e) {
                    $this->invitation->recordFailure($orderId);
                    $this->logger->error('Review invitation for order ' . $orderId . ' failed: ' . $e->getMessage());
                } finally {
                    $this->emulation->stopEnvironmentEmulation();
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return bool false when no shipped product can be reviewed, so nothing was sent
     */
    private function sendOrder(array $row, string $token, int $storeId): bool
    {
        $vars = $this->templateVars($row, $token, $storeId);
        if ($vars === null) {
            return false;
        }
        $this->transportBuilder->setTemplateIdentifier($this->config->template($storeId))
            ->setTemplateOptions(['area' => Area::AREA_FRONTEND, 'store' => $storeId])
            ->setTemplateVars($vars)
            ->setFromByScope($this->config->identity($storeId), $storeId)
            ->addTo((string) $row['customer_email'])
            ->getTransport()->sendMessage();
        return true;
    }

    /**
     * The invitation's template variables. Shared with the Email Designer preview (Model\EmailPreview); call it in
     * the order's store emulation, as send() does.
     *
     * @param array<string, mixed> $row a getDueOrders() row
     * @return array<string, mixed>|null null when no shipped product can be reviewed
     */
    public function templateVars(array $row, string $token, int $storeId): ?array
    {
        $websiteId = (int) $this->storeManager->getStore($storeId)->getWebsiteId();
        /** @var Order $order */
        $order = $this->orderFactory->create()->load((int) $row['entity_id']);
        $products = [];
        foreach ($order->getAllVisibleItems() as $item) {
            if (!$item instanceof Item || (float) $item->getQtyShipped() <= 0) {
                continue;
            }
            $product = $item->getProduct();
            // Reviewable = its product page (with the review form) can be opened in this store.
            if (!$product || !$product->getId()
                || (int) $product->getStatus() !== Status::STATUS_ENABLED
                || !$product->isVisibleInSiteVisibility()
                || !in_array($websiteId, array_map('intval', (array) $product->getWebsiteIds()), true)
            ) {
                continue;
            }
            $products[] = [
                'name' => (string) $item->getName(),
                'url' => $product->getProductUrl() . '#review-form',
                'image' => $this->imageHelper->init($product, 'category_page_grid')->getUrl(),
            ];
        }
        if (!$products) {
            return null;
        }
        $store = $this->storeManager->getStore($storeId);
        return [
            'customerName' => (string) $row['customer_firstname'] ?: (string) __('there'),
            'products' => $products,
            'unsubscribeUrl' => $this->frontendUrl->setScope($storeId)
                ->getUrl('reviewreminder/reminder/unsubscribe', ['token' => $token, '_nosid' => true]),
            'orderIncrementId' => (string) $row['increment_id'],
            'storeName' => (string) $store->getFrontendName(),
            // {{trans}} does not run inside {{for}}; the template reads this variable instead.
            'reviewButtonLabel' => (string) __('Write a review'),
        ];
    }
}
