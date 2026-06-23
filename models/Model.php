<?php
require_once __DIR__ . '/../db.php';

abstract class Model {
    protected static $table = '';
    protected static $primaryKey = 'id';
    /**
     * Whitelist of allowed column names for where() queries.
     * Override in child models to restrict queryable columns.
     */
    protected static $allowedColumns = [];
    protected $attributes = [];
    protected $original = [];

    private static $SAFE_OPERATORS = ['=', '!=', '<', '>', '<=', '>=', 'LIKE', 'IN', 'IS', 'IS NOT'];

    public function __construct(array $attributes = []) {
        $this->fill($attributes);
    }

    public function fill(array $attributes) {
        foreach ($attributes as $key => $value) {
            $this->attributes[$key] = $value;
        }
    }

    public function __get($key) {
        return $this->attributes[$key] ?? null;
    }

    public function __set($key, $value) {
        $this->attributes[$key] = $value;
    }

    public function getAttributes() {
        return $this->attributes;
    }

    public static function find($id) {
        $pdo = get_db();
        $stmt = $pdo->prepare("SELECT * FROM " . static::$table . " WHERE " . static::$primaryKey . " = :id");
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $instance = new static($row);
            $instance->original = $row;
            return $instance;
        }
        return null;
    }

    public static function all() {
        $pdo = get_db();
        $stmt = $pdo->query("SELECT * FROM " . static::$table);
        $results = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $instance = new static($row);
            $instance->original = $row;
            $results[] = $instance;
        }
        return $results;
    }

    public static function where($column, $operator, $value = null) {
        if ($value === null) {
            $value = $operator;
            $operator = '=';
        }

        // Sanitize column name: must be in the allowed list (if defined) and match safe pattern
        if (!empty(static::$allowedColumns) && !in_array($column, static::$allowedColumns, true)) {
            throw new \InvalidArgumentException("Column '$column' is not allowed for querying on " . static::$table);
        }
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $column)) {
            throw new \InvalidArgumentException("Invalid column name: '$column'");
        }

        // Sanitize operator
        $operator = strtoupper(trim($operator));
        if (!in_array($operator, self::$SAFE_OPERATORS, true)) {
            throw new \InvalidArgumentException("Unsafe SQL operator: '$operator'");
        }

        $pdo = get_db();
        $stmt = $pdo->prepare("SELECT * FROM " . static::$table . " WHERE $column $operator :value");
        $stmt->execute(['value' => $value]);
        $results = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $instance = new static($row);
            $instance->original = $row;
            $results[] = $instance;
        }
        return $results;
    }

    public static function create(array $attributes) {
        $instance = new static($attributes);
        $instance->save();
        return $instance;
    }

    public function save() {
        $pdo = get_db();
        $pk = static::$primaryKey;

        if (empty($this->original)) {
            // Insert
            $keys = array_keys($this->attributes);
            $fields = implode(', ', $keys);
            $placeholders = ':' . implode(', :', $keys);

            if (!in_array($pk, $keys)) {
                $this->attributes[$pk] = bin2hex(random_bytes(16));
                $keys[] = $pk;
                $fields = implode(', ', $keys);
                $placeholders = ':' . implode(', :', $keys);
            }

            $sql = "INSERT INTO " . static::$table . " ($fields) VALUES ($placeholders)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($this->attributes);
            
            $this->original = $this->attributes;
        } else {
            // Update
            $updates = [];
            foreach ($this->attributes as $key => $value) {
                if ($key !== $pk) {
                    $updates[] = "$key = :$key";
                }
            }
            if (empty($updates)) return;
            
            $sql = "UPDATE " . static::$table . " SET " . implode(', ', $updates) . " WHERE $pk = :pk_val";
            $params = $this->attributes;
            $params['pk_val'] = $this->original[$pk];
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            
            $this->original = $this->attributes;
        }
    }

    public function delete() {
        if (empty($this->original)) return;
        
        $pdo = get_db();
        $pk = static::$primaryKey;
        $stmt = $pdo->prepare("DELETE FROM " . static::$table . " WHERE $pk = :id");
        $stmt->execute(['id' => $this->original[$pk]]);
    }
}
