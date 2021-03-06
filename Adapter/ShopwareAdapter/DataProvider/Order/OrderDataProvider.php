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

        // for Klarna (paymentId staging: 106 / live 117) switch order number to transactionId for further processing | 24.02.2020 | MW 
        if($order['paymentId'] === 117) {
            $order['number'] = $order['transactionId'];
            // file_put_contents('./log_klarna_order.log', 'Order: '.print_r($order, true).'\n\n#####\n\n', FILE_APPEND);
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
