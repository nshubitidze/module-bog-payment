<?php

declare(strict_types=1);

namespace Shubo\BogPayment\Test\Integration\Controller\Adminhtml\Order;

use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\TestCase\AbstractBackendController;

/**
 * Smoke test for the admin order-view page when the order paid via BOG.
 *
 * Mirror of TbcPayment ViewTest with method=shubo_bog. Looks up an existing
 * shubo_bog order from the integration test DB (cloned from production);
 * skips if none exists.
 *
 * Per session-9 design §3.c.
 *
 * @magentoAppArea adminhtml
 */
class ViewTest extends AbstractBackendController
{
    public function testOrderViewRendersBogPaymentBlock(): void
    {
        $orderId = $this->resolveOrderIdByMethod('shubo_bog');
        if ($orderId === null) {
            $this->markTestSkipped('No shubo_bog order found in integration test DB — seed one via make test-integration-bootstrap.');
        }

        $this->dispatch('backend/sales/order/view/order_id/' . $orderId);

        $response = $this->getResponse();
        self::assertSame(200, $response->getHttpResponseCode(), 'Order view must return HTTP 200.');

        $body = (string) $response->getBody();
        self::assertNotSame('', $body, 'Order view response body must not be empty.');
        self::assertStringContainsString(
            'BOG',
            $body,
            'Order view body must reference the BOG payment method label.',
        );
        self::assertStringContainsString(
            'shubo_bog',
            $body,
            'Order view body must reference the shubo_bog payment method code.',
        );
    }

    private function resolveOrderIdByMethod(string $methodCode): ?int
    {
        $objectManager = Bootstrap::getObjectManager();
        /** @var \Magento\Framework\App\ResourceConnection $resource */
        $resource = $objectManager->get(\Magento\Framework\App\ResourceConnection::class);
        $connection = $resource->getConnection();

        $select = $connection->select()
            ->from(['sop' => $resource->getTableName('sales_order_payment')], [])
            ->join(['so' => $resource->getTableName('sales_order')], 'so.entity_id = sop.parent_id', ['entity_id'])
            ->where('sop.method = ?', $methodCode)
            ->limit(1);

        $entityId = $connection->fetchOne($select);
        return $entityId ? (int) $entityId : null;
    }
}
