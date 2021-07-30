<?php

namespace Laybuy\Laybuy\Model;

use Laybuy\Laybuy\Model\Config as LaybuyConfig;
use Laybuy\Laybuy\Model\Logger\ConvertQuoteLogger;
use Magento\Quote\Model\QuoteManagement;
use Magento\Quote\Api\CartRepositoryInterface;
use Laybuy\Laybuy\Model\LaybuyFactory;
use Magento\Quote\Model\Quote;
use Magento\Sales\Model\OrderRepository;
use Magento\Sales\Model\Service\InvoiceService;
use Magento\Framework\DB\TransactionFactory;
use Magento\Sales\Model\Order\InvoiceRepository;
use Magento\Sales\Model\Order\Config;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\InvoiceSender;

/**
 * Class LaybuyConvertQuote
 */
class LaybuyConvertOrder
{
    const LIMIT_TRY = 3;
    const ORDER_LAYBUY_TYPE_CAPTURE_MESASAGE = 'Automatically Created - Detected as Order not created but charged on laybuy';
    const ORDER_AUTO_INVOICE_MESSAGE = 'Automatically Invoiced - Detected as Order was not Invoiced but charged on laybuy';
    /**
     * @var ConvertQuoteLogger
     */
    protected $logger;

    /**
     * @var QuoteManagement
     */
    protected $quoteManagement;

    /**
     * @var CartRepositoryInterface
     */
    protected $quoteRepository;
    /**
     * @var LaybuyFactory
     */
    protected $laybuyFactory;

    /**
     * @var Laybuy
     */
    protected $laybuy;

    /**
     * @var OrderRepository
     */
    protected $orderRepository;

    /**
     * @var InvoiceService
     */
    protected $invoiceService;

    /**
     * @var TransactionFactory
     */
    protected $transactionFactory;

    /**
     * @var InvoiceRepository
     */
    protected $invoiceRepository;

    /**
     * @var Config
     */
    protected $salesOrderConfig;

    /**
     * @var InvoiceSender
     */
    protected $invoiceSender;

    /**
     * LaybuyConvertOrder constructor.
     * @param ConvertQuoteLogger $logger
     * @param QuoteManagement $quoteManagement
     * @param CartRepositoryInterface $quoteRepository
     * @param \Laybuy\Laybuy\Model\LaybuyFactory $laybuyFactory
     * @param OrderRepository $orderRepository
     * @param InvoiceService $invoiceService
     * @param TransactionFactory $transactionFactory
     * @param InvoiceRepository $invoiceRepository
     * @param Config $salesOrderConfig
     * @param InvoiceSender $invoiceSender
     */
    public function __construct
    (
        ConvertQuoteLogger $logger,
        QuoteManagement $quoteManagement,
        CartRepositoryInterface $quoteRepository,
        LaybuyFactory $laybuyFactory,
        OrderRepository $orderRepository,
        InvoiceService $invoiceService,
        TransactionFactory $transactionFactory,
        InvoiceRepository $invoiceRepository,
        Config $salesOrderConfig,
        InvoiceSender $invoiceSender
    ) {
        $this->logger = $logger;
        $this->quoteManagement = $quoteManagement;
        $this->quoteRepository = $quoteRepository;
        $this->laybuyFactory = $laybuyFactory;
        $this->laybuy = $laybuyFactory->create();
        $this->orderRepository = $orderRepository;
        $this->invoiceService = $invoiceService;
        $this->transactionFactory = $transactionFactory;
        $this->invoiceRepository = $invoiceRepository;
        $this->salesOrderConfig = $salesOrderConfig;
        $this->invoiceSender = $invoiceSender;
    }

    /**
     * @param $quoteId
     * @param $quoteInformation
     */
    public function processValidateAndCreateOrder($quoteId, $quoteInformation)
    {
        if ($quoteInformation) {
            $token = json_decode($quoteInformation, true)['Token'];
            $orderId = $this->getOrderIdByToken($this->laybuy, $token);
            if ($orderId) {
                $laybuyOrderDetail = $this->laybuy->laybuyGetOrderById($orderId);
                if ($laybuyOrderDetail && $quote = $this->validateOrderLaybuy($laybuyOrderDetail, $quoteId)) {
                    $this->createOrder($quote, $orderId, $token);
                }
            }
        }
    }

    /**
     * @param $quote
     * @param $laybuyOrderId
     * @param $token
     * @return false
     */
    private function createOrder($quote, $laybuyOrderId, $token)
    {
        try {
            if (!$quote->getIsActive()) {
                $quote->setIsActive(1);
                $this->quoteRepository->save($quote);
            }
            $orderId = $this->quoteManagement->placeOrder($quote->getId());
            $order = $this->orderRepository->get($orderId);
            $order->addCommentToStatusHistory(self::ORDER_LAYBUY_TYPE_CAPTURE_MESASAGE);
            $this->orderRepository->save($order);
            if ($orderId && $order) {
                $txnId = $laybuyOrderId . '_' . $token;
                $this->laybuy->addTransactionId($order, $txnId);
                $this->laybuy->sendOrderEmail($order);
            }

        } catch (\Exception $e) {
            $quote->setIsActive(0);
            $this->quoteRepository->save($quote);
            $this->logger->error('Can\'t create new order',
                ['message' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
        }
        return false;
    }

    /**
     * @param $laybuyOrderDetail
     * @param $quoteId
     * @return false|\Magento\Quote\Api\Data\CartInterface
     */
    private function validateOrderLaybuy($laybuyOrderDetail, $quoteId)
    {
        try {
            $quote = $this->quoteRepository->get($quoteId);
            $laybuyOrderDetailArray = json_decode(json_encode($laybuyOrderDetail), true);
            $quote->collectTotals();
            if ($this->validateLaybuyConfig($quote)
                && !isset($laybuyOrderDetailArray['refunds'])
                && (float)$quote->getGrandTotal() === (float)$laybuyOrderDetailArray['amount']) {
                return $quote;
            }
        } catch (\Exception $e) {
            $this->logger->error('Quote not validate',
                [
                    'quote_id' => $quoteId,
                    'laybuyOrderDetail' => $laybuyOrderDetail,
                    'message' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            return false;
        }
        $this->logger->error('Quote not validate',
            ['quote_id' => $quoteId, 'laybuyOrderDetail' => $laybuyOrderDetail]);
        return false;
    }

    /**
     * @param Quote $quote
     * @return bool
     */
    protected function validateLaybuyConfig(Quote $quote)
    {
        if (!$quote ||
            !$quote->getItemsCount() ||
            $this->laybuy->getConfigData('min_order_total') > $quote->getGrandTotal() ||
            $quote->getGrandTotal() > $this->laybuy->getConfigData('max_order_total')) {
            return false;
        }
        return true;
    }

    /**
     * @return string
     */
    public function getConfigPaymentAction()
    {
        return $this->laybuy->getConfigPaymentAction();
    }

    /**
     * @param $laybuy
     * @param $token
     * @return false
     */
    private function getOrderIdByToken($laybuy, $token)
    {
        $count = 1;
        while ($count) {
            $orderId = $laybuy->laybuyConfirm($token);
            if ($orderId) {
                return $orderId;
            }
            if ($count > self::LIMIT_TRY) {
                break;
            }
            $count++;
        }
        return false;
    }

    /**
     * @param $orderId
     * @param $orderIncrementId
     * @param $token
     * @return false
     */
    public function validateAndCreateInvoiceOrder($orderId, $orderIncrementId, $token)
    {
        try {
            $orderLaybuyData = $this->laybuy->laybuyCheckOrder($orderIncrementId);
            if ($orderLaybuyData) {
                $data = json_decode(json_encode($orderLaybuyData), true);
                if (!isset($data['refunds']) && isset($data['orderId'])) {
                    $order = $this->orderRepository->get($orderId);
                    $order->setState(Order::STATE_PROCESSING)
                        ->setStatus($this->salesOrderConfig->getStateDefaultStatus(Order::STATE_PROCESSING));
                    $laybuyOrderId = $data['orderId'];
                    $txnId = $laybuyOrderId . '_' . $token;
                    if ($order->canInvoice() && $order->getPayment()->getMethod() === LaybuyConfig::CODE) {
                        $this->laybuy->updatePayment($order, $txnId);
                        $invoice = $this->invoiceService->prepareInvoice($order);
                        $invoice->setRequestedCaptureCase(\Magento\Sales\Model\Order\Invoice::CAPTURE_ONLINE);
                        $invoice->setTransactionId($txnId);
                        $invoice->register();
                        $this->invoiceRepository->save($invoice);
                        $transaction = $this->transactionFactory->create();
                        $transactionSave = $transaction->addObject(
                            $invoice
                        )->addObject(
                            $invoice->getOrder()
                        );
                        $transactionSave->save();
                        $order->addCommentToStatusHistory(self::ORDER_AUTO_INVOICE_MESSAGE);
                        $this->orderRepository->save($order);
                        if ($this->laybuy->getConfigData('send_invoice_to_customer')) {
                            $this->invoiceSender->send($invoice);
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            $this->logger->error('Can\'t create order invoice', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }

        return false;
    }
}
