<?php declare(strict_types=1);

namespace Shopware\Core\Checkout\Test\Payment;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\CheckoutContext;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Payment\Cart\Token\JWTFactory;
use Shopware\Core\Checkout\Payment\Exception\InvalidOrderException;
use Shopware\Core\Checkout\Payment\Exception\InvalidTokenException;
use Shopware\Core\Checkout\Payment\Exception\TokenExpiredException;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Checkout\Payment\PaymentService;
use Shopware\Core\Checkout\Test\Cart\Common\Generator;
use Shopware\Core\Checkout\Test\Payment\Handler\AsyncTestPaymentHandler;
use Shopware\Core\Checkout\Test\Payment\Handler\SyncTestPaymentHandler;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Struct\Uuid;
use Shopware\Core\Framework\Test\TestCaseBase\DatabaseTransactionBehaviour;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;
use Shopware\Core\System\StateMachine\StateMachineRegistry;
use Symfony\Component\HttpFoundation\Request;

class PaymentServiceTest extends TestCase
{
    use KernelTestBehaviour,
        DatabaseTransactionBehaviour;

    /**
     * @var PaymentService
     */
    private $paymentService;

    /**
     * @var JWTFactory
     */
    private $tokenFactory;

    /**
     * @var EntityRepositoryInterface
     */
    private $orderRepository;

    /**
     * @var EntityRepositoryInterface
     */
    private $customerRepository;

    /**
     * @var EntityRepositoryInterface
     */
    private $orderTransactionRepository;

    /**
     * @var EntityRepositoryInterface
     */
    private $paymentMethodRepository;

    /**
     * @var Context
     */
    private $context;

    /**
     * @var StateMachineRegistry
     */
    private $stateMachineRegistry;

    protected function setUp(): void
    {
        $this->paymentService = $this->getContainer()->get(PaymentService::class);
        $this->tokenFactory = $this->getContainer()->get(JWTFactory::class);
        $this->orderRepository = $this->getContainer()->get('order.repository');
        $this->customerRepository = $this->getContainer()->get('customer.repository');
        $this->orderTransactionRepository = $this->getContainer()->get('order_transaction.repository');
        $this->paymentMethodRepository = $this->getContainer()->get('payment_method.repository');
        $this->stateMachineRegistry = $this->getContainer()->get(StateMachineRegistry::class);
        $this->context = Context::createDefaultContext();
    }

    public function testHandlePaymentByOrderWithInvalidOrderId(): void
    {
        $orderId = Uuid::uuid4()->getHex();
        $checkoutContext = Generator::createCheckoutContext();
        $this->expectException(InvalidOrderException::class);
        $this->expectExceptionMessage(sprintf('The order with id %s is invalid or could not be found.', $orderId));
        $this->paymentService->handlePaymentByOrder($orderId, $checkoutContext);
    }

    public function testHandlePaymentByOrderSyncPayment(): void
    {
        $paymentMethodId = $this->createPaymentMethod($this->context, SyncTestPaymentHandler::class);
        $customerId = $this->createCustomer($this->context);
        $orderId = $this->createOrder($customerId, $paymentMethodId, $this->context);
        $this->createTransaction($orderId, $paymentMethodId, $this->context);

        $checkoutContext = $this->getCheckoutContext($paymentMethodId);

        static::assertNull($this->paymentService->handlePaymentByOrder($orderId, $checkoutContext));
    }

    public function testHandlePaymentByOrderAsyncPayment(): void
    {
        $paymentMethodId = $this->createPaymentMethod($this->context);
        $customerId = $this->createCustomer($this->context);
        $orderId = $this->createOrder($customerId, $paymentMethodId, $this->context);
        $this->createTransaction($orderId, $paymentMethodId, $this->context);

        $checkoutContext = $this->getCheckoutContext($paymentMethodId);

        $response = $this->paymentService->handlePaymentByOrder($orderId, $checkoutContext);

        static::assertEquals(AsyncTestPaymentHandler::REDIRECT_URL, $response->getTargetUrl());
    }

    public function testHandlePaymentByOrderAsyncPaymentWithFinalize(): void
    {
        $paymentMethodId = $this->createPaymentMethod($this->context);
        $customerId = $this->createCustomer($this->context);
        $orderId = $this->createOrder($customerId, $paymentMethodId, $this->context);
        $transactionId = $this->createTransaction($orderId, $paymentMethodId, $this->context);

        $checkoutContext = $this->getCheckoutContext($paymentMethodId);

        $response = $this->paymentService->handlePaymentByOrder($orderId, $checkoutContext);

        static::assertEquals(AsyncTestPaymentHandler::REDIRECT_URL, $response->getTargetUrl());

        $transaction = JWTFactoryTest::createTransaction();
        $transaction->setId($transactionId);
        $transaction->setPaymentMethodId($paymentMethodId);
        $transaction->setOrderId($orderId);

        $token = $this->tokenFactory->generateToken($transaction, $this->context, 'testFinishUrl');
        $request = new Request();
        $tokenStruct = $this->paymentService->finalizeTransaction($token, $request, $this->context);

        static::assertSame('testFinishUrl', $tokenStruct->getFinishUrl());
        /** @var OrderTransactionEntity $transactionEntity */
        $transactionEntity = $this->orderTransactionRepository->search(new Criteria([$transactionId]), $this->context)->first();
        static::assertSame(Defaults::ORDER_TRANSACTION_STATES_PAID, $transactionEntity->getStateMachineState()->getTechnicalName());
    }

    public function testFinalizeTransactionWithInvalidToken(): void
    {
        $token = Uuid::uuid4()->getHex();
        $request = new Request();
        $this->expectException(InvalidTokenException::class);
        $this->paymentService->finalizeTransaction(
            $token,
            $request,
            $this->context
        );
    }

    public function testFinalizeTransactionWithExpiredToken(): void
    {
        $request = new Request();
        $transaction = JWTFactoryTest::createTransaction();

        $token = $this->tokenFactory->generateToken($transaction, $this->context, null, -1);

        $this->expectException(TokenExpiredException::class);
        $this->paymentService->finalizeTransaction($token, $request, $this->context);
    }

    private function getCheckoutContext(string $paymentMethodId): CheckoutContext
    {
        return Generator::createCheckoutContext(
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            (new PaymentMethodEntity())->assign(['id' => $paymentMethodId])
        );
    }

    private function createTransaction(
        string $orderId,
        string $paymentMethodId,
        Context $context
    ): string {
        $id = Uuid::uuid4()->getHex();
        $transaction = [
            'id' => $id,
            'orderId' => $orderId,
            'paymentMethodId' => $paymentMethodId,
            'stateId' => $this->stateMachineRegistry->getInitialState(Defaults::ORDER_TRANSACTION_STATE_MACHINE, $context)->getId(),
            'amount' => new CalculatedPrice(100, 100, new CalculatedTaxCollection(), new TaxRuleCollection(), 1),
            'payload' => '{}',
        ];

        $this->orderTransactionRepository->upsert([$transaction], $context);

        return $id;
    }

    private function createOrder(
        string $customerId,
        string $paymentMethodId,
        Context $context
    ): string {
        $orderId = Uuid::uuid4()->getHex();
        $addressId = Uuid::uuid4()->getHex();
        $stateId = $this->stateMachineRegistry->getInitialState(Defaults::ORDER_STATE_MACHINE, $context)->getId();

        $order = [
            'id' => $orderId,
            'price' => new CartPrice(10, 10, 10, new CalculatedTaxCollection(), new TaxRuleCollection(), CartPrice::TAX_STATE_NET),
            'shippingCosts' => new CalculatedPrice(10, 10, new CalculatedTaxCollection(), new TaxRuleCollection()),
            'orderCustomer' => [
                'customerId' => $customerId,
                'email' => 'test@example.com',
                'salutationId' => Defaults::SALUTATION_ID_MR,
                'firstName' => 'Max',
                'lastName' => 'Mustermann',
            ],
            'stateId' => $stateId,
            'paymentMethodId' => $paymentMethodId,
            'currencyId' => Defaults::CURRENCY,
            'currencyFactor' => 1.0,
            'salesChannelId' => Defaults::SALES_CHANNEL,
            'billingAddressId' => $addressId,
            'addresses' => [
                [
                    'id' => $addressId,
                    'salutationId' => Defaults::SALUTATION_ID_MR,
                    'firstName' => 'Max',
                    'lastName' => 'Mustermann',
                    'street' => 'Ebbinghoff 10',
                    'zipcode' => '48624',
                    'city' => 'Schöppingen',
                    'countryId' => Defaults::COUNTRY,
                ],
            ],
            'lineItems' => [],
            'deliveries' => [],
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
            'salutationId' => Defaults::SALUTATION_ID_MR,
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
                    'salutationId' => Defaults::SALUTATION_ID_MR,
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

    private function createPaymentMethod(
        Context $context,
        string $handlerIdentifier = AsyncTestPaymentHandler::class
    ): string {
        $id = Uuid::uuid4()->getHex();
        $payment = [
            'id' => $id,
            'handlerIdentifier' => $handlerIdentifier,
            'name' => 'Test Payment',
            'description' => 'Test payment handler',
            'active' => true,
        ];

        $this->paymentMethodRepository->upsert([$payment], $context);

        return $id;
    }
}
