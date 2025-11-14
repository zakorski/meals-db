<?php
require_once __DIR__ . '/../includes/class-db.php';
require_once __DIR__ . '/../includes/class-encryption.php';
require_once __DIR__ . '/../includes/class-client-form.php';

putenv('PLUGIN_AES_KEY=base64:' . base64_encode(str_repeat('k', 32)));
if (!defined('MEALS_DB_KEY')) {
    define('MEALS_DB_KEY', 'base64:' . base64_encode(str_repeat('k', 32)));
}

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
    public string $error = '';

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
                $missing = array_diff($this->conn->requiredInsertColumns(), $columns);
                if (!empty($missing)) {
                    $missingColumn = reset($missing);
                    $this->error = sprintf("Field '%s' cannot be null", $missingColumn);
                    $this->conn->error = $this->error;
                    return false;
                }
                $this->conn->lastInsert = array_combine($columns, $this->params);
            }

            return true;
        }

        if (stripos($sql, 'UPDATE meals_clients SET') === 0) {
            if (preg_match('/SET\s+(.+)\s+WHERE/i', $sql, $matches)) {
                $assignments = explode(',', $matches[1]);
                $columns = [];

                foreach ($assignments as $assignment) {
                    if (preg_match('/`?([a-z_]+)`?\s*=\s*\?/i', trim($assignment), $columnMatch)) {
                        $columns[] = $columnMatch[1];
                    }
                }

                $valueCount = count($columns);
                $values = array_slice($this->params, 0, $valueCount);
                if (!empty($columns)) {
                    $this->conn->lastUpdate = array_combine($columns, $values);
                }
                $this->conn->lastUpdateWhereId = $this->params[$valueCount] ?? null;
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
            $user = (int) ($this->params[2] ?? 0);
            $updated = $this->conn->updateDraft($id, $json, $user);
            $this->affected_rows = $updated ? 1 : 0;
            return true;
        }

        if (stripos($sql, 'DELETE FROM meals_drafts') === 0) {
            $id = (int) ($this->params[0] ?? 0);
            $user = (int) ($this->params[1] ?? 0);
            $deleted = $this->conn->deleteDraft($id, $user);
            $this->affected_rows = $deleted ? 1 : 0;
            return true;
        }

        if (stripos($sql, 'SELECT id FROM meals_drafts') === 0) {
            $id = (int) ($this->params[0] ?? 0);
            $owner = null;
            if (stripos($sql, 'created_by') !== false) {
                $owner = (int) ($this->params[1] ?? 0);
            }
            $this->num_rows = $this->conn->hasDraft($id, $owner) ? 1 : 0;
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
    private $requiredInsertColumns;
    public $lastInsert = [];
    public $lastUpdate = [];
    public $lastUpdateWhereId = null;
    public int|string $affected_rows = 0;
    public int|string $insert_id = 0;
    public string $error = '';

    public function __construct(
        array $existingValues = [],
        array $existingColumns = [],
        array $existingIndexes = [],
        array $drafts = [],
        array $requiredInsertColumns = []
    ) {
        $this->existingValues = $existingValues;
        $this->existingColumns = $existingColumns;
        $this->existingIndexes = $existingIndexes;
        $this->requiredInsertColumns = array_values($requiredInsertColumns);

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

    public function requiredInsertColumns(): array {
        return $this->requiredInsertColumns;
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

    public function updateDraft(int $id, string $json, int $user): bool {
        if (!isset($this->drafts[$id])) {
            return false;
        }

        if ($this->drafts[$id]['created_by'] !== $user) {
            return false;
        }

        $changed = $this->drafts[$id]['data'] !== $json;
        $this->drafts[$id]['data'] = $json;

        return $changed;
    }

    public function deleteDraft(int $id, int $user): bool {
        if (!isset($this->drafts[$id])) {
            return false;
        }

        if ($this->drafts[$id]['created_by'] !== $user) {
            return false;
        }

        unset($this->drafts[$id]);
        return true;
    }

    public function hasDraft(int $id, ?int $user = null): bool {
        if (!isset($this->drafts[$id])) {
            return false;
        }

        if ($user !== null && $this->drafts[$id]['created_by'] !== $user) {
            return false;
        }

        return true;
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
            if (preg_match('/ADD COLUMN `([a-z_]+)`/i', $sql, $matches)) {
                $column = $matches[1];
                if (!in_array($column, $this->existingColumns, true)) {
                    $this->existingColumns[] = $column;
                }
            }

            if (preg_match('/CHANGE COLUMN `([a-z_]+)` `([a-z_]+)`/i', $sql, $matches)) {
                $old = $matches[1];
                $new = $matches[2];
                $index = array_search($old, $this->existingColumns, true);
                if ($index !== false) {
                    $this->existingColumns[$index] = $new;
                } elseif (!in_array($new, $this->existingColumns, true)) {
                    $this->existingColumns[] = $new;
                }
            }

            return true;
        }

        if (stripos($sql, 'SHOW INDEX') === 0) {
            if (preg_match("/Key_name = '([^']+)'/i", $sql, $matches)) {
                $index = stripslashes($matches[1]);
                $exists = in_array($index, $this->existingIndexes, true);
                $rows = [];
                if ($exists) {
                    $rows[] = [
                        'Key_name'   => $index,
                        'Non_unique' => stripos($index, 'unique_') === 0 ? 0 : 1,
                    ];
                }

                return new StubResult($exists ? 1 : 0, $rows);
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

run_test('validation rejects invalid enumerated and numeric inputs', function () {
    reset_index_flag();
    set_db_connection(new StubMysqli());

    $payload = [
        'first_name' => 'Jamie',
        'last_name' => 'Client',
        'customer_type' => 'Type A',
        'client_email' => 'jamie@example.com',
        'phone_primary' => '(123)-456-7890',
        'address_postal' => 'A1A1A1',
        'gender' => 'Unknown',
        'service_zone' => 'Z',
        'service_course' => '9',
        'meal_type' => '4',
        'requisition_period' => 'Yearly',
        'delivery_day' => 'Monday',
        'ordering_contact_method' => 'Text',
        'ordering_frequency' => 'often',
        'delivery_frequency' => 'frequently',
        'freezer_capacity' => 'large',
        'delivery_fee' => 'ten dollars',
    ];

    $result = MealsDB_Client_Form::validate($payload);
    if ($result['valid']) {
        throw new Exception('Expected validation to fail for invalid enumerated inputs.');
    }

    $expected_messages = [
        'Gender must be Male, Female, or Other.',
        'Service zone must be either A or B.',
        'Service course must be either 1 or 2.',
        'Meal type must be either 1 or 2.',
        'Requisition period must be Day, Week, or Month.',
        'Delivery day must match one of the scheduled options.',
        'Ordering contact method must be a supported option.',
        'Ordering frequency must be a number.',
        'Delivery frequency must be a number.',
        'Freezer capacity must be a number.',
        'Delivery fee must be a number.',
    ];

    foreach ($expected_messages as $message) {
        if (!in_array($message, $result['errors'], true)) {
            throw new Exception('Missing expected validation message: ' . $message);
        }
    }

    set_db_connection(null);
});

run_test('validation accepts enumerated selections', function () {
    reset_index_flag();
    set_db_connection(new StubMysqli());

    $payload = [
        'first_name' => 'Morgan',
        'last_name' => 'Valid',
        'customer_type' => 'Type B',
        'client_email' => 'morgan@example.com',
        'phone_primary' => '(555)-123-4567',
        'address_postal' => 'B2B2B2',
        'address_street_number' => '123',
        'address_street_name' => 'Main',
        'address_unit' => '1A',
        'address_city' => 'City',
        'address_province' => 'NB',
        'gender' => 'Female',
        'service_zone' => 'A',
        'service_course' => '2',
        'meal_type' => '1',
        'requisition_period' => 'Week',
        'delivery_day' => 'Friday PM',
        'ordering_contact_method' => 'Client Email',
        'ordering_frequency' => '4',
        'delivery_frequency' => '2',
        'freezer_capacity' => '3',
        'delivery_fee' => '5.25',
        'units' => '5',
        'payment_method' => 'Cheque',
        'required_start_date' => '2024-01-01',
        'rate' => '20',
        'delivery_initials' => 'ABC',
        'delivery_area_name' => 'Area 1',
        'delivery_area_zone' => 'A',
    ];

    $result = MealsDB_Client_Form::validate($payload);
    if (!$result['valid']) {
        throw new Exception('Validation should succeed for supported enumerated selections.');
    }

    $sanitized = $result['sanitized'];
    $assertions = [
        'gender' => 'Female',
        'service_zone' => 'A',
        'service_course' => '2',
        'meal_type' => '1',
        'requisition_period' => 'Week',
        'delivery_day' => 'Friday PM',
        'ordering_contact_method' => 'Client Email',
        'ordering_frequency' => '4',
        'delivery_frequency' => '2',
        'freezer_capacity' => '3',
        'delivery_fee' => '5.25',
        'units' => '5',
    ];

    foreach ($assertions as $field => $expected) {
        if (($sanitized[$field] ?? null) !== $expected) {
            throw new Exception(sprintf('Expected sanitized %s to equal %s', $field, $expected));
        }
    }

    set_db_connection(null);
});

run_test('staff client allows minimal required fields without wordpress id', function () {
    reset_index_flag();
    set_db_connection(new StubMysqli());

    $payload = [
        'customer_type' => 'Staff',
        'first_name' => 'Alex',
        'last_name' => 'Smith',
        'client_email' => 'alex@example.com',
    ];

    $result = MealsDB_Client_Form::validate($payload);
    if (!$result['valid']) {
        throw new Exception('Expected Staff clients to validate with minimal fields.');
    }

    $sanitized = $result['sanitized'];
    if (!empty($sanitized['wordpress_user_id'] ?? '')) {
        throw new Exception('WordPress user ID should remain optional for Staff clients.');
    }

    if (($sanitized['client_email'] ?? null) !== 'alex@example.com') {
        throw new Exception('Client email should be preserved for Staff clients.');
    }

    if (!array_key_exists('delivery_initials', $sanitized)) {
        throw new Exception('Staff clients should include delivery_initials key in sanitized data.');
    }

    if ($sanitized['delivery_initials'] !== null) {
        throw new Exception('Staff clients should have delivery_initials forced to null.');
    }

    set_db_connection(null);
});

run_test('non-staff clients require delivery initials', function () {
    reset_index_flag();
    set_db_connection(new StubMysqli());

    $payload = [
        'customer_type' => 'Private',
        'first_name' => 'Jamie',
        'last_name' => 'Doe',
        'phone_primary' => '(506)-555-1111',
        'address_street_name' => 'Main',
        'address_city' => 'Moncton',
        'address_province' => 'NB',
        'address_postal' => 'E1E1E1',
        'delivery_day' => 'MONDAY AM',
        'payment_method' => 'Cheque',
    ];

    $result = MealsDB_Client_Form::validate($payload);
    if ($result['valid']) {
        throw new Exception('Expected validation to fail when delivery initials are missing.');
    }

    if (!in_array('Initials for delivery is required.', $result['errors'], true)) {
        throw new Exception('Missing required error for delivery initials.');
    }

    set_db_connection(null);
});

run_test('non-staff delivery initials must pass server-side validation', function () {
    reset_index_flag();
    set_db_connection(new StubMysqli());

    $payload = [
        'customer_type' => 'Private',
        'first_name' => 'Jamie',
        'last_name' => 'Doe',
        'phone_primary' => '(506)-555-1111',
        'address_street_name' => 'Main',
        'address_city' => 'Moncton',
        'address_province' => 'NB',
        'address_postal' => 'E1E1E1',
        'delivery_day' => 'MONDAY AM',
        'payment_method' => 'Cheque',
        'delivery_initials' => 'AB1',
    ];

    $result = MealsDB_Client_Form::validate($payload);
    if ($result['valid']) {
        throw new Exception('Expected validation to fail for invalid delivery initials.');
    }

    if (!in_array('Initials must be exactly three uppercase letters.', $result['errors'], true)) {
        throw new Exception('Expected format error for invalid delivery initials.');
    }

    $payload['delivery_initials'] = 'ABC';
    $result = MealsDB_Client_Form::validate($payload);
    if (!$result['valid']) {
        throw new Exception('Expected valid initials to pass validation.');
    }

    $sanitized = $result['sanitized'];
    if (($sanitized['delivery_initials'] ?? null) !== 'ABC') {
        throw new Exception('Sanitized delivery initials should preserve validated code.');
    }

    set_db_connection(null);
});

run_test('staff client accepts optional WordPress user id', function () {
    reset_index_flag();
    set_db_connection(new StubMysqli());

    $payload = [
        'customer_type' => 'Staff',
        'first_name' => 'Jamie',
        'last_name' => 'Lee',
        'client_email' => 'jamie@example.com',
        'wordpress_user_id' => '0042',
    ];

    $result = MealsDB_Client_Form::validate($payload);
    if (!$result['valid']) {
        throw new Exception('Expected Staff clients to allow providing an optional WordPress user ID.');
    }

    $sanitized = $result['sanitized'];
    if (($sanitized['wordpress_user_id'] ?? null) !== '42') {
        throw new Exception('WordPress user ID should be sanitized to digits when provided.');
    }

    set_db_connection(null);
});

run_test('staff client requires email but not WordPress user id', function () {
    reset_index_flag();
    set_db_connection(new StubMysqli());

    $payload = [
        'customer_type' => 'Staff',
        'first_name' => 'Taylor',
        'last_name' => 'Jones',
        'client_email' => '',
        'wordpress_user_id' => '',
    ];

    $result = MealsDB_Client_Form::validate($payload);
    if ($result['valid']) {
        throw new Exception('Expected Staff validation to fail when email is missing.');
    }

    $missing = $result['error_details']['missing_required'] ?? [];
    if (!isset($missing['client_email']['message'])) {
        throw new Exception('Expected missing email error for Staff client.');
    }

    if (isset($missing['wordpress_user_id'])) {
        throw new Exception('WordPress user ID should not be required for Staff clients.');
    }

    if (isset($missing['phone_primary'])) {
        throw new Exception('Staff clients should not require primary phone numbers.');
    }

    set_db_connection(null);
});

run_test('private clients enforce configured required fields', function () {
    reset_index_flag();
    set_db_connection(new StubMysqli());

    $payload = [
        'customer_type' => 'Private',
        'first_name' => 'River',
        'last_name' => 'Stone',
    ];

    $result = MealsDB_Client_Form::validate($payload);
    if ($result['valid']) {
        throw new Exception('Expected Private clients to require address and delivery details.');
    }

    $missing = $result['error_details']['missing_required'] ?? [];
    $expected = [
        'phone_primary',
        'address_street_name',
        'address_city',
        'address_province',
        'address_postal',
        'delivery_day',
        'payment_method',
    ];

    foreach ($expected as $field) {
        if (!isset($missing[$field]['message'])) {
            throw new Exception('Missing required validation entry for ' . $field . '.');
        }
    }

    if (isset($missing['address_street_number'])) {
        throw new Exception('Street number should not be required for Private clients.');
    }

    set_db_connection(null);
});

run_test('sdnb clients require service identifiers and payment info', function () {
    reset_index_flag();
    set_db_connection(new StubMysqli());

    $payload = [
        'customer_type' => 'SDNB',
        'first_name' => 'Morgan',
        'last_name' => 'Quill',
    ];

    $result = MealsDB_Client_Form::validate($payload);
    if ($result['valid']) {
        throw new Exception('Expected SDNB clients to require service identifiers.');
    }

    $missing = $result['error_details']['missing_required'] ?? [];
    $expected = [
        'phone_primary',
        'vendor_number',
        'service_center_charged',
        'service_id',
        'requisition_period',
        'rate',
        'payment_method',
    ];

    foreach ($expected as $field) {
        if (!isset($missing[$field]['message'])) {
            throw new Exception('Missing required validation entry for ' . $field . '.');
        }
    }

    if (isset($missing['delivery_day'])) {
        throw new Exception('Delivery day should not be required for SDNB clients.');
    }

    set_db_connection(null);
});

run_test('veteran clients require requisition and health identifiers', function () {
    reset_index_flag();
    set_db_connection(new StubMysqli());

    $payload = [
        'customer_type' => 'Veteran',
        'first_name' => 'Robin',
        'last_name' => 'Vale',
    ];

    $result = MealsDB_Client_Form::validate($payload);
    if ($result['valid']) {
        throw new Exception('Expected Veteran clients to require requisition and health identifiers.');
    }

    $missing = $result['error_details']['missing_required'] ?? [];
    $expected = [
        'phone_primary',
        'requisition_period',
        'vet_health_card',
        'payment_method',
    ];

    foreach ($expected as $field) {
        if (!isset($missing[$field]['message'])) {
            throw new Exception('Missing required validation entry for ' . $field . '.');
        }
    }

    if (isset($missing['vendor_number'])) {
        throw new Exception('Vendor number should not be required for Veteran clients.');
    }

    set_db_connection(null);
});

run_test('builds a helpful summary for missing and invalid fields', function () {
    reset_index_flag();
    set_db_connection(new StubMysqli());

    $payload = [
        'customer_type' => 'Private',
        'first_name' => '',
        'last_name' => 'Tester',
        'address_street_number' => '123',
        'address_street_name' => 'Main Street',
        'address_unit' => '1A',
        'address_city' => 'Moncton',
        'address_province' => 'NB',
        'address_postal' => 'E2E2E2',
        'phone_primary' => '123',
        'payment_method' => 'Cheque',
        'required_start_date' => '2024-01-01',
        'rate' => '20',
        'delivery_initials' => 'ABC',
        'delivery_day' => 'Monday',
        'delivery_area_name' => 'Zone 1',
        'delivery_area_zone' => 'A',
        'ordering_frequency' => '2',
        'ordering_contact_method' => 'Client Email',
        'delivery_frequency' => '1',
    ];

    $result = MealsDB_Client_Form::validate($payload);

    if ($result['valid']) {
        throw new Exception('Expected validation to fail when required and formatted fields are incorrect.');
    }

    $summary = $result['error_summary'] ?? '';
    if (strpos($summary, 'Missing required fields:') === false) {
        throw new Exception('Summary should describe missing required fields.');
    }

    if (strpos($summary, 'Formatting issues detected in:') === false) {
        throw new Exception('Summary should describe formatting issues.');
    }

    $missing = $result['error_details']['missing_required'] ?? [];
    if (!isset($missing['first_name']['message'])) {
        throw new Exception('Missing required field details for first_name.');
    }

    $formatIssues = $result['error_details']['invalid_format']['phone_primary']['messages'] ?? [];
    if (!in_array('Phone number must be in (###)-###-#### format.', $formatIssues, true)) {
        throw new Exception('Expected phone format error to be captured in error details.');
    }

    if (!in_array('First Name is required.', $result['errors'], true)) {
        throw new Exception('Expected detailed error message for missing first name.');
    }

    if (!in_array('Phone number must be in (###)-###-#### format.', $result['errors'], true)) {
        throw new Exception('Expected detailed error message for phone number format.');
    }

    set_db_connection(null);
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
    $hash = hash('sha256', strtolower(trim('ABC')));
    $conn = new StubMysqli([
        'delivery_initials_index' => [$hash]
    ], ['individual_id_index', 'requisition_id_index', 'vet_health_card_index', 'delivery_initials_index'], ['idx_individual_id_index', 'idx_requisition_id_index', 'idx_vet_health_card_index', 'idx_delivery_initials_index']);
    set_db_connection($conn);

    $method = new ReflectionMethod(MealsDB_Client_Form::class, 'check_unique_fields');
    $method->setAccessible(true);
    $errors = $method->invoke(null, ['delivery_initials' => 'ABC']);

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
        'delivery_initials' => 'ABC',
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

    $expectedInitials = hash('sha256', strtolower(trim('ABC')));
    if (($conn->lastInsert['delivery_initials_index'] ?? null) !== $expectedInitials) {
        throw new Exception('Missing deterministic hash for delivery_initials.');
    }
});

run_test('save omits empty wordpress user id values', function () {
    reset_index_flag();
    $conn = new StubMysqli([], ['individual_id_index', 'requisition_id_index', 'vet_health_card_index', 'delivery_initials_index'], ['idx_individual_id_index', 'idx_requisition_id_index', 'idx_vet_health_card_index', 'idx_delivery_initials_index']);
    set_db_connection($conn);

    $data = [
        'first_name' => 'Sam',
        'last_name' => 'Staff',
        'customer_type' => 'Staff',
        'client_email' => 'sam@example.com',
        'wordpress_user_id' => '',
    ];

    $result = MealsDB_Client_Form::save($data);
    if ($result !== true) {
        throw new Exception('Save did not succeed when WordPress user ID is empty.');
    }

    if (array_key_exists('wordpress_user_id', $conn->lastInsert)) {
        throw new Exception('Expected empty WordPress user ID to be omitted from insert payload.');
    }
});

run_test('staff client save populates defaults for required database columns', function () {
    reset_index_flag();
    $requiredColumns = ['client_email', 'phone_primary', 'address_postal'];
    $conn = new StubMysqli([], ['individual_id_index', 'requisition_id_index', 'vet_health_card_index', 'delivery_initials_index'], ['idx_individual_id_index', 'idx_requisition_id_index', 'idx_vet_health_card_index', 'idx_delivery_initials_index'], [], $requiredColumns);
    set_db_connection($conn);

    $payload = [
        'first_name'    => 'Jordan',
        'last_name'     => 'Staff',
        'customer_type' => 'Staff',
        'client_email'  => 'jordan@example.com',
    ];

    $validation = MealsDB_Client_Form::validate($payload);
    if (!$validation['valid']) {
        throw new Exception('Staff payload should validate successfully.');
    }

    if (!MealsDB_Client_Form::save($payload)) {
        throw new Exception('Staff save should succeed with defaults for required columns.');
    }

    foreach ($requiredColumns as $column) {
        if (!array_key_exists($column, $conn->lastInsert)) {
            throw new Exception('Missing expected column ' . $column . ' in insert payload.');
        }
    }

    if ($conn->lastInsert['client_email'] !== 'jordan@example.com') {
        throw new Exception('Client email should be preserved when saving Staff clients.');
    }

    if ($conn->lastInsert['phone_primary'] !== '') {
        throw new Exception('Phone primary should default to an empty string for Staff clients.');
    }

    if ($conn->lastInsert['address_postal'] !== '') {
        throw new Exception('Address postal should default to an empty string for Staff clients.');
    }

    set_db_connection(null);
});

run_test('private client submission succeeds with all fields populated', function () {
    reset_index_flag();
    $requiredColumns = ['client_email', 'phone_primary', 'address_postal'];
    $conn = new StubMysqli([], ['individual_id_index', 'requisition_id_index', 'vet_health_card_index', 'delivery_initials_index'], ['idx_individual_id_index', 'idx_requisition_id_index', 'idx_vet_health_card_index', 'idx_delivery_initials_index'], [], $requiredColumns);
    set_db_connection($conn);

    $payload = [
        'customer_type'                 => 'Private',
        'first_name'                    => 'Alex',
        'last_name'                     => 'Client',
        'client_email'                  => 'alex.private@example.com',
        'phone_primary'                 => '(506)-123-4567',
        'phone_secondary'               => '(506)-765-4321',
        'do_not_call_client_phone'      => '1',
        'address_street_number'         => '123',
        'address_street_name'           => 'Main Street',
        'address_unit'                  => '5B',
        'address_city'                  => 'Moncton',
        'address_province'              => 'NB',
        'address_postal'                => 'E2E2E2',
        'delivery_address_street_number'=> '456',
        'delivery_address_street_name'  => 'Elm Street',
        'delivery_address_unit'         => '9C',
        'delivery_address_city'         => 'Moncton',
        'delivery_address_province'     => 'NB',
        'delivery_address_postal'       => 'B3B3B3',
        'gender'                        => 'Female',
        'birth_date'                    => '1950-05-01',
        'service_center'                => 'Center A',
        'service_center_charged'        => 'Center B',
        'vendor_number'                 => 'VN-123',
        'service_id'                    => 'SID-555',
        'service_zone'                  => 'A',
        'service_course'                => '1',
        'per_sdnb_req'                  => 'N/A',
        'payment_method'                => 'Cheque',
        'rate'                          => '10.50',
        'client_contribution'           => '25',
        'delivery_fee'                  => '5.00',
        'delivery_initials'             => 'ACD',
        'delivery_day'                  => 'MONDAY AM',
        'delivery_area_name'            => 'Area 1',
        'delivery_area_zone'            => 'Zone A',
        'ordering_frequency'            => '2',
        'ordering_contact_method'       => 'CLIENT EMAIL',
        'delivery_frequency'            => '2',
        'freezer_capacity'              => '4',
        'meal_type'                     => '1',
        'requisition_period'            => 'MONTH',
        'service_commence_date'         => '2024-05-01',
        'required_start_date'           => '2024-05-02',
        'expected_termination_date'     => '2024-12-31',
        'initial_renewal_date'          => '2024-06-01',
        'termination_date'              => '2025-01-31',
        'most_recent_renewal_date'      => '2024-07-01',
        'units'                         => '3',
        'diet_concerns'                 => 'None',
        'client_comments'               => 'All good.',
        'alt_contact_name'              => 'Taylor Helper',
        'alt_contact_phone_primary'     => '(506)-111-2222',
        'alt_contact_phone_secondary'   => '(506)-333-4444',
        'alt_contact_email'             => 'helper@example.com',
    ];

    $validation = MealsDB_Client_Form::validate($payload);
    if (!$validation['valid']) {
        throw new Exception('Private payload should validate successfully: ' . json_encode($validation['errors']));
    }

    if (!MealsDB_Client_Form::save($payload)) {
        throw new Exception('Private client save should succeed.');
    }

    if (($conn->lastInsert['delivery_day'] ?? '') !== 'MONDAY AM') {
        throw new Exception('Delivery day should be preserved for Private clients.');
    }

    if (($conn->lastInsert['ordering_contact_method'] ?? '') !== 'CLIENT EMAIL') {
        throw new Exception('Ordering contact method should be preserved for Private clients.');
    }

    set_db_connection(null);
});

run_test('sdnb client submission succeeds with all fields populated', function () {
    reset_index_flag();
    $requiredColumns = ['client_email', 'phone_primary', 'address_postal'];
    $conn = new StubMysqli([], ['individual_id_index', 'requisition_id_index', 'vet_health_card_index', 'delivery_initials_index'], ['idx_individual_id_index', 'idx_requisition_id_index', 'idx_vet_health_card_index', 'idx_delivery_initials_index'], [], $requiredColumns);
    set_db_connection($conn);

    $payload = [
        'customer_type'                 => 'SDNB',
        'first_name'                    => 'Morgan',
        'last_name'                     => 'Support',
        'client_email'                  => 'morgan.sdnb@example.com',
        'phone_primary'                 => '(506)-555-1234',
        'phone_secondary'               => '(506)-555-9876',
        'do_not_call_client_phone'      => '0',
        'address_street_number'         => '789',
        'address_street_name'           => 'Pine Avenue',
        'address_unit'                  => '12A',
        'address_city'                  => 'Saint John',
        'address_province'              => 'NB',
        'address_postal'                => 'C1C1C1',
        'delivery_address_street_number'=> '101',
        'delivery_address_street_name'  => 'Oak Lane',
        'delivery_address_unit'         => '3B',
        'delivery_address_city'         => 'Saint John',
        'delivery_address_province'     => 'NB',
        'delivery_address_postal'       => 'D2D2D2',
        'gender'                        => 'Male',
        'birth_date'                    => '1945-07-15',
        'service_center'                => 'Center C',
        'service_center_charged'        => 'Center D',
        'vendor_number'                 => 'VN-789',
        'service_id'                    => 'SID-777',
        'service_zone'                  => 'B',
        'service_course'                => '2',
        'per_sdnb_req'                  => 'Required check',
        'payment_method'                => 'Credit',
        'rate'                          => '15.00',
        'client_contribution'           => '40',
        'delivery_fee'                  => '7.00',
        'delivery_initials'             => 'MSQ',
        'delivery_day'                  => 'TUESDAY PM',
        'delivery_area_name'            => 'Area 2',
        'delivery_area_zone'            => 'Zone B',
        'ordering_frequency'            => '4',
        'ordering_contact_method'       => 'CLIENT PHONE',
        'delivery_frequency'            => '4',
        'freezer_capacity'              => '6',
        'meal_type'                     => '2',
        'requisition_period'            => 'WEEK',
        'service_commence_date'         => '2024-03-15',
        'required_start_date'           => '2024-03-20',
        'expected_termination_date'     => '2024-11-01',
        'initial_renewal_date'          => '2024-04-20',
        'termination_date'              => '2024-12-31',
        'most_recent_renewal_date'      => '2024-05-20',
        'open_date'                     => '2024-03-01',
        'units'                         => '6',
        'diet_concerns'                 => 'Low sodium',
        'client_comments'               => 'Weekly wellness check needed.',
        'alt_contact_name'              => 'Jamie Caregiver',
        'alt_contact_phone_primary'     => '(506)-777-8888',
        'alt_contact_phone_secondary'   => '(506)-999-0000',
        'alt_contact_email'             => 'care@example.com',
    ];

    $validation = MealsDB_Client_Form::validate($payload);
    if (!$validation['valid']) {
        throw new Exception('SDNB payload should validate successfully: ' . json_encode($validation['errors']));
    }

    if (!MealsDB_Client_Form::save($payload)) {
        throw new Exception('SDNB client save should succeed.');
    }

    if (($conn->lastInsert['open_date'] ?? '') !== '2024-03-01') {
        throw new Exception('Open date should be preserved for SDNB clients.');
    }

    if (($conn->lastInsert['units'] ?? '') !== '6') {
        throw new Exception('Units should be preserved for SDNB clients.');
    }

    set_db_connection(null);
});

run_test('veteran client submission succeeds with all fields populated', function () {
    reset_index_flag();
    $requiredColumns = ['client_email', 'phone_primary', 'address_postal'];
    $conn = new StubMysqli([], ['individual_id_index', 'requisition_id_index', 'vet_health_card_index', 'delivery_initials_index'], ['idx_individual_id_index', 'idx_requisition_id_index', 'idx_vet_health_card_index', 'idx_delivery_initials_index'], [], $requiredColumns);
    set_db_connection($conn);

    $payload = [
        'customer_type'                 => 'Veteran',
        'first_name'                    => 'Sam',
        'last_name'                     => 'Service',
        'client_email'                  => 'sam.veteran@example.com',
        'phone_primary'                 => '(506)-222-3333',
        'phone_secondary'               => '(506)-444-5555',
        'do_not_call_client_phone'      => '0',
        'address_street_number'         => '222',
        'address_street_name'           => 'Hero Road',
        'address_unit'                  => '2D',
        'address_city'                  => 'Fredericton',
        'address_province'              => 'NB',
        'address_postal'                => 'F3F3F3',
        'delivery_address_street_number'=> '333',
        'delivery_address_street_name'  => 'Honor Way',
        'delivery_address_unit'         => '7E',
        'delivery_address_city'         => 'Fredericton',
        'delivery_address_province'     => 'NB',
        'delivery_address_postal'       => 'G4G4G4',
        'gender'                        => 'Other',
        'birth_date'                    => '1940-12-10',
        'service_center'                => 'Center E',
        'service_center_charged'        => 'Center F',
        'vendor_number'                 => 'VN-321',
        'service_id'                    => 'SID-999',
        'service_zone'                  => 'A',
        'service_course'                => '1',
        'per_sdnb_req'                  => 'Veteran support',
        'payment_method'                => 'Direct Deposit',
        'rate'                          => '18.00',
        'client_contribution'           => '50',
        'delivery_fee'                  => '9.00',
        'delivery_initials'             => 'SSA',
        'delivery_day'                  => 'FRIDAY AM',
        'delivery_area_name'            => 'Area 3',
        'delivery_area_zone'            => 'Zone C',
        'ordering_frequency'            => '3',
        'ordering_contact_method'       => 'SOCIAL WORKER PHONE',
        'delivery_frequency'            => '3',
        'freezer_capacity'              => '8',
        'meal_type'                     => '2',
        'requisition_period'            => 'MONTH',
        'service_commence_date'         => '2024-02-10',
        'required_start_date'           => '2024-02-15',
        'expected_termination_date'     => '2024-10-30',
        'initial_renewal_date'          => '2024-03-15',
        'termination_date'              => '2024-12-30',
        'most_recent_renewal_date'      => '2024-04-15',
        'open_date'                     => '2024-02-01',
        'units'                         => '8',
        'vet_health_card'               => 'VH-123456',
        'diet_concerns'                 => 'Gluten free',
        'client_comments'               => 'Prefers morning calls.',
        'alt_contact_name'              => 'Riley Advocate',
        'alt_contact_phone_primary'     => '(506)-666-7777',
        'alt_contact_phone_secondary'   => '(506)-888-9999',
        'alt_contact_email'             => 'advocate@example.com',
    ];

    $validation = MealsDB_Client_Form::validate($payload);
    if (!$validation['valid']) {
        throw new Exception('Veteran payload should validate successfully: ' . json_encode($validation['errors']));
    }

    if (!MealsDB_Client_Form::save($payload)) {
        throw new Exception('Veteran client save should succeed.');
    }

    if (($conn->lastInsert['vet_health_card'] ?? '') === '') {
        throw new Exception('Vet health card should be included for Veteran clients.');
    }

    set_db_connection(null);
});

run_test('save aborts when deterministic indexes cannot be created', function () {
    reset_index_flag();
    $conn = new class([], [], [], []) extends StubMysqli {
        public function __construct() {
            parent::__construct([], [], [], []);
        }

        public function query(string $sql, int $result_mode = MYSQLI_STORE_RESULT) {
            if (stripos($sql, 'ALTER TABLE') === 0) {
                return false;
            }

            return parent::query($sql, $result_mode);
        }
    };

    set_db_connection($conn);

    $data = [
        'individual_id' => 'ID-001',
        'first_name' => 'Jane'
    ];

    $result = MealsDB_Client_Form::save($data);

    if ($result !== false) {
        throw new Exception('Save should fail when deterministic indexes cannot be ensured.');
    }
});

run_test('update clears wordpress user id when blank', function () {
    reset_index_flag();
    $conn = new StubMysqli([], ['individual_id_index', 'requisition_id_index', 'vet_health_card_index', 'delivery_initials_index'], ['idx_individual_id_index', 'idx_requisition_id_index', 'idx_vet_health_card_index', 'idx_delivery_initials_index']);
    set_db_connection($conn);

    $result = MealsDB_Client_Form::update(12, [
        'first_name' => 'Chris',
        'wordpress_user_id' => '',
    ]);

    if ($result !== true) {
        throw new Exception('Update should succeed when clearing WordPress user ID.');
    }

    if (!array_key_exists('wordpress_user_id', $conn->lastUpdate)) {
        throw new Exception('Expected update payload to include the WordPress user ID column.');
    }

    if ($conn->lastUpdate['wordpress_user_id'] !== null) {
        throw new Exception('Expected WordPress user ID to be null when cleared during update, got ' . var_export($conn->lastUpdate['wordpress_user_id'], true));
    }

    if ($conn->lastUpdateWhereId !== 12) {
        throw new Exception('Expected update to target the provided client ID.');
    }
});

run_test('save_draft updates existing record when id provided', function () {
    $existingId = 5;
    $original = json_encode(['first_name' => 'Original']);
    $conn = new StubMysqli([], [], [], [
        ['id' => $existingId, 'data' => $original, 'created_by' => 1234],
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

run_test('save_draft prevents updating drafts owned by other users', function () {
    $existingId = 8;
    $original = json_encode(['first_name' => 'Owner']);
    $conn = new StubMysqli([], [], [], [
        ['id' => $existingId, 'data' => $original, 'created_by' => 4321],
    ]);
    set_db_connection($conn);

    $result = MealsDB_Client_Form::save_draft(['first_name' => 'Intruder'], $existingId);

    if ($result !== false) {
        throw new Exception('Expected save_draft to fail for drafts owned by another user.');
    }

    $stored = $conn->draftData($existingId);
    if ($stored !== $original) {
        throw new Exception('Draft content should remain unchanged when update is rejected.');
    }
});

run_test('delete_draft removes stored draft and reports missing ids', function () {
    $existingId = 7;
    $conn = new StubMysqli([], [], [], [
        ['id' => $existingId, 'data' => json_encode(['note' => 'keep me']), 'created_by' => 1234],
    ]);
    set_db_connection($conn);

    if (!MealsDB_Client_Form::delete_draft($existingId)) {
        throw new Exception('Expected delete_draft to return true for existing record.');
    }

    if (MealsDB_Client_Form::delete_draft(999) !== false) {
        throw new Exception('Expected delete_draft to fail for missing record.');
    }
});

run_test('delete_draft prevents removing drafts owned by other users', function () {
    $existingId = 11;
    $conn = new StubMysqli([], [], [], [
        ['id' => $existingId, 'data' => json_encode(['note' => 'secure']), 'created_by' => 5555],
    ]);
    set_db_connection($conn);

    if (MealsDB_Client_Form::delete_draft($existingId) !== false) {
        throw new Exception('Expected delete_draft to fail for drafts owned by another user.');
    }

    if (!$conn->hasDraft($existingId)) {
        throw new Exception('Draft owned by another user should not be removed.');
    }
});

reset_index_flag();
set_db_connection(null);

echo "All tests passed.\n";
