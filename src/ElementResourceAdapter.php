<?php

namespace craft\elementapi;

use Craft;
use craft\base\ElementInterface;
use craft\elements\db\ElementQueryInterface;
use craft\helpers\ArrayHelper;
use League\Fractal\Resource\Collection;
use League\Fractal\Resource\Item;
use League\Fractal\Resource\ResourceInterface;
use League\Fractal\TransformerAbstract;
use yii\base\Exception;
use yii\base\InvalidConfigException;
use yii\base\Object;

/**
 * Resource adapter class.
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since  2.0
 */
class ElementResourceAdapter extends Object implements ResourceAdapterInterface
{
    // Properties
    // =========================================================================

    /**
     * @var string The element type class name
     */
    public $elementType;

    /**
     * @var array The element criteria params that should be used to filter the matching elements
     */
    public $criteria = [];

    /**
     * @var callable|string|array|TransformerAbstract The transformer config, or an actual transformer object
     */
    public $transformer = ElementTransformer::class;

    /**
     * @var bool Whether to only return one result
     */
    public $one = false;

    /**
     * @var bool Whether to paginate the results
     */
    public $paginate = true;

    /**
     * @var int The number of elements to include per page
     * @see paginate
     */
    public $elementsPerPage = 100;

    /**
     * @var string The query string param name that should be used to specify the page number
     * @see paginate
     */
    public $pageParam = 'page';

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function __construct(array $config = [])
    {
        if (array_key_exists('first', $config)) {
            $config['one'] = ArrayHelper::remove($config, 'first');
            Craft::$app->getDeprecator()->log('ElementAPI:first', 'The `first` Element API endpoint setting has been renamed to `one`.');
        }

        parent::__construct($config);
    }

    /**
     * @inheritdoc
     * @throws InvalidConfigException
     */
    public function init()
    {
        if ($this->elementType === null || !is_subclass_of($this->elementType, ElementInterface::class)) {
            throw new InvalidConfigException('Endpoint has an invalid elementType');
        }

        if ($this->paginate) {
            // Make sure the page param != the path param
            $pathParam = Craft::$app->getConfig()->getGeneral()->pathParam;
            if ($this->pageParam === $pathParam) {
                throw new InvalidConfigException("The pageParam cannot be set to \"{$pathParam}\" because that's the parameter Craft uses to check the requested path.");
            }
        }
    }

    /**
     * Returns the element query based on [[elementType]] and [[criteria]]
     *
     * @return ElementQueryInterface
     */
    public function getElementQuery(): ElementQueryInterface
    {
        /** @var string|ElementInterface $elementType */
        $elementType = $this->elementType;
        $query = $elementType::find();
        Craft::configure($query, $this->criteria);

        return $query;
    }

    /**
     * Returns the transformer based on the given endpoint
     *
     * @return callable|TransformerAbstract
     */
    public function getTransformer()
    {
        if (is_callable($this->transformer) || $this->transformer instanceof TransformerAbstract) {
            return $this->transformer;
        }

        return Craft::createObject($this->transformer);
    }

    /**
     * @inheritdoc
     * @throws Exception if [[one]] is true and no element matches [[criteria]]
     */
    public function getResource(): ResourceInterface
    {
        $query = $this->getElementQuery();
        $transformer = $this->getTransformer();

        if ($this->one) {
            $element = $query->one();

            if (!$element) {
                throw new Exception('No element exists that matches the endpoint criteria');
            }

            return new Item($element, $transformer);
        }

        if ($this->paginate) {
            // Create the paginator
            $paginator = new PaginatorAdapter($this->elementsPerPage, $query->count(), $this->pageParam);

            // Fetch this page's elements
            $query->offset($this->elementsPerPage * ($paginator->getCurrentPage() - 1));
            $query->limit($this->elementsPerPage);
            $elements = $query->all();
            $paginator->setCount(count($elements));

            $resource = new Collection($elements, $transformer);
            $resource->setPaginator($paginator);

            return $resource;
        }

        return new Collection($query, $transformer);
    }
}
