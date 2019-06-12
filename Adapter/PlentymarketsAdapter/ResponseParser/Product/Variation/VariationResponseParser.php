<?php

namespace PlentymarketsAdapter\ResponseParser\Product\Variation;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use PlentymarketsAdapter\Helper\ReferenceAmountCalculatorInterface;
use PlentymarketsAdapter\Helper\VariationHelperInterface;
use PlentymarketsAdapter\PlentymarketsAdapter;
use PlentymarketsAdapter\ReadApi\Availability as AvailabilityApi;
use PlentymarketsAdapter\ReadApi\Item\Attribute as AttributeApi;
use PlentymarketsAdapter\ReadApi\Item\Barcode as BarcodeApi;
use PlentymarketsAdapter\ResponseParser\Product\Image\ImageResponseParserInterface;
use PlentymarketsAdapter\ResponseParser\Product\Price\PriceResponseParserInterface;
use PlentymarketsAdapter\ResponseParser\Product\Stock\StockResponseParserInterface;
use SystemConnector\ConfigService\ConfigServiceInterface;
use SystemConnector\IdentityService\Exception\NotFoundException;
use SystemConnector\IdentityService\IdentityServiceInterface;
use SystemConnector\TransferObject\Language\Language;
use SystemConnector\TransferObject\Product\Barcode\Barcode;
use SystemConnector\TransferObject\Product\Image\Image;
use SystemConnector\TransferObject\Product\Product;
use SystemConnector\TransferObject\Product\Property\Property;
use SystemConnector\TransferObject\Product\Property\Value\Value;
use SystemConnector\TransferObject\Product\Variation\Variation;
use SystemConnector\TransferObject\TransferObjectInterface;
use SystemConnector\TransferObject\Unit\Unit;
use SystemConnector\ValueObject\Translation\Translation;

class VariationResponseParser implements VariationResponseParserInterface
{
    /**
     * @var IdentityServiceInterface
     */
    private $identityService;

    /**
     * @var PriceResponseParserInterface
     */
    private $priceResponseParser;

    /**
     * @var ImageResponseParserInterface
     */
    private $imageResponseParser;

    /**
     * @var StockResponseParserInterface
     */
    private $stockResponseParser;

    /**
     * @var AvailabilityApi
     */
    private $availabilitiesApi;

    /**
     * @var AttributeApi
     */
    private $itemAttributesApi;

    /**
     * @var BarcodeApi
     */
    private $itemBarcodeApi;

    /**
     * @var ReferenceAmountCalculatorInterface
     */
    private $referenceAmountCalculator;

    /**
     * @var VariationHelperInterface
     */
    private $variationHelper;

    /**
     * @var ConfigServiceInterface
     */
    private $configService;

    public function __construct(
        IdentityServiceInterface $identityService,
        PriceResponseParserInterface $priceResponseParser,
        ImageResponseParserInterface $imageResponseParser,
        StockResponseParserInterface $stockResponseParser,
        AvailabilityApi $availabilitiesApi,
        AttributeApi $itemAttributesApi,
        BarcodeApi $itemBarcodeApi,
        ReferenceAmountCalculatorInterface $referenceAmountCalculator,
        VariationHelperInterface $variationHelper,
        ConfigServiceInterface $configService
    ) {
        $this->identityService = $identityService;
        $this->priceResponseParser = $priceResponseParser;
        $this->imageResponseParser = $imageResponseParser;
        $this->stockResponseParser = $stockResponseParser;
        $this->availabilitiesApi = $availabilitiesApi;
        $this->itemAttributesApi = $itemAttributesApi;
        $this->itemBarcodeApi = $itemBarcodeApi;
        $this->referenceAmountCalculator = $referenceAmountCalculator;
        $this->variationHelper = $variationHelper;
        $this->configService = $configService;
    }

    /**
     * @param array $product
     *
     * @throws NotFoundException
     *
     * @return array
     */
    public function parse(array $product): array
    {
        $productIdentity = $this->identityService->findOneBy(
            [
                'adapterIdentifier' => (string) $product['id'],
                'adapterName' => PlentymarketsAdapter::NAME,
                'objectType' => Product::TYPE,
            ]
        );

        if (null === $productIdentity) {
            return [];
        }

        $variations = $product['variations'];

        $mainVariation = $this->variationHelper->getMainVariation($variations);

        if (empty($mainVariation)) {
            return [];
        }

        if (Product::MULTIPACK === $product['itemType']) {
            $variations = array_filter(
                $variations,
                static function (array $variation) {
                    return $variation['isMain'];
                }
            );
        }

        if (count($variations) > 1) {
            $variations = array_filter(
                $variations,
                static function (array $variation) {
                    return !empty($variation['variationAttributeValues']);
                }
            );
        }

        usort(
            $variations,
            static function (array $a, array $b) {
                if ((int) $a['position'] === (int) $b['position']) {
                    return 0;
                }

                return ((int) $a['position'] < (int) $b['position']) ? -1 : 1;
            }
        );

        $result = [];

        foreach ($variations as $variation) {
            $identity = $this->identityService->findOneOrCreate(
                (string) $variation['id'],
                PlentymarketsAdapter::NAME,
                Variation::TYPE
            );

            $variationObject = new Variation();
            $variationObject->setIdentifier($identity->getObjectIdentifier());
            $variationObject->setProductIdentifier($productIdentity->getObjectIdentifier());
            $variationObject->setActive((bool) $variation['isActive']);
            $variationObject->setNumber($this->getVariationNumber($variation));
            $variationObject->setStockLimitation($variation['stockLimitation'] === 1);
            $variationObject->setBarcodes($this->getBarcodes($variation));
            $variationObject->setPosition((int) $variation['position']);
            $variationObject->setModel((string) $variation['model']);
            $variationObject->setImages($this->getVariationImages($product['texts'], $variation, $result));
            $variationObject->setPrices($this->priceResponseParser->parse($variation));
            $variationObject->setPurchasePrice((float) $variation['purchasePrice']);
            $variationObject->setUnitIdentifier($this->getUnitIdentifier($variation));
            $variationObject->setContent((float) $variation['unit']['content']);
            $variationObject->setReferenceAmount($this->referenceAmountCalculator->calculate($variation));
            $variationObject->setMaximumOrderQuantity((float) $variation['maximumOrderQuantity']);
            $variationObject->setMinimumOrderQuantity((float) $variation['minimumOrderQuantity']);
            $variationObject->setIntervalOrderQuantity((float) $variation['intervalOrderQuantity']);
            $variationObject->setReleaseDate($this->getReleaseDate($variation));
            $variationObject->setShippingTime($this->getShippingTime($variation));
            $variationObject->setWidth((int) $variation['widthMM']);
            $variationObject->setHeight((int) $variation['heightMM']);
            $variationObject->setLength((int) $variation['lengthMM']);
            $variationObject->setWeight($this->getVariationWeight($variation));
            $variationObject->setProperties($this->getVariationProperties($variation));

            $stockObject = $this->stockResponseParser->parse($variation);

            if (null === $stockObject) {
                continue;
            }

            $importVariationsWithoutStock = json_decode(
                $this->configService->get('import_variations_without_stock', true),
                512
            );

            if (!$importVariationsWithoutStock && empty($stockObject->getStock())) {
                continue;
            }

            $result[$variationObject->getIdentifier()] = $variationObject;
            $result[$stockObject->getIdentifier()] = $stockObject;
        }

        $variations = array_filter(
            $result,
            static function (TransferObjectInterface $object) {
                return $object instanceof Variation;
            }
        );

        $mainVariationNumber = $this->variationHelper->getMainVariationNumber($mainVariation, $variations);

        /**
         * @var Variation $variation
         */
        foreach ($variations as &$variation) {
            if ($variation->getNumber() === $mainVariationNumber) {
                $variation->setIsMain(true);

                $checkActiveMainVariation = json_decode($this->configService->get('check_active_main_variation'), 512);

                if ($checkActiveMainVariation && !$mainVariation['isActive']) {
                    $variation->setActive(false);
                }

                break;
            }
        }

        return $result;
    }

    /**
     * @param array $element
     *
     * @return string
     */
    private function getVariationNumber(array $element): string
    {
        if ($this->configService->get('variation_number_field', 'number') === 'number') {
            return (string) $element['number'];
        }

        return (string) $element['id'];
    }

    /**
     * @param array $variation
     *
     * @return null|DateTimeImmutable
     */
    private function getReleaseDate(array $variation)
    {
        try {
            # 2018-01-18 BVK change release date to be based on the position
            if (null !== $variation['position']) {
                $position = $variation['position'];
                return new DateTimeImmutable("@$position", new DateTimeZone("Europe/Berlin"));
            }
            return new DateTimeImmutable($variation['releasedAt']);
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * @param array $texts
     * @param array $variation
     * @param array $result
     *
     * @return Image[]
     */
    private function getVariationImages(array $texts, array $variation, array &$result): array
    {
        $images = [];

        foreach ((array) $variation['images'] as $entry) {
            $images[] = $this->imageResponseParser->parseImage($entry, $texts, $result);
        }

        return array_filter($images);
    }

    /**
     * @param array $variation
     *
     * @throws NotFoundException
     *
     * @return null|string
     */
    private function getUnitIdentifier(array $variation)
    {
        if (empty($variation['unit'])) {
            return null;
        }

        // Unit
        $unitIdentity = $this->identityService->findOneBy(
            [
                'adapterIdentifier' => (string) $variation['unit']['unitId'],
                'adapterName' => PlentymarketsAdapter::NAME,
                'objectType' => Unit::TYPE,
            ]
        );

        if (null === $unitIdentity) {
            throw new NotFoundException('missing mapping for unit');
        }

        return $unitIdentity->getObjectIdentifier();
    }

    /**
     * @param array $variation
     *
     * @return int
     */
    private function getShippingTime(array $variation): int
    {
        static $shippingConfigurations;

        if (null === $shippingConfigurations) {
            $shippingConfigurations = $this->availabilitiesApi->findAll();
        }

        $shippingConfiguration = array_filter(
            $shippingConfigurations,
            static function (array $configuration) use ($variation) {
                return $configuration['id'] === $variation['availability'];
            }
        );

        if (empty($shippingConfiguration)) {
            return 0;
        }

        $shippingConfiguration = array_shift($shippingConfiguration);

        if (empty($shippingConfiguration['averageDays'])) {
            return 0;
        }

        return (int) $shippingConfiguration['averageDays'];
    }

    /**
     * @param array $variation
     *
     * @return Barcode[]
     */
    private function getBarcodes(array $variation): array
    {
        static $barcodeMapping;

        if (null === $barcodeMapping) {
            $systemBarcodes = $this->itemBarcodeApi->findAll();

            foreach ($systemBarcodes as $systemBarcode) {
                $typeMapping = [
                    'GTIN_13' => Barcode::TYPE_GTIN13,
                    'GTIN_128' => Barcode::TYPE_GTIN128,
                    'UPC' => Barcode::TYPE_UPC,
                    'ISBN' => Barcode::TYPE_ISBN,
                ];

                if (array_key_exists($systemBarcode['type'], $typeMapping)) {
                    $barcodeMapping[$systemBarcode['id']] = $typeMapping[$systemBarcode['type']];
                }
            }

            $barcodeMapping = array_filter($barcodeMapping);
        }

        $barcodes = array_filter(
            $variation['variationBarcodes'],
            static function (array $barcode) use ($barcodeMapping) {
                return array_key_exists($barcode['barcodeId'], $barcodeMapping);
            }
        );

        $barcodes = array_map(
            static function (array $barcode) use ($barcodeMapping) {
                $barcodeObject = new Barcode();
                $barcodeObject->setType($barcodeMapping[$barcode['barcodeId']]);
                $barcodeObject->setCode($barcode['code']);

                return $barcodeObject;
            },
            $barcodes
        );

        return $barcodes;
    }

    /**
     * @param array $variation
     *
     * @return Property[]
     */
    private function getVariationProperties(array $variation): array
    {
        static $attributes;

        $result = [];
        foreach ((array) $variation['variationAttributeValues'] as $attributeValue) {
            if (!isset($attributes[$attributeValue['attributeId']])) {
                $attributes[$attributeValue['attributeId']] = $this->itemAttributesApi->findOne(
                    $attributeValue['attributeId']
                );
            }

            $values = $attributes[$attributeValue['attributeId']]['values'];

            $attributes[$attributeValue['attributeId']]['values'] = [];

            foreach ((array) $values as $value) {
                $attributes[$attributeValue['attributeId']]['values'][$value['id']] = $value;
            }

            if (!isset($attributes[$attributeValue['attributeId']]['values'][$attributeValue['valueId']]['valueNames'])) {
                continue;
            }

            $propertyNames = $attributes[$attributeValue['attributeId']]['attributeNames'];
            $propertyPosition = $attributes[$attributeValue['attributeId']]['position'];
            $valueNames = $attributes[$attributeValue['attributeId']]['values'][$attributeValue['valueId']]['valueNames'];
            $valuePosition = $attributes[$attributeValue['attributeId']]['values'][$attributeValue['valueId']]['position'];

            $value = Value::fromArray(
                [
                    'value' => $valueNames[0]['name'],
                    'position' => $valuePosition,
                    'translations' => $this->getVariationPropertyValueTranslations($valueNames),
                ]
            );

            $result[] = Property::fromArray(
                [
                    'name' => $propertyNames[0]['name'],
                    'position' => $propertyPosition,
                    'values' => [$value],
                    'translations' => $this->getVariationPropertyTranslations($propertyNames),
                ]
            );
        }

        return $result;
    }

    /**
     * @param array $names
     *
     * @return Translation[]
     */
    private function getVariationPropertyValueTranslations(array $names): array
    {
        $translations = [];

        foreach ($names as $name) {
            $languageIdentifier = $this->identityService->findOneBy(
                [
                    'adapterIdentifier' => $name['lang'],
                    'adapterName' => PlentymarketsAdapter::NAME,
                    'objectType' => Language::TYPE,
                ]
            );

            if (null === $languageIdentifier) {
                continue;
            }

            $translations[] = Translation::fromArray(
                [
                    'languageIdentifier' => $languageIdentifier->getObjectIdentifier(),
                    'property' => 'value',
                    'value' => $name['name'],
                ]
            );
        }

        return $translations;
    }

    /**
     * @param array $names
     *
     * @return Translation[]
     */
    private function getVariationPropertyTranslations(array $names): array
    {
        $translations = [];

        foreach ($names as $name) {
            $languageIdentifier = $this->identityService->findOneBy(
                [
                    'adapterIdentifier' => $name['lang'],
                    'adapterName' => PlentymarketsAdapter::NAME,
                    'objectType' => Language::TYPE,
                ]
            );

            if (null === $languageIdentifier) {
                continue;
            }

            $translations[] = Translation::fromArray(
                [
                    'languageIdentifier' => $languageIdentifier->getObjectIdentifier(),
                    'property' => 'name',
                    'value' => $name['name'],
                ]
            );
        }

        return $translations;
    }

    /**
     * @param array $variation
     *
     * @return float
     */
    private function getVariationWeight(array $variation): float
    {
        if ($variation['weightNetG'] > 0) {
            $weight = $variation['weightNetG'];
        } else {
            $weight = $variation['weightG'];
        }

        return (float) ($weight / 1000);
    }
}
