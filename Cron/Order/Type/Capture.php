<?php

namespace Laybuy\Laybuy\Cron\Order\Type;

use Laybuy\Laybuy\Model\Laybuy;
use Laybuy\Laybuy\Model\Logger\ConvertQuoteLogger;
use Laybuy\Laybuy\Model\LaybuyConvertOrder;
use Magento\Framework\App\ResourceConnection;
use Laybuy\Laybuy\Model\Email;

/**
 * Class Capture
 */
class Capture
{
    /**
     * @var ResourceConnection
     */
    protected $resource;

    /**
     * @var ConvertQuoteLogger
     */
    protected $logger;

    /**
     * @var LaybuyConvertOrder
     */
    protected $laybuyConvertOrder;

    /**
     * @var Email
     */
    protected $laybuyEmail;

    private $reportList = [];

    /**
     * Capture constructor.
     * @param ResourceConnection $resource
     * @param ConvertQuoteLogger $logger
     * @param LaybuyConvertOrder $laybuyConvertOrder
     * @param Email $laybuyEmail
     */
    public function __construct(
        ResourceConnection $resource,
        ConvertQuoteLogger $logger,
        LaybuyConvertOrder $laybuyConvertOrder,
        Email $laybuyEmail
    ) {
        $this->resource = $resource;
        $this->logger = $logger;
        $this->laybuyConvertOrder = $laybuyConvertOrder;
        $this->laybuyEmail = $laybuyEmail;
    }

    /**
     * @throws \Exception
     */
    public function execute()
    {
        $date = new \DateTime('now', new \DateTimeZone('UTC'));
        $timeToCompare = $date->modify('-60 minutes')->format('Y-m-d h:i:s');
        $connection = $this->resource->getConnection();
        $select = $connection->select()->from(
            ['quote' => $connection->getTableName('quote')],
            ['quote.entity_id', 'quote.store_id']
        )->joinLeft(
            ['quote_payment' => $connection->getTableName('quote_payment')],
            'quote_payment.quote_id = quote.entity_id',
            ['quote_payment.additional_information']
        )->joinLeft(
            ['sales_order' => $connection->getTableName('sales_order')],
            'sales_order.quote_id = quote.entity_id',
            []
        )
            ->where('quote.updated_at> (?)', $timeToCompare)
            ->where('quote_payment.method = (?)', 'laybuy_payment')
            ->where('sales_order.quote_id IS NULL');
        $quoteList = $connection->fetchAll($select);
        foreach ($quoteList as $quoteData) {
            try {
                $quoteId = $quoteData['entity_id'];
                $quoteInformation = $quoteData['additional_information'];
                $storeId = $quoteData['store_id'];
                if ($quoteInformation) {
                    $this->reportList[] = $this->laybuyConvertOrder->processValidateAndCreateOrder($quoteId, $quoteInformation, $storeId);
                }
            } catch (\Exception $e) {
                $this->logger->error('Convert quote to order error',
                    ['quote_id' => $quoteId, 'message' => $e->getMessage(), 'trace' => $e->getTraceAsString()]
                );
                $this->reportList[] = [
                    'status' => false,
                    'message' => $e->getMessage(),
                    'data' => [
                        'quote_id' => $quoteId
                    ]
                ];
            }
        }
        if (count($this->reportList)) {
            $reportByStoreList = [];
            foreach ($this->reportList as $report) {
                $reportByStoreList[$report['store_id']][] = $report;
            }
            $this->laybuyEmail->sendEmailListByStore($reportByStoreList);
        }
    }

    public function executeCommand()
    {
        $date = new \DateTime('now', new \DateTimeZone('UTC'));
        $timeToCompare = $date->modify('-10080 minutes')->format('Y-m-d h:i:s');
        $connection = $this->resource->getConnection();
        $select = $connection->select()->from(
            ['quote' => $connection->getTableName('quote')],
            ['quote.entity_id', 'quote.store_id']
        )->joinLeft(
            ['quote_payment' => $connection->getTableName('quote_payment')],
            'quote_payment.quote_id = quote.entity_id',
            ['quote_payment.additional_information']
        )->joinLeft(
            ['sales_order' => $connection->getTableName('sales_order')],
            'sales_order.quote_id = quote.entity_id',
            []
        )
            ->where('quote.updated_at> (?)', $timeToCompare)
            ->where('quote_payment.method = (?)', 'laybuy_payment')
            ->where('sales_order.quote_id IS NULL');
        $quoteList = $connection->fetchAll($select);
        foreach ($quoteList as $quoteData) {
            try {
                $quoteId = $quoteData['entity_id'];
                $quoteInformation = $quoteData['additional_information'];
                $storeId = $quoteData['store_id'];
                if ($quoteInformation) {
                    $this->reportList[] = $this->laybuyConvertOrder->processValidateAndCreateOrder($quoteId, $quoteInformation, $storeId);
                }
            } catch (\Exception $e) {
                $this->logger->error('Convert quote to order error',
                    ['quote_id' => $quoteId, 'message' => $e->getMessage(), 'trace' => $e->getTraceAsString()]
                );
                $this->reportList[] = [
                    'status' => false,
                    'message' => $e->getMessage(),
                    'data' => [
                        'quote_id' => $quoteId
                    ]
                ];
            }
        }
        if (count($this->reportList)) {
            $reportByStoreList = [];
            foreach ($this->reportList as $report) {
                $reportByStoreList[$report['store_id']][] = $report;
            }
            $this->laybuyEmail->sendEmailListByStore($reportByStoreList);
        }
    }
}
