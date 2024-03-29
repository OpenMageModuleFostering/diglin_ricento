<?php
/**
 * ricardo.ch AG - Switzerland
 *
 * @author      Sylvain Rayé <support at diglin.com>
 * @category    Diglin
 * @package     Diglin_Ricento
 * @copyright   Copyright (c) 2015 ricardo.ch AG (http://www.ricardo.ch)
 * @license     http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

use \Diglin\Ricardo\Managers\SellerAccount\Parameter\SoldArticlesParameter;

/**
 * Class Diglin_Ricento_Model_Dispatcher_Transaction
 */
class Diglin_Ricento_Model_Dispatcher_Transaction extends Diglin_Ricento_Model_Dispatcher_Abstract
{
    /**
     * @var int
     */
    protected $_logType = Diglin_Ricento_Model_Products_Listing_Log::LOG_TYPE_TRANSACTION;

    /**
     * @var string
     */
    protected $_jobType = Diglin_Ricento_Model_Sync_Job::TYPE_TRANSACTION;

    /**
     * Create Order Jobs for all products listing with the listed status
     *
     * @return $this
     */
    public function proceed()
    {
        if (!Mage::helper('diglin_ricento')->canImportTransaction()) {
            return $this;
        }

        $plResource = Mage::getResourceModel('diglin_ricento/products_listing');
        $readConnection = $plResource->getReadConnection();
        $select = $readConnection
                    ->select()
                    ->from($plResource->getTable('diglin_ricento/products_listing'), 'entity_id');

        $listingIds = $readConnection->fetchCol($select);

        foreach ($listingIds as $listingId) {
            $select = $readConnection
                ->select()
                ->from(array('pli' => $plResource->getTable('diglin_ricento/products_listing_item')), 'item_id')
                ->where('type <> ?', Mage_Catalog_Model_Product_Type::TYPE_CONFIGURABLE)
                ->where('products_listing_id = :id AND is_planned = 0')
                ->where('status IN (?)', array(Diglin_Ricento_Helper_Data::STATUS_LISTED, Diglin_Ricento_Helper_Data::STATUS_SOLD));

            $binds = array('id' => $listingId);
            $countListedItems = count($readConnection->fetchAll($select, $binds));

            if ($countListedItems == 0) {
                continue;
            }

            /**
             * Check that there is not already running job instead of creating a new one
             */
            Mage::getResourceModel('diglin_ricento/sync_job')->cleanupPendingJob($this->_jobType, $listingId);

            // pending progress doesn't make sense here as we cleanup before but keep it to be sure everything ok
            $job = Mage::getModel('diglin_ricento/sync_job');
            $job->loadByTypeListingIdProgress($this->_jobType, $listingId, array(
                Diglin_Ricento_Model_Sync_Job::PROGRESS_PENDING,
                Diglin_Ricento_Model_Sync_Job::PROGRESS_CHUNK_RUNNING
            ));

            if ($job->getId()) {
                continue;
            }

            $job
                ->setJobType($this->_jobType)
                ->setProgress(Diglin_Ricento_Model_Sync_Job::PROGRESS_PENDING)
                ->setJobMessage(array($job->getJobMessage(true)))
                ->save();

            $jobListing = Mage::getModel('diglin_ricento/sync_job_listing');
            $jobListing
                ->setProductsListingId($listingId)
                ->setTotalCount($countListedItems)
                ->setTotalProceed(0)
                ->setJobId($job->getId())
                ->save();
        }

        unset($listingIds);
        unset($readConnection);
        unset($job);
        unset($jobListing);

        return parent::proceed();
    }

    /**
     * @return $this|mixed
     */
    protected function _proceed()
    {
        $article = null;
        $soldArticles = array();

        try {
            $soldArticles = $this->getSoldArticles();
        } catch (Exception $e) {
            $this->_handleException($e, Mage::getSingleton('diglin_ricento/api_services_selleraccount'));
            $e = null;
            // keep going - no break
        }

        /* @var $item Diglin_Ricento_Model_Products_Listing_Item */
        foreach ($soldArticles as $soldArticle) {
            /**
             * Save item information and eventual error messages
             */
            if (isset($soldArticle['item_message'])) {
                $this->_getListingLog()->saveLog(array(
                    'job_id' => $this->_currentJob->getId(),
                    'product_title' => $soldArticle['product_title'],
                    'product_id' => $soldArticle['product_id'],
                    'products_listing_id' => $this->_productsListingId,
                    'message' => (is_array($soldArticle['item_message'])) ? $this->_jsonEncode($soldArticle['item_message']) : $soldArticle['item_message'],
                    'log_status' => $soldArticle['item_status'],
                    'log_type' => $this->_logType,
                    'created_at' => Mage::getSingleton('core/date')->gmtDate()
                ));
            }
        }

        /**
         * Save the current information of the process to allow live display via ajax call
         */
//        $this->_totalProceed += count($soldArticles);
        $this->_currentJobListing->saveCurrentJob(array(
            'total_proceed' => $this->_currentJobListing->getTotalCount(),
            'last_item_id' => 0 // $lastItem->getId()
        ));

        /**
         * Stop the list if all products listing items are stopped
         */
        if ($this->_productsListingId) {
            Mage::getResourceModel('diglin_ricento/products_listing')->setStatusStop($this->_productsListingId);
        }

        unset($itemCollection);

        return $this;
    }

    /**
     * @param array $articleIds
     * @param int|null $minimumEndDate Default 259200 = 3 days
     * @param int|null $maximumEndDate
     * @return array
     */
    public function getSoldArticlesList(array $articleIds = null, $minimumEndDate = 259200, $maximumEndDate = null)
    {
        $soldArticlesParameter = new SoldArticlesParameter();

        $transactionCollection = Mage::getResourceModel('diglin_ricento/sales_transaction_collection');
        $transactionCollection
            ->addFieldToFilter('order_id', new Zend_Db_Expr('NULL'))
            ->getSelect()
            ->where('UNIX_TIMESTAMP(created_at) + (?) < UNIX_TIMESTAMP(now())', $minimumEndDate);

        /**
         * Set minimum end date to filter e.g. last day. Do not use a higher value as the minimum sales duration is 1 day,
         * we prevent to have conflict with several sold articles having similar internal reference
         */
        $soldArticlesParameter
            ->setPageSize($this->_limit) // if not defined, default is 10 via the API, here is 200
            ->setExcludedTransactionIdsFilter($transactionCollection->getColumnValues('transaction_id'))
            ->setMinimumEndDate($this->_getHelper()->getJsonDate(time() - $minimumEndDate));

        if (!is_null($articleIds)) {
            $soldArticlesParameter->setArticleIdsFilter($articleIds);
        }
        if (!is_null($maximumEndDate)) {
            $soldArticlesParameter->setMaximumEndDate($this->_getHelper()->getJsonDate($maximumEndDate));
        }

        $sellerAccountService = Mage::getSingleton('diglin_ricento/api_services_selleraccount')->setCanUseCache(false);
        if (!$sellerAccountService->getCurrentWebsite()) {
            $sellerAccountService->setCurrentWebsite($this->_getListing()->getWebsiteId());
        }

        $soldArticlesResult = $sellerAccountService->getSoldArticles($soldArticlesParameter);

        return array_reverse($soldArticlesResult['SoldArticles']);
    }

    /**
     * @param array $articleIds
     * @param Diglin_Ricento_Model_Products_Listing_Item $productItem
     * @return bool
     * @throws Exception
     */
    public function getSoldArticles($articleIds = array(), Diglin_Ricento_Model_Products_Listing_Item $productItem = null, $minimumEndDate = 259200, $maximumEndDate = null)
    {
        $soldArticlesReturn = array();

        foreach ($this->getSoldArticlesList($articleIds, $minimumEndDate, $maximumEndDate) as $soldArticle) {

            $rawData = $soldArticle;
            $soldArticle = $this->_getHelper()->extractData($soldArticle);
            $transaction = $soldArticle->getTransaction();

            if ($transaction && count($transaction) > 0) {

                /**
                 * 1. Check that the transaction doesn't already exists
                 */
                if (Mage::getResourceModel('diglin_ricento/sales_transaction_collection')
                    ->addFieldToFilter('bid_id', $transaction->getBidId())->getSize()
                ) {
                    continue;
                }

                /**
                 * 2. Check if the products listing item exists and is listed
                 */
                $references = $soldArticle->getArticleInternalReferences();
                if (!isset($references[0]['InternalReferenceValue'])) {
                    continue;
                }

                $extractedInternReference = $this->_getHelper()->extractInternalReference($references[0]['InternalReferenceValue']);
                if (!($extractedInternReference instanceof Varien_Object)) {
                    continue;
                }

                if (is_null($productItem) || $productItem->getId() != $extractedInternReference->getItemId()) {
                    $productItem = Mage::getModel('diglin_ricento/products_listing_item')->load($extractedInternReference->getItemId());
                    $productItem->setLoadFallbackOptions(true);
                }

                if (!$productItem->getId() /*|| (!in_array($productItem->getStatus(), array(Diglin_Ricento_Helper_Data::STATUS_LISTED, Diglin_Ricento_Helper_Data::STATUS_SOLD)))*/) {
                    continue;
                }

                /**
                 * 3. Create customer if not exist and set his default billing address
                 */
                $customer = $this->getCustomerFromTransaction($transaction->getBuyer(), $this->_getListing()->getWebsiteId());

                if ($customer) {
                    $address = $this->getBillingAddress($customer, $transaction);
                } else {
                    Mage::log($transaction->getBuyer(), Zend_Log::ERR, Diglin_Ricento_Helper_Data::LOG_FILE, true);
                    throw new Exception($this->_getHelper()->__('Customer creation failed! ricardo.ch transaction cannot be added.'));
                }

                /**
                 * 4. Insert transaction into DB for future use
                 */
                $salesTransaction = $this->saveTransaction($transaction, $customer, $address, $soldArticle, $productItem, $rawData);

                /**
                 * 5. Decrease the quantity at products listing item level and stop it if needed
                 */
                $productItem
                    ->setQtyInventory($productItem->getQtyInventory() - $salesTransaction->getQty())
                    ->setStatus(Diglin_Ricento_Helper_Data::STATUS_SOLD)
                    ->save();

                if (!isset($soldArticlesReturn[$productItem->getId()])) {
                    $soldArticlesReturn[$productItem->getId()] = array(
                        'item_id' => $productItem->getId(),
                        'product_title' => $productItem->getProductTitle(),
                        'product_id' => $productItem->getProductId(),
                        'item_message' => array('success' => $this->_getHelper()->__('The product has been sold')),
                        'item_status' => Diglin_Ricento_Model_Products_Listing_Log::STATUS_SUCCESS
                    );
                }
            }
        }

        unset($salesTransaction);
        unset($soldArticlesParameter);
        unset($sellerAccountService);
        unset($soldArticles);
        unset($productItem);
        unset($customer);

        return $soldArticlesReturn;
    }

    /**
     * Find or create customer if needed based on ricardo data
     *
     * @param Varien_Object $buyer
     * @param int $websiteId
     * @return bool|Mage_Customer_Model_Customer
     */
    public function getCustomerFromTransaction(Varien_Object $buyer, $websiteId = Mage_Core_Model_App::ADMIN_STORE_ID)
    {
        if (!$buyer->getBuyerId()) {
            return false;
        }

        $store = $this->getStoreFromWebsite($websiteId);

        /* @var $customer Mage_Customer_Model_Customer */
        $customer = Mage::getModel('customer/customer')
            ->setWebsiteId($websiteId)
            ->loadByEmail($buyer->getEmail());

        if (!$customer->getId()) {
            $customer
                ->setFirstname($buyer->getFirstName())
                ->setLastname($buyer->getLastName())
                ->setEmail($buyer->getEmail())
                ->setPassword($customer->generatePassword())
                ->setStoreId($store->getId())
                ->setWebsiteId($websiteId)
                ->setConfirmation(null);
        }

        if (!$customer->getRicardoId()) {
            $customer
                ->setRicardoId($buyer->getBuyerId())
                ->setRicardoUsername($buyer->getNickName());
        }

        $customer->save();

        Mage::app()->getLocale()->emulate($store->getId());

        if ($customer->isObjectNew() && Mage::getStoreConfigFlag(Diglin_Ricento_Helper_Data::CFG_ACCOUNT_CREATION_EMAIL, $store->getId())) {
            if ($customer->isConfirmationRequired()) {
                $typeEmail = 'confirmation';
            } else {
                $typeEmail = 'registered';
            }
            $customer->sendNewAccountEmail($typeEmail, '', $store->getId());
        }

        Mage::app()->getLocale()->revert();

        return $customer;
    }

    /**
     * @param Mage_Customer_Model_Customer $customer
     * @param $transaction
     * @return Mage_Customer_Model_Address
     * @throws Exception
     */
    public function getBillingAddress(Mage_Customer_Model_Customer $customer, $transaction)
    {
        $buyerAddress = $transaction->getBuyer()->getAddresses();

        $address = $customer->getDefaultBillingAddress();

        $street = $buyerAddress->getAddress1() . ' ' . $buyerAddress->getStreetNumber()
            . (($buyerAddress->getAddress2()) ? "\n" . $buyerAddress->getAddress2() : '')
            . (($buyerAddress->getPostalBox()) ? "\n" . $buyerAddress->getPostalBox() : '');

        $postCode = $buyerAddress->getZipCode();
        $city = $buyerAddress->getCity();

        if (!$address || ($address->getCity() != $city && $address->getPostcode() != $postCode && $address->getStreet() != $street)) {

            /**
             * Ricardo API doesn't provide the region and Magento <= 1.6 doesn't allow to make region optional
             * We use the first region found for the current country but it's far to be good
             * @todo add a "other" region into each country having required region
             */
            $countryId = $this->getCountryId($buyerAddress->getCountry());
            $regionId = null;
            if (Mage::helper('directory')->isRegionRequired($countryId)) {
                $regionId = Mage::getModel('directory/region')->getCollection()
                    ->addFieldToFilter('country_id', $countryId)
                    ->getFirstItem()
                    ->getId();
            }

            $phone = ($transaction->getBuyer()->getPhone()) ? $transaction->getBuyer()->getPhone() : $transaction->getBuyer()->getMobile();

            $address = Mage::getModel('customer/address');
            $address
                ->setCustomerId($customer->getId())
                ->setCompany($transaction->getBuyer()->getCompanyName())
                ->setLastname($customer->getLastname())
                ->setFirstname($customer->getFirstname())
                ->setStreet($street)
                ->setPostcode($postCode)
                ->setCity($city)
                ->setRegionId($regionId)
                ->setCountryId($countryId)
                ->setTelephone($phone)
                ->setIsDefaultBilling(true)
                ->setIsDefaultShipping(true)
                ->setSaveInAddressBook(1)
                ->save();

            $customer->addAddress($address);
        }

        return $address;
    }

    /**
     * @param $transaction
     * @param Mage_Customer_Model_Customer $customer
     * @param $address
     * @param $soldArticle
     * @param Diglin_Ricento_Model_Products_Listing_Item $productItem
     * @param $rawData
     * @return Diglin_Ricento_Model_Sales_Transaction
     * @throws Exception
     */
    public function saveTransaction(
        $transaction,
        Mage_Customer_Model_Customer $customer,
        Mage_Customer_Model_Address $address,
        $soldArticle,
        Diglin_Ricento_Model_Products_Listing_Item $productItem,
        $rawData
    ) {
        $lang = $this->_getHelper()->getLocalCodeFromRicardoLanguageId($soldArticle->getMainLanguageId());
        $transactionData = array(
            'bid_id'                    => $transaction->getBidId(),
            'website_id'                => $this->_getListing()->getWebsiteId(),
            'customer_id'               => $customer->getId(),
            'address_id'                => $address->getId(),
            'ricardo_customer_id'       => $customer->getRicardoId(),
            'ricardo_article_id'        => $soldArticle->getArticleId(),
            'qty'                       => $transaction->getBuyerQuantity(),
            'view_count'                => $soldArticle->getViewCount(),
            'shipping_fee'              => $soldArticle->getDeliveryCost(),
            'shipping_text'             => $soldArticle->getDeliveryText(), // @fixme - if bought in FR and the API use the DE key, text will in DE. I have no solution now
            'shipping_method'           => $soldArticle->getDeliveryId(),
            'shipping_cumulative_fee'   => (int)$soldArticle->getIsCumulativeShipping(),
            'language_id'               => $soldArticle->getMainLanguageId(),
            'payment_methods'           => implode(',', $soldArticle->getPaymentMethodIds()->getData()),
            'shipping_description'      => $productItem->getShippingPaymentRule()->getShippingDescription($lang),
            'payment_description'       => $productItem->getShippingPaymentRule()->getPaymentDescription($lang),
            'total_bid_price'           => $soldArticle->getWinningBidPrice(),
            'product_id'                => $productItem->getProductId(),
            'raw_data'                  => Mage::helper('core')->jsonEncode($rawData),
            'sold_at'                   => $this->_getHelper()->getJsonTimestamp($soldArticle->getEndDate())
        );

        $salesTransaction = Mage::getModel('diglin_ricento/sales_transaction')
            ->addData($transactionData)
            ->save();

        return $salesTransaction;
    }

    /**
     * Retrieve order create model
     *
     * @return Diglin_Ricento_Model_Sales_Order_Create
     */
    public function getOrderCreateModel()
    {
        return Mage::getSingleton('diglin_ricento/sales_order_create');
    }

    /**
     * @param $countryRicardoId
     * @return string
     * @throws Exception
     */
    public function getCountryId($countryRicardoId)
    {
        $countryName = '';
        $countries = Mage::getSingleton('diglin_ricento/api_services_system')
            ->setCurrentWebsite($this->_getListing()->getWebsiteId())
            ->getCountries();

        foreach ($countries as $country) {
            if ($country['CountryId'] == $countryRicardoId) {
                $countryName = $country['CountryName'];
                break;
            }
        }

        $code = $this->translateCountryNameToCode($countryName);
        if (!$code) {
            throw new Exception(Mage::helper('diglin_ricento')->__('Country Code is not available. Please contact the author of this extension or support.'));
        }
        $directory = Mage::getModel('directory/country')->loadByCode($code);
        return $directory->getCountryId();
    }

    /**
     * VERY TEMPORARY SOLUTION until ricardo provide an API method to get the correct value
     * @todo remove it as soon the API has implemented the method to get it
     *
     * @param $countryName
     * @return string
     */
    public function translateCountryNameToCode($countryName)
    {
        $countryCode = array(
            'Schweiz' => 'CH',
            'Suisse' => 'CH',
            'Liechtenstein' => 'LI', // ok for both lang
            'Österreich' => 'AT',
            'Autriche' => 'AT',
            'Deutschland' => 'DE',
            'Allemagne' => 'DE',
            'Frankreich' => 'FR',
            'France' => 'FR',
            'Italien' => 'IT',
            'Italie' => 'IT',
        );

        return (isset($countryCode[$countryName])) ? $countryCode[$countryName] : false;
    }

    /**
     * @param int $websiteId
     * @return Mage_Core_Model_Store
     */
    public function getStoreFromWebsite($websiteId)
    {
        return Mage::app()->getWebsite($websiteId)->getDefaultStore();
    }
}
