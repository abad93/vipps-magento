<?php
/**
 * Copyright 2018 Vipps
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated
 * documentation files (the "Software"), to deal in the Software without restriction, including without limitation
 * the rights to use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of the Software,
 * and to permit persons to whom the Software is furnished to do so, subject to the following conditions:
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED
 * TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NON INFRINGEMENT. IN NO EVENT SHALL
 * THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF
 * CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS
 * IN THE SOFTWARE.
 */
namespace Vipps\Payment\Model;

use Magento\Framework\{
    Exception\CouldNotSaveException, Exception\NoSuchEntityException
};
use Magento\Sales\Api\{
    OrderManagementInterface, Data\OrderInterface, OrderRepositoryInterface
};
use Magento\Sales\Model\{
    Order, Order\Payment\Transaction as PaymentTransaction,
    Order\Payment\Processor, Order\Payment
};
use Magento\Quote\Api\{
    CartRepositoryInterface, CartManagementInterface, Data\CartInterface
};
use Magento\Quote\Model\Quote;
use Vipps\Payment\{
    Gateway\Transaction\Transaction
};
use Magento\Framework\Lock\Backend\Database;

/**
 * Class OrderManagement
 * @package Vipps\Payment\Model
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class OrderPlace
{
    /**
     * @var OrderRepositoryInterface
     */
    private $orderRepository;

    /**
     * @var CartRepositoryInterface
     */
    private $cartRepository;

    /**
     * @var OrderManagementInterface
     */
    private $orderManagement;

    /**
     * @var CartManagementInterface
     */
    private $cartManagement;

    /**
     * @var OrderLocator
     */
    private $orderLocator;

    /**
     * @var QuoteLocator
     */
    private $quoteLocator;

    /**
     * @var Processor
     */
    private $processor;

    /**
     * @var QuoteUpdater
     */
    private $quoteUpdater;

    /**
     * @var Database
     */
    private $lockManager;

    /**
     * OrderPlace constructor.
     *
     * @param OrderRepositoryInterface $orderRepository
     * @param CartRepositoryInterface $cartRepository
     * @param OrderManagementInterface $orderManagement
     * @param CartManagementInterface $cartManagement
     * @param OrderLocator $orderLocator
     * @param QuoteLocator $quoteLocator
     * @param Processor $processor
     * @param QuoteUpdater $quoteUpdater
     * @param Database $lockManager
     */
    public function __construct(
        OrderRepositoryInterface $orderRepository,
        CartRepositoryInterface $cartRepository,
        OrderManagementInterface $orderManagement,
        CartManagementInterface $cartManagement,
        OrderLocator $orderLocator,
        QuoteLocator $quoteLocator,
        Processor $processor,
        QuoteUpdater $quoteUpdater,
        Database $lockManager
    ) {
        $this->orderRepository = $orderRepository;
        $this->cartRepository = $cartRepository;
        $this->orderManagement = $orderManagement;
        $this->cartManagement = $cartManagement;
        $this->orderLocator = $orderLocator;
        $this->quoteLocator = $quoteLocator;
        $this->processor = $processor;
        $this->quoteUpdater = $quoteUpdater;
        $this->lockManager = $lockManager;
    }

    /**
     * Place order
     *
     * @param CartInterface $quote
     * @param Transaction $transaction
     *
     * @return OrderInterface|null
     * @throws CouldNotSaveException
     * @throws NoSuchEntityException
     * @throws \Magento\Framework\Exception\InputException
     */
    public function execute(CartInterface $quote, Transaction $transaction)
    {
        $lockName = $this->acquireLock($quote);
        if (!$lockName) {
            return null;
        }
        try {
            $order = $this->placeOrder($quote, $transaction);
            if ($order) {
                $this->authorize($order, $transaction);
            }

            return $order;
        } finally {
            $this->releaseLock($lockName);
        }
    }

    /**
     * @param CartInterface $quote
     *
     * @return bool|string
     * @throws \Magento\Framework\Exception\InputException
     */
    private function acquireLock(CartInterface $quote)
    {
        $reservedOrderId = $quote->getReservedOrderId();
        if ($reservedOrderId) {
            $lockName = 'vipps_place_order_' . $reservedOrderId;
            if ($this->lockManager->acquireLock($lockName, 10)) {
                return $lockName;
            }
        }
        return false;
    }

    /**
     * @param $lockName
     *
     * @return bool
     * @throws \Magento\Framework\Exception\InputException
     */
    private function releaseLock($lockName)
    {
        return $this->lockManager->releaseLock($lockName);
    }

    /**
     * Check can we place order or not based on transaction object
     *
     * @param Transaction $transaction
     *
     * @return bool
     */
    private function canPlaceOrder(Transaction $transaction)
    {
        if ($transaction->getTransactionInfo()->getStatus() == Transaction::TRANSACTION_STATUS_RESERVED) {
            return true;
        }

        $lastHistoryItem = $transaction->getTransactionLogHistory()->getLastItem();
        if ($lastHistoryItem->getOperation() == Transaction::TRANSACTION_OPERATION_RESERVE
            && $lastHistoryItem->isOperationSuccess()
        ) {
            return true;
        }

        return false;
    }

    /**
     * @param CartInterface|Quote $quote
     * @param Transaction $transaction
     *
     * @return OrderInterface|null
     * @throws CouldNotSaveException
     * @throws NoSuchEntityException
     */
    private function placeOrder(CartInterface $quote, Transaction $transaction)
    {
        if (!$this->canPlaceOrder($transaction)) {
            return null;
        }

        $reservedOrderId = $quote->getReservedOrderId();
        if (!$reservedOrderId) {
            return null;
        }

        $order = $this->orderLocator->get($reservedOrderId);
        if ($order) {
            return $order;
        }

        $this->quoteUpdater->execute($quote, $transaction);

        /** @var Quote $quote */
        $quote = $this->cartRepository->get($quote->getId());
        if ($quote->getReservedOrderId() !== $reservedOrderId) {
            return null;
        }

        // set quote active, collect totals and place order
        $quote->setIsActive(true);
        $quote->collectTotals();
        $orderId = $this->cartManagement->placeOrder($quote->getId());

        return $this->orderRepository->get($orderId);
    }

    /**
     * Authorize action
     *
     * @param OrderInterface $order
     * @param Transaction $transaction
     */
    private function authorize(OrderInterface $order, Transaction $transaction)
    {
        if ($order->getState() !== Order::STATE_NEW) {
            return;
        }

        /** @var Payment $payment */
        $payment = $order->getPayment();
        $transactionId = $transaction->getTransactionId();
        $payment->setTransactionId($transactionId);
        $payment->setIsTransactionClosed(false);
        $payment->setTransactionAdditionalInfo(
            PaymentTransaction::RAW_DETAILS,
            $transaction->getTransactionInfo()->getData()
        );

        // preconditions
        $totalDue = $order->getTotalDue();
        $baseTotalDue = $order->getBaseTotalDue();

        // do authorize
        $this->processor->authorize($payment, false, $baseTotalDue);
        // base amount will be set inside
        $payment->setAmountAuthorized($totalDue);
        $this->orderRepository->save($order);

        $this->notify($order);
    }

    /**
     * Capture
     *
     * @param OrderInterface $order
     * @param Transaction $transaction
     */
    private function capture(OrderInterface $order, Transaction $transaction)
    {
        if ($order->getState() !== Order::STATE_NEW) {
            return;
        }

        // preconditions
        $totalDue = $order->getTotalDue();
        $baseTotalDue = $order->getBaseTotalDue();

        /** @var Payment $payment */
        $payment = $order->getPayment();
        $payment->setAmountAuthorized($totalDue);
        $payment->setBaseAmountAuthorized($baseTotalDue);

        $transactionId = $transaction->getTransactionId();
        $payment->setTransactionId($transactionId);
        $payment->setTransactionAdditionalInfo(
            PaymentTransaction::RAW_DETAILS,
            $transaction->getTransactionInfo()->getData()
        );

        // do capture
        $this->processor->registerCaptureNotification($payment, $baseTotalDue);
        $this->orderRepository->save($order);

        $this->notify($order);
    }

    /**
     * Send order conformation email if not sent
     *
     * @param Order|OrderInterface $order
     */
    private function notify($order)
    {
        if ($order->getCanSendNewEmailFlag() && !$order->getEmailSent()) {
            $this->orderManagement->notify($order->getEntityId());
        }
    }
}