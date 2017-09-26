<?php
namespace RW\DynamoDb;
use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Marshaler;
use Aws\Result;

/**
 * Class Model
 */
class Model
{
    protected $data;
    protected $table;
    protected $isNew;
    protected $modifiedColumns;
    protected $isModified;
    protected $dbClient;

    /** @var $marshaller Marshaler */
    public static $marshaller;

    /** @var $client DynamoDbClient */
    public static $client;
    /**
     * Init the db
     * @param $config
     *
     * @return DynamoDbClient
     */
    public static function configure($config)
    {
        $defaultConfig = [
            "region" => "us-west-1",
            "version" => "2012-08-10"
        ];
        $config = array_merge($defaultConfig, $config);
        self::$client = new DynamoDbClient($config);
        self::$marshaller = new Marshaler();

        return self::$client;
    }

    /**
     * Model constructor.
     *
     * @param null $config
     */
    function __construct($config = null)
    {
        if ($config === null && self::$client instanceof DynamoDbClient) {
            $this->dbClient = self::$client;
        } else {
            $defaultConfig = [
                "region" => "us-west-1",
                "version" => "2012-08-10"
            ];
            $config = array_merge($defaultConfig, $config);
            $this->dbClient = new DynamoDbClient($config);
        }
        $this->isNew = true;

        return $this;
    }

    /**
     * Get Property
     *
     * @param $property
     *
     * @return mixed
     */
    public function __get($property)
    {
        if (isset($this->data[$property])) {
            return $this->data[$property];
        }
        return null;
    }

    /**
     * Set Property
     *
     * @param $property
     * @param $value
     */
    public function __set($property, $value)
    {
        if (!empty($this->data[$property]) && $this->isNew === false) {
            $this->modifiedColumns[$property] = true;
        }
        $this->data[$property] = $value;
    }

    /**
     * Set Id
     *
     * @param $id
     * @return $this
     */
    public function setId($id)
    {
        $this->id = $id;
        return $this;
    }

    /**
     * Get Id
     * @return mixed
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Get A dump of data
     */
    public function debug()
    {
        var_dump($this->data);
    }

    /**
     * Return true if the object has been modified.
     *
     * @return bool
     */
    public function isModified()
    {
        return !empty($this->modifiedColumns);
    }

    /**
     * Get DynamoDB client
     * @param $table
     *
     */
    public static function init($table)
    {
        self::$marshaller = new Marshaler();

        $class = get_called_class();
        $class::$client = new DynamoDbClient($table);
        $class::$table = $table['name'];
    }

    /**
     * Populate Item into object
     *
     * @param $item
     *
     * @return Model
     */
    public static function populateItemToObject($item)
    {
        if (empty($item)) {
            return null;
        }

        $class = get_called_class();
        $object = new $class();
        foreach (self::$marshaller->unmarshalItem($item) as $k => $v) {
            $object->data[$k] = $v;
        }
        $object->isNew = false;
        return $object;
    }

    /**
     * Get a translation queue item by ID
     *
     * @param $id
     *
     * @return Model
     */
    public static function getById($id)
    {
        $class = get_called_class();

        /** @var $result Result */
        $result = $class::$client->getItem(array(
            'TableName' => $class::$tableName,
            'Key' => array(
                'id' => array('S' => $id)
            ),
            'ConsistentRead' => true,
        ));

        return self::populateItemToObject($result->get('Item'));
    }

    /**
     * Return the array
     *
     * @return mixed
     */
    public function toArray()
    {
        return $this->data;
    }

    /**
     * Convert a collection to array
     *
     * @param $collections
     * @return array
     */
    public static function collectionToArray($collections)
    {
        $collectionData = [];

        foreach ($collections as $collection) {
            $collectionData[] = $collection->toArray();
        }

        return $collectionData;
    }

    /**
     * Delete the object
     *
     * @return bool
     */
    public function delete()
    {
        $class = get_called_class();
        $item = [
            'TableName' => $class::$tableName,
            'Key' => array(
                'id' => array('S' => $this->getId())
            ),
            'ReturnValues' => "NONE",
        ];
        $class::$client->deleteItem($item);

        unset($this);
        return true;
    }

    /**
     * Save
     *
     * @return $this
     */
    public function save()
    {
        $class = get_called_class();
        if ($this->isNew) {
            $this->isNew = false;
            $this->created = time();
            $this->modified = time();

            $item = array(
                'TableName' => $class::$tableName,
                'Item' => self::$marshaller->marshalItem($this->data),
                'ConditionExpression' => 'attribute_not_exists(id)',
                'ReturnValues' => 'ALL_OLD'
            );

            $class::$client->putItem($item);

            return $this;
        }

        if ($this->isModified()) {
            $this->modified = time();

            $expressionAttributeNames = [];
            $expressionAttributeValues = [];
            $updateExpressionHolder = [];
            foreach ($this->modifiedColumns as $field => $hasModified) {
                if ($hasModified === true) {
                    $expressionAttributeNames['#' . $field] = $field;
                    $expressionAttributeValues[':'.$field] = self::$marshaller->marshalValue($this->data[$field]);
                    $updateExpressionHolder[] = "#$field = :$field";

                    $this->modifiedColumns[$field] = false;
                }
            }
            $updateExpression = implode(', ', $updateExpressionHolder);

            $updateAttributes = [
                'TableName' => $class::$tableName,
                'Key' => array(
                    'id' => self::$marshaller->marshalValue($this->id)
                ),
                'ExpressionAttributeNames' =>$expressionAttributeNames,
                'ExpressionAttributeValues' =>  $expressionAttributeValues,
                'ConditionExpression' => 'attribute_exists(id)',
                'UpdateExpression' => "set $updateExpression",
                'ReturnValues' => 'ALL_NEW'
            ];

            $class::$client->updateItem($updateAttributes);
        }

        return $this;
    }
}