<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\BundleGraphQl\Model\Resolver\Products\Query;

use Magento\Bundle\Model\Product\Type as Bundle;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\CatalogGraphQl\Model\Resolver\Products\DataProvider\Product;
use Magento\CatalogGraphQl\Model\Resolver\Products\DataProvider\Product\FormatterInterface;
use Magento\Bundle\Model\Link;
use Magento\Bundle\Model\Option;
use Magento\Catalog\Model\ResourceModel\Product as ProductResource;
use Magento\Framework\Exception\RuntimeException;
use Magento\Framework\GraphQl\Exception\GraphQlNoSuchEntityException;
use Magento\Framework\GraphQl\Query\EnumLookup;

/**
 * Retrieves simple product data for child products, and formats configurable data
 */
class BundleProductPostProcessor implements \Magento\Framework\GraphQl\Query\PostFetchProcessorInterface
{
    /**
     * @var SearchCriteriaBuilder
     */
    private $searchCriteriaBuilder;

    /**
     * @var Product
     */
    private $productDataProvider;

    /**
     * @var ProductResource
     */
    private $productResource;

    /**
     * @var FormatterInterface
     */
    private $formatter;

    /**
     * @var EnumLookup
     */
    private $enumLookup;

    /**
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param Product $productDataProvider
     * @param ProductResource $productResource
     * @param FormatterInterface $formatter
     * @param EnumLookup $enumLookup
     */
    public function __construct(
        SearchCriteriaBuilder $searchCriteriaBuilder,
        Product $productDataProvider,
        ProductResource $productResource,
        FormatterInterface $formatter,
        EnumLookup $enumLookup
    ) {
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->productDataProvider = $productDataProvider;
        $this->productResource = $productResource;
        $this->formatter = $formatter;
        $this->enumLookup = $enumLookup;
    }

    /**
     * Process all bundle product data, including adding simple product data and formatting relevant attributes.
     *
     * @param array $resultData
     * @return array
     * @throws RuntimeException
     */
    public function process(array $resultData)
    {
        $childrenSkus = [];
        $bundleMap = [];
        foreach ($resultData as $productKey => $product) {
            if (isset($product['type_id']) && $product['type_id'] === Bundle::TYPE_CODE) {
                $resultData[$productKey] = $this->formatBundleAttributes($product);
                if (isset($product['bundle_product_options'])) {
                    $bundleMap[$product['sku']] = [];
                    foreach ($product['bundle_product_options'] as $optionKey => $option) {
                        $resultData[$productKey]['items'][$optionKey]
                            = $this->formatProductOptions($option);
                        /** @var Link $link */
                        foreach ($option['product_links'] as $link) {
                            $bundleMap[$product['sku']][] = $link['sku'];
                            $childrenSkus[] = $link['sku'];
                            $formattedLink = [
                                'product' => new GraphQlNoSuchEntityException(
                                    __('Bundled product not found')
                                ),
                                'price' => $link->getPrice(),
                                'position' => $link->getPosition(),
                                'id' => $link->getId(),
                                'qty' => (int)$link->getQty(),
                                'is_default' => (bool)$link->getIsDefault(),
                                'price_type' => $this->enumLookup->getEnumValueFromField(
                                    'PriceTypeEnum',
                                    $link->getPriceType()
                                ) ?: 'DYNAMIC',
                                'can_change_quantity' => $link->getCanChangeQuantity()
                            ];
                            $resultData[$productKey]['items'][$optionKey]['options'][$link['sku']] = $formattedLink;
                        }
                    }
                }
            }
        }

        $this->searchCriteriaBuilder->addFilter(ProductInterface::SKU, $childrenSkus, 'in');
        $childProducts = $this->productDataProvider->getList($this->searchCriteriaBuilder->create());
        $resultData = $this->addChildData($childProducts->getItems(), $resultData, $bundleMap);

        return $resultData;
    }

    /**
     * Format and add children product data to bundle product response items.
     *
     * @param \Magento\Catalog\Model\Product[] $childrenProducts
     * @param array $resultData
     * @param array $bundleMap Map of parent skus and their children they contain [$parentSku => [$child1, $child2...]]
     * @return array
     */
    private function addChildData(array $childrenProducts, array $resultData, array $bundleMap)
    {
        foreach ($childrenProducts as $childProduct) {
            $childData = $this->formatter->format($childProduct);
            foreach ($resultData as $productKey => $item) {
                if ($item['type_id'] === Bundle::TYPE_CODE
                    && in_array($childData['sku'], $bundleMap[$item['sku']])
                ) {
                    $categoryLinks = $this->productResource->getCategoryIds($childProduct);
                    foreach ($categoryLinks as $position => $categoryLink) {
                        $childData['category_links'][] = ['position' => $position, 'category_id' => $categoryLink];
                    }
                    foreach ($item['items'] as $itemKey => $bundleItem) {
                        foreach (array_keys($bundleItem['options']) as $optionKey) {
                            if ($childData['sku'] === $optionKey) {
                                $resultData[$productKey]['items'][$itemKey]['options'][$optionKey]['product']
                                    = $childData;
                                $resultData[$productKey]['items'][$itemKey]['options'][$optionKey]['label']
                                    = $childData['name'];
                            }
                        }
                    }
                }
            }
        }

        return $resultData;
    }

    /**
     * Format bundle specific top level attributes from product
     *
     * @param array $product
     * @return array
     * @throws RuntimeException
     */
    private function formatBundleAttributes(array $product)
    {
        if (isset($product['price_view'])) {
            $product['price_view']
                = $this->enumLookup->getEnumValueFromField('PriceViewEnum', $product['price_view']);
        }
        if (isset($product['shipment_type'])) {
            $product['ship_bundle_items']
                = $this->enumLookup->getEnumValueFromField('ShipBundleItemsEnum', $product['shipment_type']);
        }
        if (isset($product['price_view'])) {
            $product['dynamic_price'] = !(bool)$product['price_type'];
        }
        if (isset($product['sku_type'])) {
            $product['dynamic_sku'] = !(bool)$product['sku_type'];
        }
        if (isset($product['weight_type'])) {
            $product['dynamic_weight'] = !(bool)$product['weight_type'];
        }
        return $product;
    }

    /**
     * Format bundle option
     *
     * @param Option $option
     * @return array
     */
    private function formatProductOptions(Option $option)
    {
        return $option->getData();
    }
}
