<?php

namespace ShopwareAdapter\DataProvider\Order;

use Doctrine\DBAL\Connection;
use Shopware\Components\Api\Resource\Order as OrderResource;
use Shopware\Models\Order\Status;

class OrderDataProvider implements OrderDataProviderInterface
{
    /**
     * @var Connection
     */
    private $connection;

    /**
     * @var OrderResource
     */
    private $orderResource;

    public function __construct(Connection $connection, OrderResource $orderResource)
    {
        $this->connection = $connection;
        $this->orderResource = $orderResource;
    }

    /**
     * @return array
     */
    public function getOpenOrders(): array
    {
        $filter = [
            [
                'property' => 'status',
                'expression' => '=',
                'value' => Status::ORDER_STATE_OPEN,
            ],
        ];

        $orders = $this->orderResource->getList(0, null, $filter);

        return $orders['data'];
    }

    /**
     * {@inheritdoc}
     */
    public function getOrderDetails($identifier): array
    {
        $order = $this->orderResource->getOne($identifier);

        $order['shopId'] = $this->getCorrectSubShopIdentifier($identifier);

        // for Klarna (paymentid 106) switch order ID to Transaktions ID | 24.02.2020 | MW 
        file_put_contents('./log_klarna_order.log', 'Order: '.print_r($order, true), FILE_APPEND);
        file_put_contents('./log_klarna_order.log', '\n\nPaymentMethodIdentifier: '.$order['paymentMethodIdentifier'].'\n\n#####\n\n', FILE_APPEND);
        if($order['paymentMethodIdentifier'] === 106) {
            file_put_contents('./log_klarna_order.log', '\n\nOrderNumber Before: '.$order->orderNumber.'\n', FILE_APPEND); 
            $order['orderNumber'] = $this->getTransactionIdForKlarnaOrder($identifier);
            file_put_contents('./log_klarna_order.log', 'OrderNumber After: '.$order['orderNumber'].'\n\n#####\n\n', FILE_APPEND); 
        }

        return $this->removeOrphanedShopArray($order);
    }

    /**
     * @param int $orderIdentifier
     *
     * @return int
     */
    private function getCorrectSubShopIdentifier($orderIdentifier): int
    {
        return $this->connection->fetchColumn('SELECT language FROM s_order WHERE id = ?', [$orderIdentifier]);
    }

    /** 24.02.2020 | MW 
     * @param int $orderIdentifier
     *
     * @return string
     */
    private function getTransactionIdForKlarnaOrder($orderIdentifier): string
    {
        return $this->connection->fetchColumn('SELECT transactionID FROM s_order WHERE id = ?', [$orderIdentifier]);
    }

    /**
     * @param array $order
     *
     * @return array
     */
    private function removeOrphanedShopArray(array $order): array
    {
        unset($order['shop']);

        return $order;
    }
}
