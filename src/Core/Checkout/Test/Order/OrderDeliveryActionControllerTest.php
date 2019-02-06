<?php declare(strict_types=1);

namespace Shopware\Core\Checkout\Test\Order;

use Composer\Repository\RepositoryInterface;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryDefinition;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\StateMachine\StateMachineRegistry;
use Shopware\Core\Framework\Struct\Uuid;
use Shopware\Core\Framework\Test\TestCaseBase\AdminApiTestBehaviour;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineHistory\StateMachineHistoryCollection;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineHistory\StateMachineHistoryEntity;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateDefinition;
use Symfony\Component\HttpFoundation\Response;

class OrderDeliveryActionControllerTest extends TestCase
{
    use AdminApiTestBehaviour, IntegrationTestBehaviour;

    /**
     * @var StateMachineRegistry
     */
    private $stateMachineRegistry;

    /**
     * @var RepositoryInterface
     */
    private $orderRepository;

    /**
     * @var RepositoryInterface
     */
    private $customerRepository;

    /**
     * @var RepositoryInterface
     */
    private $orderDeliveryRepository;

    /**
     * @var RepositoryInterface
     */
    private $stateMachineHistoryRepository;

    public function setUp(): void
    {
        parent::setUp();

        $this->stateMachineRegistry = $this->getContainer()->get(StateMachineRegistry::class);
        $this->orderRepository = $this->getContainer()->get('order.repository');
        $this->customerRepository = $this->getContainer()->get('customer.repository');
        $this->orderDeliveryRepository = $this->getContainer()->get('order_delivery.repository');
        $this->stateMachineHistoryRepository = $this->getContainer()->get('state_machine_history.repository');
    }

    public function testOrderNotFoundException(): void
    {
        $this->getClient()->request('GET', '/api/v' . PlatformRequest::API_VERSION . '/action/order-delivery/20080911ffff4fffafffffff19830531/state');

        $response = $this->getClient()->getResponse()->getContent();
        $response = json_decode($response, true);

        static::assertEquals(Response::HTTP_NOT_FOUND, $this->getClient()->getResponse()->getStatusCode());
        static::assertArrayHasKey('errors', $response);
    }

    public function testGetAvailableStates(): void
    {
        $context = Context::createDefaultContext();
        $customerId = $this->createCustomer($context);
        $orderId = $this->createOrder($customerId, $context);
        $deliveryId = $this->createOrderDelivery($orderId, $context);

        $this->getClient()->request('GET', '/api/v' . PlatformRequest::API_VERSION . '/_action/order-delivery/' . $deliveryId . '/state');

        $response = $this->getClient()->getResponse()->getContent();
        $response = json_decode($response, true);

        static::assertNotNull($response['currentState']);
        static::assertEquals(Defaults::ORDER_DELIVERY_STATES_OPEN, $response['currentState']['technicalName']);

        static::assertCount(3, $response['transitions']);
        static::assertEquals('cancel', $response['transitions'][0]['actionName']);
        static::assertStringEndsWith('/order-delivery/' . $deliveryId . '/state/cancel', $response['transitions'][0]['url']);
    }

    public function testTransitionToAllowedState(): void
    {
        $context = Context::createDefaultContext();
        $customerId = $this->createCustomer($context);
        $orderId = $this->createOrder($customerId, $context);
        $deliveryId = $this->createOrderDelivery($orderId, $context);

        $this->getClient()->request('GET', '/api/v' . PlatformRequest::API_VERSION . '/_action/order-delivery/' . $deliveryId . '/state');

        $response = $this->getClient()->getResponse()->getContent();
        $response = json_decode($response, true);

        $actionUrl = $response['transitions'][0]['url'];
        $transitionTechnicalName = $response['transitions'][0]['technicalName'];
        $startStateTechnicalName = $response['currentState']['technicalName'];

        $this->getClient()->request('POST', $actionUrl);

        $response = $this->getClient()->getResponse()->getContent();
        $response = json_decode($response, true);

        static::assertEquals(Response::HTTP_OK, $this->getClient()->getResponse()->getStatusCode());
        static::assertEquals($deliveryId, $response['data']['id']);

        $stateId = $response['data']['relationships']['stateMachineState']['data']['id'] ?? null;
        static::assertNotNull($stateId);

        $destinationStateTechnicalName = null;

        foreach ($response['included'] as $relationship) {
            if ($relationship['type'] === StateMachineStateDefinition::getEntityName()) {
                $destinationStateTechnicalName = $relationship['attributes']['technicalName'];
                break;
            }
        }

        static::assertEquals($transitionTechnicalName, $destinationStateTechnicalName);

        // test whether the state history was written
        /** @var StateMachineHistoryCollection $history */
        $history = $this->stateMachineHistoryRepository->search(new Criteria(), $context);

        static::assertEquals(1, \count($history->getElements()), 'Expected history to be written');
        /** @var StateMachineHistoryEntity $historyEntry */
        $historyEntry = array_values($history->getElements())[0];

        static::assertEquals($startStateTechnicalName, $historyEntry->getFromStateMachineState()->getTechnicalName());
        static::assertEquals($destinationStateTechnicalName, $historyEntry->getToStateMachineState()->getTechnicalName());

        static::assertEquals(OrderDeliveryDefinition::getEntityName(), $historyEntry->getEntityName());
        static::assertEquals($deliveryId, $historyEntry->getEntityId()['id']);
        static::assertEquals(Defaults::LIVE_VERSION, $historyEntry->getEntityId()['version_id']);
    }

    public function testTransitionToNotAllowedState(): void
    {
        $context = Context::createDefaultContext();
        $customerId = $this->createCustomer($context);
        $orderId = $this->createOrder($customerId, $context);
        $deliveryId = $this->createOrderDelivery($orderId, $context);

        $this->getClient()->request('POST', '/api/v' . PlatformRequest::API_VERSION . '/_action/order-delivery/' . $deliveryId . '/state/foo');

        $response = $this->getClient()->getResponse()->getContent();
        $response = json_decode($response, true);

        static::assertEquals(Response::HTTP_BAD_REQUEST, $this->getClient()->getResponse()->getStatusCode());
        static::assertArrayHasKey('errors', $response);
    }

    public function testTransitionToEmptyState(): void
    {
        $context = Context::createDefaultContext();
        $customerId = $this->createCustomer($context);
        $orderId = $this->createOrder($customerId, $context);
        $deliveryId = $this->createOrderDelivery($orderId, $context);

        $this->getClient()->request('POST', '/api/v' . PlatformRequest::API_VERSION . '/_action/order-delivery/' . $deliveryId . '/state');

        $response = $this->getClient()->getResponse()->getContent();
        $response = json_decode($response, true);

        static::assertEquals(Response::HTTP_BAD_REQUEST, $this->getClient()->getResponse()->getStatusCode());
        static::assertArrayHasKey('errors', $response);
    }

    private function createOrder(string $customerId, Context $context): string
    {
        $orderId = Uuid::uuid4()->getHex();
        $stateId = $this->stateMachineRegistry->getInitialState(Defaults::ORDER_DELIVERY_STATE_MACHINE, $context)->getId();
        $billingAddressId = Uuid::uuid4()->getHex();

        $order = [
            'id' => $orderId,
            'date' => (new \DateTime())->format(Defaults::DATE_FORMAT),
            'price' => new CartPrice(10, 10, 10, new CalculatedTaxCollection(), new TaxRuleCollection(), CartPrice::TAX_STATE_NET),
            'shippingCosts' => new CalculatedPrice(10, 10, new CalculatedTaxCollection(), new TaxRuleCollection()),
            'orderCustomer' => [
                'customerId' => $customerId,
                'email' => 'test@example.com',
                'firstName' => 'Max',
                'lastName' => 'Mustermann',
            ],
            'stateId' => $stateId,
            'paymentMethodId' => Defaults::PAYMENT_METHOD_DEBIT,
            'currencyId' => Defaults::CURRENCY,
            'currencyFactor' => 1.0,
            'salesChannelId' => Defaults::SALES_CHANNEL,
            'billingAddressId' => $billingAddressId,
            'addresses' => [
                [
                    'salutation' => 'mr',
                    'firstName' => 'Max',
                    'lastName' => 'Mustermann',
                    'street' => 'Ebbinghoff 10',
                    'zipcode' => '48624',
                    'city' => 'Schöppingen',
                    'countryId' => Defaults::COUNTRY,
                ],
            ],
            'lineItems' => [],
            'deliveries' => [
            ],
            'context' => '{}',
            'payload' => '{}',
        ];

        $this->orderRepository->upsert([$order], $context);

        return $orderId;
    }

    private function createCustomer(Context $context): string
    {
        $customerId = Uuid::uuid4()->getHex();
        $addressId = Uuid::uuid4()->getHex();

        $customer = [
            'id' => $customerId,
            'customerNumber' => '1337',
            'salutation' => 'Herr',
            'firstName' => 'Max',
            'lastName' => 'Mustermann',
            'email' => Uuid::uuid4()->getHex() . '@example.com',
            'password' => 'shopware',
            'defaultPaymentMethodId' => Defaults::PAYMENT_METHOD_INVOICE,
            'groupId' => Defaults::FALLBACK_CUSTOMER_GROUP,
            'salesChannelId' => Defaults::SALES_CHANNEL,
            'defaultBillingAddressId' => $addressId,
            'defaultShippingAddressId' => $addressId,
            'addresses' => [
                [
                    'id' => $addressId,
                    'customerId' => $customerId,
                    'countryId' => Defaults::COUNTRY,
                    'salutation' => 'Herr',
                    'firstName' => 'Max',
                    'lastName' => 'Mustermann',
                    'street' => 'Ebbinghoff 10',
                    'zipcode' => '48624',
                    'city' => 'Schöppingen',
                ],
            ],
        ];

        $this->customerRepository->upsert([$customer], $context);

        return $customerId;
    }

    private function createOrderDelivery(string $orderId, Context $context): string
    {
        $deliveryId = Uuid::uuid4()->getHex();
        $stateId = $this->stateMachineRegistry->getInitialState(Defaults::ORDER_DELIVERY_STATE_MACHINE, $context)->getId();

        $delivery = [
            'id' => $deliveryId,
            'orderId' => $orderId,
            'shippingDateEarliest' => (new \DateTime())->format(Defaults::DATE_FORMAT),
            'shippingDateLatest' => (new \DateTime())->format(Defaults::DATE_FORMAT),
            'shippingMethodId' => Defaults::SHIPPING_METHOD,
            'shippingOrderAddressId' => Defaults::SHIPPING_METHOD,
            'shippingCosts' => new CalculatedPrice(10, 10, new CalculatedTaxCollection(), new TaxRuleCollection()),
            'stateId' => $stateId,
            'shippingOrderAddress' => [
                'orderId' => $orderId,
                'countryId' => Defaults::COUNTRY,
                'salutation' => 'Herr',
                'firstName' => 'Max',
                'lastName' => 'Mustermann',
                'street' => 'Ebbinghoff 10',
                'zipcode' => '48624',
                'city' => 'Schöppingen',
            ],
        ];

        $this->orderDeliveryRepository->upsert([$delivery], $context);

        return $deliveryId;
    }
}