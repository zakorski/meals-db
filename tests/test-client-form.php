<?php
require_once __DIR__ . '/../includes/class-db.php';
require_once __DIR__ . '/../includes/class-encryption.php';
require_once __DIR__ . '/../includes/class-client-form.php';

putenv('PLUGIN_AES_KEY=base64:' . base64_encode(str_repeat('k', 32)));

class StubResult {
    public $num_rows;

    public function __construct(int $num_rows) {
        $this->num_rows = $num_rows;
    }

    public function free(): void {
        // no-op for stub
    }
}

class StubStmt {
    private $conn;
    private $sql;
    private $params = [];
    public $num_rows = 0;

    public function __construct(StubMysqli $conn, string $sql) {
        $this->conn = $conn;
        $this->sql = $sql;
    }

    public function bind_param($types, ...$vars): void {
        $this->params = $vars;
    }

    public function execute(): bool {
        if (stripos($this->sql, 'SELECT id FROM meals_clients') === 0) {
            if (preg_match('/WHERE\s+`?([a-z_]+)`?\s*=\s*\?/i', $this->sql, $matches)) {
                $column = $matches[1];
                $value = $this->params[0] ?? null;
                if ($value !== null && $this->conn->hasValue($column, $value)) {
                    $this->num_rows = 1;
                } else {
                    $this->num_rows = 0;
                }
            }
        } elseif (stripos($this->sql, 'INSERT INTO meals_clients') === 0) {
            if (preg_match('/\(([^\)]+)\)\s*VALUES/i', $this->sql, $matches)) {
                $columns = array_map('trim', explode(',', $matches[1]));
                $columns = array_map(static function ($col) {
                    return trim($col, "` ");
                }, $columns);
                $this->conn->lastInsert = array_combine($columns, $this->params);
            }
        }

        return true;
    }

    public function store_result(): void {
        // no-op for stub
    }

    public function close(): void {
        // no-op for stub
    }
}

class StubMysqli extends mysqli {
    private $existingValues;
    private $existingColumns;
    private $existingIndexes;
    public $lastInsert = [];

    public function __construct(array $existingValues = [], array $existingColumns = [], array $existingIndexes = []) {
        $this->existingValues = $existingValues;
        $this->existingColumns = $existingColumns;
        $this->existingIndexes = $existingIndexes;
    }

    public function hasValue(string $column, string $value): bool {
        return in_array($value, $this->existingValues[$column] ?? [], true);
    }

    public function real_escape_string(string $value): string {
        return addslashes($value);
    }

    #[\ReturnTypeWillChange]
    public function query(string $sql, int $result_mode = MYSQLI_STORE_RESULT) {
        if (stripos($sql, 'SHOW COLUMNS') === 0) {
            if (preg_match("/LIKE '([^']+)'/i", $sql, $matches)) {
                $column = stripslashes($matches[1]);
                $exists = in_array($column, $this->existingColumns, true);
                return new StubResult($exists ? 1 : 0);
            }

            return new StubResult(0);
        }

        if (stripos($sql, 'ALTER TABLE') === 0) {
            if (preg_match('/`([a-z_]+)` CHAR/', $sql, $matches)) {
                $this->existingColumns[] = $matches[1];
            }
            return true;
        }

        if (stripos($sql, 'SHOW INDEX') === 0) {
            if (preg_match("/Key_name = '([^']+)'/i", $sql, $matches)) {
                $index = stripslashes($matches[1]);
                $exists = in_array($index, $this->existingIndexes, true);
                return new StubResult($exists ? 1 : 0);
            }
            return new StubResult(0);
        }

        if (stripos($sql, 'CREATE INDEX') === 0) {
            if (preg_match('/`(idx_[a-z_]+)`/', $sql, $matches)) {
                $this->existingIndexes[] = $matches[1];
            }
            return true;
        }

        return false;
    }

    #[\ReturnTypeWillChange]
    public function prepare(string $sql)
    {
        return new StubStmt($this, $sql);
    }
}

function set_db_connection($conn): void {
    $reflection = new ReflectionClass(MealsDB_DB::class);
    $property = $reflection->getProperty('connection');
    $property->setAccessible(true);
    $property->setValue(null, $conn);
}

function reset_index_flag(): void {
    $reflection = new ReflectionClass(MealsDB_Client_Form::class);
    $property = $reflection->getProperty('indexes_ensured');
    $property->setAccessible(true);
    $property->setValue(null, false);
}

function run_test(string $description, callable $callback): void {
    try {
        $callback();
        echo "[PASS] {$description}\n";
    } catch (Throwable $e) {
        echo "[FAIL] {$description}: {$e->getMessage()}\n";
        exit(1);
    }
}

run_test('detects duplicate individual_id via deterministic hash', function () {
    reset_index_flag();
    $hash = hash('sha256', strtolower(trim('ABC123')));
    $conn = new StubMysqli([
        'individual_id_index' => [$hash]
    ], ['individual_id_index', 'requisition_id_index'], ['idx_individual_id_index', 'idx_requisition_id_index']);
    set_db_connection($conn);

    $method = new ReflectionMethod(MealsDB_Client_Form::class, 'check_unique_fields');
    $method->setAccessible(true);
    $errors = $method->invoke(null, ['individual_id' => 'ABC123']);

    if (empty($errors) || stripos($errors[0], 'Individual id') === false) {
        throw new Exception('Expected duplicate error for individual_id.');
    }
});

run_test('allows unique individual_id when hash not present', function () {
    reset_index_flag();
    $conn = new StubMysqli([], ['individual_id_index', 'requisition_id_index'], ['idx_individual_id_index', 'idx_requisition_id_index']);
    set_db_connection($conn);

    $method = new ReflectionMethod(MealsDB_Client_Form::class, 'check_unique_fields');
    $method->setAccessible(true);
    $errors = $method->invoke(null, ['individual_id' => 'NEW123']);

    if (!empty($errors)) {
        throw new Exception('Unexpected error for unique individual_id.');
    }
});

run_test('save stores deterministic indexes for encrypted fields', function () {
    reset_index_flag();
    $conn = new StubMysqli([], ['individual_id_index', 'requisition_id_index'], ['idx_individual_id_index', 'idx_requisition_id_index']);
    set_db_connection($conn);

    $data = [
        'individual_id' => 'ID-001',
        'requisition_id' => 'REQ-002',
        'first_name' => 'Jane'
    ];

    $result = MealsDB_Client_Form::save($data);
    if ($result !== true) {
        throw new Exception('Save did not return true.');
    }

    $expectedIndividual = hash('sha256', strtolower(trim('ID-001')));
    $expectedRequisition = hash('sha256', strtolower(trim('REQ-002')));

    if (($conn->lastInsert['individual_id_index'] ?? null) !== $expectedIndividual) {
        throw new Exception('Missing deterministic hash for individual_id.');
    }

    if (($conn->lastInsert['requisition_id_index'] ?? null) !== $expectedRequisition) {
        throw new Exception('Missing deterministic hash for requisition_id.');
    }
});

reset_index_flag();
set_db_connection(null);

echo "All tests passed.\n";
