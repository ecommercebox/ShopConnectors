<?php

namespace rutgerkirkels\ShopConnectors\Connectors;


use rutgerkirkels\ShopConnectors\Entities\Credentials\CredentialsInterface;
use rutgerkirkels\ShopConnectors\Models\Customer;
use rutgerkirkels\ShopConnectors\Models\DateRange;
use rutgerkirkels\ShopConnectors\Models\Item;
use rutgerkirkels\ShopConnectors\Models\Order;
use rutgerkirkels\ShopConnectors\Models\InvoiceAddress;
use rutgerkirkels\ShopConnectors\Models\DeliveryAddress;
use rutgerkirkels\ShopConnectors\Models\OrderLine;

/**
 * Class MagentoV1Connector
 * @package rutgerkirkels\ShopConnectors\Connectors
 * @author Rutger Kirkels <rutger@kirkels.nl>
 */
class MagentoV1Connector extends AbstractConnector implements ConnectorInterface
{
    /**
     * @var \SoapClient
     */
    protected $webservice;

    /**
     * @var string
     */
    protected $sessionId;

    /**
     * MagentoV1Connector constructor.
     * @param string|null $host
     * @param CredentialsInterface|null $credentials
     */
    public function __construct(string $host = null, CredentialsInterface $credentials = null)
    {
        parent::__construct($host, $credentials);
        $this->webservice = new \SoapClient($this->getHost() . '/index.php/api/v2_soap?wsdl');
        $this->getSessionId();
    }

    /**
     * @param DateRange|null $dateRange
     * @return array
     */
    public function getOrders(DateRange $dateRange = null)
    {
        $filter = [
            'complex_filter' => [
                [
                    'key' => 'created_at',
                    'value' => [
                        'key' => 'from',
                        'value' => $dateRange->getStart()->format('Y-m-d')
                    ]
                ],
                [
                    'key' => 'created_at',
                    'value' => [
                        'key' => 'to',
                        'value' => $dateRange->getEnd()->format('Y-m-d')
                    ]
                ]
            ]
        ];

        $magentoOrders = $this->webservice->salesOrderList(
            $this->sessionId,
            $filter
        );

        $orders=[];
        foreach ($magentoOrders as $magentoOrderData) {
            $magentoOrder = $this->webservice->salesOrderInfo($this->getSessionId(), $magentoOrderData->increment_id);

            $order = new Order();
            $order->setDate($this->getTimestamp($magentoOrder->created_at));
            $order->setLastUpdate($this->getTimestamp($magentoOrder->updated_at));
            $order->setCustomer($this->getMagentoCustomer($magentoOrder));
            $order->setInvoiceAddress($this->getAddress($magentoOrder->billing_address, InvoiceAddress::class));
            $order->setDeliveryAddress($this->getAddress($magentoOrder->shipping_address, DeliveryAddress::class));
            $order->setOrderLines($this->getOrderLines($magentoOrder->items));

            $orders[] = $order;
        }

        return $orders;
    }

    /**
     * @param \stdClass $magentoOrder
     * @return Customer
     */
    protected function getMagentoCustomer(\stdClass $magentoOrder)
    {
        $customer = new Customer();
        $customer->setFirstName($magentoOrder->customer_firstname);
        $customer->setLastName($magentoOrder->customer_lastname);
        $customer->setEmail($magentoOrder->customer_email);

        return $customer;
    }

    /**
     * @param \stdClass $magentoAddress
     * @param string $type
     * @return mixed
     */
    protected function getAddress(\stdClass $magentoAddress, string $type)
    {
        $address = new $type;
        $address->setAddress($magentoAddress->street);
        $address->setPostalCode($magentoAddress->postcode);
        $address->setCity($magentoAddress->city);
        $address->setCountryIso2($magentoAddress->country_id);

        if (property_exists($magentoAddress, 'telephone')) {
            $address->addPhone($magentoAddress->telephone);
        }
        return $address;
    }

    /**
     * @param array $magentoOrderLines
     * @return array
     */
    protected function getOrderLines(array $magentoOrderLines)
    {
        $orderlines = [];
        foreach ($magentoOrderLines as $magentoOrderLine) {
            $item = new Item();
            $item->setName($magentoOrderLine->name);
            $item->setSku($magentoOrderLine->sku);
            $item->setPriceWithTax(floatval($magentoOrderLine->price));
            $item->setWeight(floatval($magentoOrderLine->weight));

            $orderlines[] = new OrderLine($item, $magentoOrderLine->qty_ordered);
        }

        return $orderlines;
    }

    /**
     * @return string
     */
    protected function getSessionId() {
        if (is_null($this->sessionId)) {
            $this->sessionId = $this->webservice->login($this->getCredentials()->getUsername(), $this->getCredentials()->getPassword());
        }

        return $this->sessionId;
    }
}