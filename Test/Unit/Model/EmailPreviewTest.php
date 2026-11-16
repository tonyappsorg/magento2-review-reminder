<?php
declare(strict_types=1);

namespace Tonyapps\ReviewReminder\Test\Unit\Model;

use Magento\Catalog\Helper\Image;
use Magento\Catalog\Model\Product;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Mail\TransportInterface;
use Magento\Framework\Math\Random;
use Magento\Framework\Url;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Item;
use Magento\Sales\Model\OrderFactory;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Tonyapps\ReviewReminder\Helper\Config;
use Tonyapps\ReviewReminder\Model\EmailPreview;
use Tonyapps\ReviewReminder\Model\InvitationSender;
use Tonyapps\ReviewReminder\Model\ResourceModel\Invitation;

class EmailPreviewTest extends TestCase
{
    private const TEMPLATE = 'tonyapps_reviewreminder_email_template';

    private Invitation&MockObject $invitation;
    private TransportBuilder&MockObject $transportBuilder;
    private Random&MockObject $random;
    private Emulation&MockObject $emulation;
    private bool $reviewable = true;
    private ?array $sentVars = null;

    protected function setUp(): void
    {
        $this->invitation = $this->createMock(Invitation::class);
        $this->random = $this->createMock(Random::class);
        $this->emulation = $this->createMock(Emulation::class);
        $this->transportBuilder = $this->createMock(TransportBuilder::class);
        foreach (['setTemplateIdentifier', 'setTemplateOptions', 'setFromByScope', 'addTo'] as $method) {
            $this->transportBuilder->method($method)->willReturnSelf();
        }
        $this->transportBuilder->method('setTemplateVars')->willReturnCallback(function (array $vars) {
            $this->sentVars = $vars;
            return $this->transportBuilder;
        });
        $this->transportBuilder->method('getTransport')->willReturn($this->createMock(TransportInterface::class));
    }

    public function testRecordsAreRecentShippedOrders(): void
    {
        $this->invitation->expects(self::once())->method('getPreviewOrders')->with(20)->willReturn([
            $this->row(['entity_id' => 6, 'increment_id' => '000000006']),
            $this->row(['customer_firstname' => null, 'customer_lastname' => null]),
        ]);

        self::assertSame([
            '6' => '#000000006 · Ann Lee · 2026-11-10',
            '5' => '#000000005 · a@example.com · 2026-11-10',
        ], $this->preview()->getRecords(self::TEMPLATE, 20));
    }

    public function testVariablesAreTheOnesTheSenderPassesForTheSameOrder(): void
    {
        $row = $this->row(['token' => 'abc']);
        $this->invitation->method('getDueOrders')->willReturn([$row]);
        $this->invitation->method('getPreviewOrders')->with(1, 5)->willReturn([$row]);

        $this->sender()->send();
        self::assertNotNull($this->sentVars, 'the sender built the email');
        $sent = $this->sentVars;
        $this->sentVars = null;

        $previewed = $this->preview()->getVariables(self::TEMPLATE, '5');

        self::assertSame($sent, $previewed);
        self::assertSame('https://shop/reviewreminder/reminder/unsubscribe/token/abc', $previewed['unsubscribeUrl']);
    }

    public function testOrderWithoutInvitationGetsAPlaceholderTokenAndNothingIsWritten(): void
    {
        $this->invitation->method('getPreviewOrders')->willReturn([$this->row()]);
        foreach (['create', 'markSent', 'markSkipped', 'recordFailure', 'unsubscribe'] as $method) {
            $this->invitation->expects(self::never())->method($method);
        }
        $this->random->expects(self::never())->method('getUniqueHash');
        $this->transportBuilder->expects(self::never())->method('getTransport');
        $this->emulation->expects(self::once())->method('startEnvironmentEmulation')->with(1);
        $this->emulation->expects(self::once())->method('stopEnvironmentEmulation');

        $vars = $this->preview()->getVariables(self::TEMPLATE, '5');

        self::assertSame('https://shop/reviewreminder/reminder/unsubscribe/token/preview', $vars['unsubscribeUrl']);
        self::assertSame('Ann', $vars['customerName']);
        self::assertSame('000000005', $vars['orderIncrementId']);
    }

    public function testStoreIsTheOrdersStore(): void
    {
        $this->invitation->method('getPreviewOrders')->willReturnCallback(
            fn (int $limit, ?int $orderId) => $orderId === 5 ? [$this->row(['store_id' => 3])] : []
        );
        $this->invitation->expects(self::never())->method('create');

        self::assertSame(3, $this->preview()->getStoreId(self::TEMPLATE, '5'));
        self::assertNull($this->preview()->getStoreId(self::TEMPLATE, '6'));
    }

    public function testOrderWithNothingToReviewIsRefused(): void
    {
        $this->reviewable = false;
        $this->invitation->method('getPreviewOrders')->willReturn([$this->row()]);
        $this->emulation->expects(self::once())->method('stopEnvironmentEmulation');

        $this->expectException(LocalizedException::class);
        $this->preview()->getVariables(self::TEMPLATE, '5');
    }

    /** @return array<string, mixed> */
    private function row(array $data = []): array
    {
        return $data + [
            'entity_id' => 5, 'store_id' => 1, 'customer_email' => 'a@example.com', 'customer_firstname' => 'Ann',
            'customer_lastname' => 'Lee', 'increment_id' => '000000005', 'created_at' => '2026-11-10 09:00:00',
            'token' => null,
        ];
    }

    private function preview(): EmailPreview
    {
        return new EmailPreview($this->invitation, $this->sender(), $this->emulation);
    }

    private function sender(): InvitationSender
    {
        $store = $this->createMock(Store::class);
        $store->method('getId')->willReturn(1);
        $store->method('getFrontendName')->willReturn('Luma');
        $store->method('getWebsiteId')->willReturn(1);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn([$store]);
        $storeManager->method('getStore')->willReturn($store);
        $config = $this->createMock(Config::class);
        $config->method('enabled')->willReturn(true);
        $config->method('reviewsEnabled')->willReturn(true);

        $product = $this->createMock(Product::class);
        $product->method('getId')->willReturn(9);
        $product->method('getProductUrl')->willReturn('https://shop/tank.html');
        $product->method('getStatus')->willReturn($this->reviewable ? 1 : 2);
        $product->method('isVisibleInSiteVisibility')->willReturn(true);
        $product->method('getWebsiteIds')->willReturn(['1']);
        $item = $this->createMock(Item::class);
        $item->method('getQtyShipped')->willReturn(1.0);
        $item->method('getProduct')->willReturn($product);
        $item->method('getName')->willReturn('Tank');
        $order = $this->createMock(Order::class);
        $order->method('load')->willReturnSelf();
        $order->method('getAllVisibleItems')->willReturn([$item]);
        $orderFactory = $this->getMockBuilder(OrderFactory::class)->disableOriginalConstructor()->onlyMethods(['create'])->getMock();
        $orderFactory->method('create')->willReturn($order);
        $image = $this->createMock(Image::class);
        $image->method('init')->willReturnSelf();
        $image->method('getUrl')->willReturn('https://shop/media/tank.jpg');

        return new InvitationSender(
            $this->invitation,
            $config,
            $orderFactory,
            $this->random,
            $storeManager,
            $this->emulation,
            $this->transportBuilder,
            $this->createMock(LoggerInterface::class),
            $image,
            $this->frontendUrl()
        );
    }

    /** Storefront URL builder: https://shop/<route>/token/<token>. */
    private function frontendUrl(): Url
    {
        $url = $this->createMock(Url::class);
        $url->method('setScope')->willReturnSelf();
        $url->method('getUrl')->willReturnCallback(
            static fn (string $route, array $params) => 'https://shop/' . $route . '/token/' . $params['token']
        );
        return $url;
    }
}
