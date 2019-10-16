<?php
/**
 * Copyright (c) 2017, Nosto Solutions Ltd
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without modification,
 * are permitted provided that the following conditions are met:
 *
 * 1. Redistributions of source code must retain the above copyright notice,
 * this list of conditions and the following disclaimer.
 *
 * 2. Redistributions in binary form must reproduce the above copyright notice,
 * this list of conditions and the following disclaimer in the documentation
 * and/or other materials provided with the distribution.
 *
 * 3. Neither the name of the copyright holder nor the names of its contributors
 * may be used to endorse or promote products derived from this software without
 * specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND
 * ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
 * WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
 * DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR
 * ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES
 * (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
 * LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON
 * ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS
 * SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * @author Nosto Solutions Ltd <contact@nosto.com>
 * @copyright 2017 Nosto Solutions Ltd
 * @license http://opensource.org/licenses/BSD-3-Clause BSD 3-Clause
 *
 */

namespace Nosto\MassUpdater\Console\Command;

use Exception;
use Magento\Framework\Exception\LocalizedException;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Store\Model\StoreManager;
use Magento\Store\Model\Store;
use Magento\Catalog\Model\ProductRepository;
use Magento\Framework\App\State;
use Magento\Framework\App\Area;

/**
 * Class NostoMassUpdaterCommand
 * @package Nosto\MassUpdater\Console\Command
 */
class NostoMassUpdaterCommand extends Command
{
    public const PRODUCTS_AMOUNT = 'products-amount';
    public const APPEND_TXT = 'append-text';
    public const PRODUCT_ATTRIBUTE = 'product-attribute';
    public const STORE_CODE = 'store-code';
    public const BATCH_SIZE = 50;
    public const PRODUCT_ATTRIBUTES = [
        1 => 'Name',
        2 => 'Description'
    ];

    private $productCollectionFactory;
    private $storeManager;
    private $io;
    private $productRepository;
    private $state;

    /**
     * NostoMassUpdaterCommand constructor.
     * @param CollectionFactory $productCollectionFactory
     * @param StoreManager $storeManager
     * @param State $state
     * @param ProductRepository $productRepository
     */
    public function __construct(
        CollectionFactory $productCollectionFactory,
        StoreManager $storeManager,
        State $state,
        ProductRepository $productRepository
    ) {
        $this->productCollectionFactory = $productCollectionFactory;
        $this->storeManager = $storeManager;
        $this->productRepository = $productRepository;
        $this->state = $state;
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     * @throws LocalizedException
     */
    protected function configure()
    {
        $this->setName('nosto:massupdater')
            ->setDescription('Mass update random products via CLI command')
            ->addOption(
                self::PRODUCTS_AMOUNT,
                null,
                InputOption::VALUE_REQUIRED,
                'Amount of products to be updated'
            )->addOption(
                self::APPEND_TXT,
                null,
                InputOption::VALUE_REQUIRED,
                'String to append to product field'
            )->addOption(
                self::PRODUCT_ATTRIBUTE,
                null,
                InputOption::VALUE_REQUIRED,
                'Field to have string amended'
            )->addOption(
                self::STORE_CODE,
                null,
                InputOption::VALUE_REQUIRED,
                'Store to update the catalog'
            );
        $this->state->setAreaCode(Area::AREA_ADMINHTML);
        parent::configure();
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->io = new SymfonyStyle($input, $output);
        try {
            $storeCode = $this->io->choice(
                'Select store code: ',
                $this->getStoreCodes()
            );
            $storeId = $this->resolveStoreId($storeCode);
            $productsAmount = $input->getOption(self::PRODUCTS_AMOUNT) ?:
                $this->io->ask(sprintf('Enter amount of products: [Max: %d]', $this->getTotalCountProducts($storeId)));
            $appendText = $input->getOption(self::APPEND_TXT) ?:
                $this->io->ask('Enter text to be amended:');
            $attributeChosen = strtolower($this->io->choice(
                'Select attribute to amend',
                self::PRODUCT_ATTRIBUTES
            ));
            $this->updateProducts($storeId, $productsAmount, $attributeChosen, $appendText);
            $this->io->success('Operation completed');
        } catch (Exception $e) {
            $this->io->error($e->getMessage());
        }
    }

    /**
     * Update all products in batches.
     *
     * @param $storeId
     * @param $amount
     * @param $attribute
     * @param $appendTxt
     * @return bool |null
     */
    private function updateProducts($storeId, $amount, $attribute, $appendTxt)
    {
        $totalProducts = $this->getTotalCountProducts($storeId);
        if ($totalProducts <= 0) {
            throw new RuntimeException('No products in the Database');
        }
        if ($amount > $totalProducts) {
            $this->io->note(
                sprintf('Amount of products given is greater that the count in the database, 
                overriding %d products', $totalProducts)
            );
            $amount = $totalProducts;
        }
        $batchSize = self::BATCH_SIZE > $amount ? $amount : self::BATCH_SIZE;
        $pageNumber = 0;
        $this->io->progressStart($amount);
        do {
            $pageNumber++;
            try {
                $collection = $this->productCollectionFactory->create();
                $collection->addAttributeToSelect('*');
                $collection->setPageSize($batchSize);
                $collection->setCurPage($pageNumber);
                $this->updateProductsBatch($collection, $attribute, $appendTxt);
            } catch (Exception $e) {
                $this->io->error($e->getMessage());
                return false;
            }
        } while (($pageNumber * $batchSize) <= $amount);
        $this->io->progressFinish();
        return true;
    }

    /**
     * @param ProductCollection $products
     * @param $attribute
     * @param $appendTxt
     * @throws Exception
     */
    private function updateProductsBatch(ProductCollection $products, $attribute, $appendTxt)
    {
        /** @var ProductCollection $products */
        foreach ($products as $product) {
            if ($attribute === strtolower(self::PRODUCT_ATTRIBUTES[1])) { // If name got selected
                $product->setName($product->getName() . $appendTxt);
            } else { // If Description was selected
                $product->setDescription($product->getDescription() . $appendTxt);
            }

            $this->productRepository->save($product);
            $this->io->progressAdvance();
        }
    }

    /**
     * Returns the total count of products in the database
     *
     * @param $storeId
     * @return int
     */
    private function getTotalCountProducts($storeId)
    {
        $collection = $this->productCollectionFactory->create();
        $collection->addAttributeToSelect('id');
        $collection->addStoreFilter($storeId);
        return $collection->getSize();
    }

    /**
     * Return store id by the given store code
     *
     * @param $storeCode
     * @return mixed
     * @throws Exception
     */
    private function resolveStoreId($storeCode)
    {
        $stores = $this->storeManager->getStores(true, false);
        /** @var Store[] $stores */
        foreach ($stores as $store) {
            if ($store->getCode() === $storeCode) {
                return $store->getId();
            }
        }
        throw new RuntimeException('Could not find Store');
    }

    /**
     * @return array
     */
    private function getStoreCodes()
    {
        $storeNames = array();
        $stores = $this->storeManager->getStores();
        foreach ($stores as $store) {
            /** @var Store $store */
            $storeNames[] = $store->getCode();
        }
        return $storeNames;
    }
}
