<?php

namespace Jagusiak\JSONSimpleStorage;

/**
 * Stores objects in json format
 * 
 * @author Seweryn Jagusiak <jagusiak@gmail.com>
 */
abstract class JSONSimpleStorage {
    
    /**
     * Class name 
     * @var string 
     */
    private $class;
    
    /**
     * Id generator (it is not used when ids are given from outside), starts from 0
     * @var int 
     */
    private $idGen;
    
    /**
     * Array used to determine relation 'one'
     * Its structure is as follows:
     * [record_id][foreign_storage] = foreign_id
     * - record_id - current storage record id
     * - foreign_sotrage - classname of foreign stroage (it can be the same in case of self relation)
     * - foreign_id - id of record in foreign storage is in relation one
     * @var array[] 
     */
    private $one;
    
    /**
     * Array used to determine relation 'many'
     * Its structure is as follows:
     * [record_id][foreign_storage][] = foreign_id
     * - record_id - current storage record id
     * - foreign_sotrage - classname of foreign stroage (it can be the same in case of self relation)
     * - foreign_id - id of record in foreign storage which is in relation many
     * @var array[] 
     */
    private $many;
    
    /**
     * Array which stores records, key = id, value = record
     * @var array
     */
    private $data;
    
    /**
     * Stores all instances (each class is used as singleton)
     * @var JSONStorage[] instances store 
     */
    private static $instances = [];
    
    /**
     * Determines if class data should be saved
     * @var bool[] Array maps classnames to true
     */
    private static $dirty = [];
    
    
    /**
     * JSON Store fileds names
     */
    const FIELD_IDGEN = 'idGen';
    const FIELD_DATA = 'data';
    const FIELD_MANY = 'many';
    const FIELD_ONE = 'one';
    
    // Determines where json is stored (it is the same directory as class by default)
    const STORE_DIR = '';
    
    /**
     * Default constructor
     */
    private function __construct() {
        // store class name
        $this->class = get_class($this); 
        
        // read file
        if (file_exists($filename = static::STORE_DIR . $this->class . '.json')) {
            $content = json_decode(file_get_contents($filename), true);
        } else {
            $content = [];
        }
        
        // load data
        $this->idGen = self::getField($content, self::FIELD_IDGEN, 0);
        $this->data = self::getField($content, self::FIELD_DATA, []);
        $this->many = self::getField($content, self::FIELD_MANY, []);
        $this->one = self::getField($content, self::FIELD_ONE, []);
        
    }
    
    /**
     * Sets object in storage, works like:
     * - create - when id is not data
     * - update - when id is in data
     * 
     * @param array $data data to store
     * @param mixed $id id of data, when null, id generator is used
     * @return mixed assigned id of data
     */
    public function set(array $data, $id = null) {
        $id = null === $id ? $this->idGen++ : $id;
        $this->data[$id] = $data;
        self::$dirty[$this->class] = true;
        return $id;
    }
    
    /**
     * Deletes data
     * 
     * @param mixed $id Element identifier
     * @param bool $cascade If true, data which has one this object will be deleted
     */
    public function delete($id, $cascade = false) {
        
        // remove has one
        if (isset($this->one[$id])) {
            // iterate through all data
            foreach ($this->one[$id] as $class => $foreignId) {
                $object = $class::getInstance();
                // remove entry in many
                if (null !== ($key = array_search($id, $object->many[$foreignId][$this->class]))) {
                    unset($object->many[$foreignId][$this->class][$key]);
                    self::$dirty[$class] = true;
                }
            }
            unset($this->one[$id]);
        }
        
        // remove has many
        if (isset($this->many[$id])) {
            // iterate through many
            foreach ($this->many[$id] as $class => $foreignIds) {
                $object = $class::getInstance();
                if ($cascade) {
                    // delete objects
                    foreach ($foreignIds as $foreignId) {
                        $object->delete($foreignId, $cascade);
                    }
                } else {
                    // 'only' unset
                    foreach ($foreignIds as $foreignId) {
                        unset($object->one[$foreignId][$this->class][$id]);
                    }
                }
                self::$dirty[$class] = true;
            }
            unset($this->many[$id]);
        }
        
        // unset element
        unset($this->data[$id]);
        self::$dirty[$this->class] = true;
    }
    
    /**
     * Gets all ids which are stored
     * 
     * @return mixed[]
     */
    public function getIds() {
        return array_keys($this->data);
    }
    
    /**
     * Gets all stored data
     * 
     * @return array
     */
    public function getAll() {
        return $this->data;
    }
    
    /**
     * Gets data by id
     * 
     * @param mixed $id Identifier of data
     * @param bool $cascade If true it will join result data with related data
     * @return array[]
     */
    public function getById($id, $cascade = false) {
        $result = self::getField($this->data, $id, null);
        if ($cascade && null !== $result && isset($this->one[$id])) {
            foreach ($this->one[$id] as $class => $foreignId) {
                $result = array_merge($class::getInstance()->getById($foreignId, $cascade), $result);
            }
        }
        return $result;
    }
    
    /**
     * Gets all records which has one object
     * 
     * @param \JSONSimpleStorage $object
     * @param mixed $foreignId
     * @param bool $cascade
     * @return array[]
     */
    public function getAllWhichHasOne($foreignId, \JSONSimpleStorage $object, $cascade = false) {
        // default value
        $result = [];
        
        // check if foreign object has relations with current one
        if (isset($object->many[$foreignId][$this->class])) {
            // iterate through all related data
            foreach ($object->many[$foreignId][$this->class] as $id) {
                if (null !== ($item = $this->getById($id, $cascade))) {
                    $result[$id] = $item;
                }
            }
        }
        return $result;
    }
    
    /**
     * Sets relation has one
     * 
     * @param mixed $id
     * @param \JSONSimpleStorage $object
     * @param mixed $foreignId
     */
    public function hasOne($id, \JSONSimpleStorage $object, $foreignId) {
        // init one filed
        if (!isset($this->one[$id])) {
            $this->one[$id] = [];
        }
        
        // init many field
        if (!isset($object->many[$foreignId])) {
            $object->many[$foreignId] = [];
            $object->many[$foreignId][$this->class] = [];
        } else if (!isset($object->many[$foreignId][$this->class])) {
            $object->many[$foreignId][$this->class] = [];
        }
        
        // set fields
        $this->one[$id][$object->class] = $foreignId;
        if (!in_array($id, $object->many[$foreignId][$this->class])) {
            $object->many[$foreignId][$this->class][] = $id;
        }
        
        // set dirty objects
        self::$dirty[$object->class] = true;
        self::$dirty[$this->class] = true;
    }
    
    /**
     * Sets has many relation
     * 
     * @param mixed $id
     * @param \JSONSimpleStorage $object
     * @param mixed[] $foreignIds
     */
    public function hasMany($id, \JSONSimpleStorage $object, array $foreignIds) {
        // set has one on foreign data
        foreach ($foreignIds as $foreignId) {
            $object->hasOne($foreignId, $this, $id);
        }
    }
    
    /**
     * Stores data in file
     */
    private function store() {
        file_put_contents(static::STORE_DIR . $this->class . '.json', json_encode([
            self::FIELD_IDGEN => $this->idGen,
            self::FIELD_DATA => $this->data,
            self::FIELD_MANY => $this->many,
            self::FIELD_ONE => $this->one,
        ]));
    }
    
    /**
     * Return instance of class
     * 
     * @return \JSONSimpleStorage
     */
    public static final function getInstance() {
        $class = get_called_class();
        return empty(self::$instances[$class]) ? self::$instances[$class] = new $class() : self::$instances[$class]; 
    }
    
    /**
     * Saves data (all!)
     */
    public static final function save() {
        foreach (self::$dirty as $class => $true) {
            self::$instances[$class]->store();
            unset(self::$dirty[$class]);
        }
    }
    
    /**
     * Retrieves data from array with setting default value
     * 
     * @param array $data
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    private static final function getField($data, $key, $default = null) {
        return isset($data[$key]) ? $data[$key] : $default; 
    }
    
}