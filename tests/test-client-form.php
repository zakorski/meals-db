<?php
require_once __DIR__ . '/../includes/class-db.php';
require_once __DIR__ . '/../includes/class-encryption.php';
require_once __DIR__ . '/../includes/class-client-form.php';

putenv('PLUGIN_AES_KEY=base64:' . base64_encode(str_repeat('k', 32)));

if (!function_exists('get_current_user_id')) {
    function get_current_user_id(): int {
        return 1234;
    }
}

class StubResult {
    public $num_rows;
    private $rows;
    private $position = 0;

    public function __construct(int $num_rows, array $rows = []) {
        $this->num_rows = $num_rows;
        $this->rows = array_values($rows);
    }

    public function fetch_assoc(): ?array {
        if ($this->position >= count($this->rows)) {
            return null;
        }

        return $this->rows[$this->position++];
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
    public int|string $affected_rows = 0;

    public function __construct(StubMysqli $conn, string $sql) {
        $this->conn = $conn;
        $this->sql = $sql;
    }

    public function bind_param($types, &...$vars): bool {
        $this->params = $vars;
        return true;
    }

    public function execute(): bool {
        $sql = $this->sql;

        if (stripos($sql, 'SELECT id FROM meals_clients') === 0) {
            if (preg_match('/WHERE\s+`?([a-z_]+)`?\s*=\s*\?/i', $sql, $matches)) {
                $column = $matches[1];
                $value = $this->params[0] ?? null;
                $this->num_rows = ($value !== null && $this->conn->hasValue($column, $value)) ? 1 : 0;
            }

            return true;
        }

        if (stripos($sql, 'INSERT INTO meals_clients') === 0) {
            if (preg_match('/\(([^\)]+)\)\s*VALUES/i', $sql, $matches)) {
                $columns = array_map('trim', explode(',', $matches[1]));
                $columns = array_map(static function ($col) {
                    return trim($col, "` ");
                }, $columns);
                $this->conn->lastInsert = array_combine($columns, $this->params);
            }

            return true;
        }

        if (stripos($sql, 'INSERT INTO meals_drafts') === 0) {
            $json = $this->params[0] ?? '';
            $user = $this->params[1] ?? 0;
            $this->conn->createDraft($json, (int) $user);
            $this->affected_rows = 1;
            return true;
        }

        if (stripos($sql, 'UPDATE meals_drafts SET') === 0) {
            $json = $this->params[0] ?? '';
            $id = (int) ($this->params[1] ?? 0);
            $result = $this->conn->updateDraft($id, $json);
            $this->affected_rows = $result['exists'] && $result['changed'] ? 1 : 0;
            return true;
        }

        if (stripos($sql, 'DELETE FROM meals_drafts') === 0) {
            $id = (int) ($this->params[0] ?? 0);
            $deleted = $this->conn->deleteDraft($id);
            $this->affected_rows = $deleted ? 1 : 0;
            return true;
        }

        if (stripos($sql, 'SELECT id FROM meals_drafts') === 0) {
            $id = (int) ($this->params[0] ?? 0);
            $this->num_rows = $this->conn->hasDraft($id) ? 1 : 0;
            return true;
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
    private $drafts = [];
    private $nextDraftId = 1;
    public $lastInsert = [];
    public int|string $affected_rows = 0;
    public int|string $insert_id = 0;

    public function __construct(
        array $existingValues = [],
        array $existingColumns = [],
        array $existingIndexes = [],
        array $drafts = []
    ) {
        $this->existingValues = $existingValues;
        $this->existingColumns = $existingColumns;
        $this->existingIndexes = $existingIndexes;

        foreach ($drafts as $draft) {
            $id = (int) ($draft['id'] ?? 0);
            if ($id <= 0) {
                $id = $this->nextDraftId++;
            } else {
                $this->nextDraftId = max($this->nextDraftId, $id + 1);
            }

            $this->drafts[$id] = [
                'data' => (string) ($draft['data'] ?? ''),
                'created_by' => (int) ($draft['created_by'] ?? 0),
            ];
        }
    }

    public function hasValue(string $column, string $value): bool {
        return in_array($value, $this->existingValues[$column] ?? [], true);
    }

    public function real_escape_string(string $value): string {
        return addslashes($value);
    }

    public function createDraft(string $json, int $user): int {
        $id = $this->nextDraftId++;
        $this->drafts[$id] = [
            'data' => $json,
            'created_by' => $user,
        ];
        $this->affected_rows = 1;

        return $id;
    }

    public function updateDraft(int $id, string $json): array {
        if (!isset($this->drafts[$id])) {
            return ['exists' => false, 'changed' => false];
        }

        $changed = $this->drafts[$id]['data'] !== $json;
        $this->drafts[$id]['data'] = $json;
        return ['exists' => true, 'changed' => $changed];
    }

    public function deleteDraft(int $id): bool {
        if (!isset($this->drafts[$id])) {
            return false;
        }

        unset($this->drafts[$id]);
        return true;
    }

    public function hasDraft(int $id): bool {
        return isset($this->drafts[$id]);
    }

    public function draftData(int $id): ?string {
        return $this->drafts[$id]['data'] ?? null;
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

        if (stripos($sql, 'CREATE UNIQUE INDEX') === 0 || stripos($sql, 'CREATE INDEX') === 0) {
            if (preg_match('/`([a-z_]+)`/', $sql, $matches)) {
                $this->existingIndexes[] = $matches[1];
            }
            return true;
        }

        return new StubResult(0);
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
    ], ['individual_id_index', 'requisition_id_index', 'vet_health_card_index', 'delivery_initials_index'], ['idx_individual_id_index', 'idx_requisition_id_index', 'idx_vet_health_card_index', 'idx_delivery_initials_index']);
    set_db_connection($conn);

    $method = new ReflectionMethod(MealsDB_Client_Form::class, 'check_unique_fields');
    $method->setAccessible(true);
    $errors = $method->invoke(null, ['individual_id' => 'ABC123']);

    if (empty($errors) || stripos($errors[0], 'Individual id') === false) {
        throw new Exception('Expected duplicate error for individual_id.');
    }
});

run_test('detects duplicate vet health card via deterministic hash', function () {
    reset_index_flag();
    $hash = hash('sha256', strtolower(trim('VH-999')));
    $conn = new StubMysqli([
        'vet_health_card_index' => [$hash]
    ], ['individual_id_index', 'requisition_id_index', 'vet_health_card_index', 'delivery_initials_index'], ['idx_individual_id_index', 'idx_requisition_id_index', 'idx_vet_health_card_index', 'idx_delivery_initials_index']);
    set_db_connection($conn);

    $method = new ReflectionMethod(MealsDB_Client_Form::class, 'check_unique_fields');
    $method->setAccessible(true);
    $errors = $method->invoke(null, ['vet_health_card' => 'VH-999']);

    if (empty($errors) || stripos($errors[0], 'Vet health card') === false) {
        throw new Exception('Expected duplicate error for vet_health_card.');
    }
});

run_test('detects duplicate delivery initials via deterministic hash', function () {
    reset_index_flag();
    $hash = hash('sha256', strtolower(trim('AB')));
    $conn = new StubMysqli([
        'delivery_initials_index' => [$hash]
    ], ['individual_id_index', 'requisition_id_index', 'vet_health_card_index', 'delivery_initials_index'], ['idx_individual_id_index', 'idx_requisition_id_index', 'idx_vet_health_card_index', 'idx_delivery_initials_index']);
    set_db_connection($conn);

    $method = new ReflectionMethod(MealsDB_Client_Form::class, 'check_unique_fields');
    $method->setAccessible(true);
    $errors = $method->invoke(null, ['delivery_initials' => 'AB']);

    if (empty($errors) || stripos($errors[0], 'Delivery initials') === false) {
        throw new Exception('Expected duplicate error for delivery_initials.');
    }
});

run_test('allows unique individual_id when hash not present', function () {
    reset_index_flag();
    $conn = new StubMysqli([], ['individual_id_index', 'requisition_id_index', 'vet_health_card_index', 'delivery_initials_index'], ['idx_individual_id_index', 'idx_requisition_id_index', 'idx_vet_health_card_index', 'idx_delivery_initials_index']);
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
    $conn = new StubMysqli([], ['individual_id_index', 'requisition_id_index', 'vet_health_card_index', 'delivery_initials_index'], ['idx_individual_id_index', 'idx_requisition_id_index', 'idx_vet_health_card_index', 'idx_delivery_initials_index']);
    set_db_connection($conn);

    $data = [
        'individual_id' => 'ID-001',
        'requisition_id' => 'REQ-002',
        'vet_health_card' => 'VH-123',
        'delivery_initials' => 'AB',
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

    $expectedVetCard = hash('sha256', strtolower(trim('VH-123')));
    if (($conn->lastInsert['vet_health_card_index'] ?? null) !== $expectedVetCard) {
        throw new Exception('Missing deterministic hash for vet_health_card.');
    }

    $expectedInitials = hash('sha256', strtolower(trim('AB')));
    if (($conn->lastInsert['delivery_initials_index'] ?? null) !== $expectedInitials) {
        throw new Exception('Missing deterministic hash for delivery_initials.');
    }
});

run_test('save_draft updates existing record when id provided', function () {
    $existingId = 5;
    $original = json_encode(['first_name' => 'Original']);
    $conn = new StubMysqli([], [], [], [
        ['id' => $existingId, 'data' => $original, 'created_by' => 321],
    ]);
    set_db_connection($conn);

    $updatedId = MealsDB_Client_Form::save_draft(['last_name' => 'Updated'], $existingId);

    if ($updatedId !== $existingId) {
        throw new Exception('Draft update did not return same id.');
    }

    $decoded = json_decode($conn->draftData($existingId), true);
    if (!isset($decoded['last_name']) || $decoded['last_name'] !== 'Updated') {
        throw new Exception('Draft update did not persist new payload.');
    }
});

run_test('delete_draft removes stored draft and reports missing ids', function () {
    $existingId = 7;
    $conn = new StubMysqli([], [], [], [
        ['id' => $existingId, 'data' => json_encode(['note' => 'keep me'])],
    ]);
    set_db_connection($conn);

    if (!MealsDB_Client_Form::delete_draft($existingId)) {
        throw new Exception('Expected delete_draft to return true for existing record.');
    }

    if (MealsDB_Client_Form::delete_draft(999) !== false) {
        throw new Exception('Expected delete_draft to fail for missing record.');
    }
});

reset_index_flag();
set_db_connection(null);

echo "All tests passed.\n";
