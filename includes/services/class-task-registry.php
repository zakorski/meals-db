<?php
/**
 * Task type registry.
 *
 * In-memory registry of task type definitions. Task type modules register
 * themselves during plugin init.
 *
 * @package MealsDB
 */

defined('ABSPATH') || exit;

class MealsDB_Task_Registry {

    /**
     * @var array<string, array<string, mixed>>
     */
    private static $types = [];

    /**
     * Register a task type definition.
     *
     * @param string               $type_id
     * @param array<string, mixed> $definition
     */
    public static function register(string $type_id, array $definition): void {
        if ($type_id === '') {
            error_log('[MealsDB Task Registry] Refused to register task with empty type_id.');
            return;
        }

        $definition += [
            'label'         => $type_id,
            'description'   => '',
            'assignee_role' => null,
            'urgency'       => 'routine',
            'form_schema'   => [],
            'on_complete'   => null,
            'on_defer'      => null,
            'on_skip'       => null,
        ];

        if (!is_array($definition['form_schema'])) {
            $definition['form_schema'] = [];
        }

        self::$types[$type_id] = $definition;
    }

    /**
     * Retrieve a registered type definition or null if unknown.
     *
     * @return array<string, mixed>|null
     */
    public static function get(string $type_id): ?array {
        return self::$types[$type_id] ?? null;
    }

    /**
     * Retrieve all registered type definitions.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function get_all(): array {
        return self::$types;
    }

    /**
     * Whether a type id has been registered.
     */
    public static function has(string $type_id): bool {
        return isset(self::$types[$type_id]);
    }

    /**
     * Clear the registry. Intended for tests only.
     */
    public static function reset(): void {
        self::$types = [];
    }

    /**
     * Validate a form-data payload against a type's form_schema.
     *
     * Returns an array of error messages (empty on success). Honours the
     * `show_when` conditional-field rule: fields that are currently hidden
     * are skipped for required validation.
     *
     * @param string               $type_id
     * @param array<string, mixed> $form_data
     * @return string[]
     */
    public static function validate_form_data(string $type_id, array $form_data): array {
        $definition = self::get($type_id);
        if ($definition === null) {
            return [sprintf('Unknown task type: %s', $type_id)];
        }

        $schema = is_array($definition['form_schema']) ? $definition['form_schema'] : [];
        return self::validate_fields($schema, $form_data);
    }

    /**
     * Validate an arbitrary schema (list of field definitions) against a
     * data row. Used recursively for repeat_group rows.
     *
     * @param array<int, array<string, mixed>> $schema
     * @param array<string, mixed>             $form_data
     * @param string                           $path_prefix optional label prefix for nested errors
     * @return string[]
     */
    public static function validate_fields(array $schema, array $form_data, string $path_prefix = ''): array {
        $errors = [];

        foreach ($schema as $field) {
            if (!is_array($field) || empty($field['name'])) {
                continue;
            }

            $name = (string) $field['name'];
            $type = isset($field['type']) ? (string) $field['type'] : 'text';
            $required = !empty($field['required']);
            $visible = self::field_is_visible($field, $form_data);

            if (!$visible) {
                continue;
            }

            $label_path = $path_prefix === '' ? $name : $path_prefix . '.' . $name;
            $value = $form_data[$name] ?? null;
            $empty = ($value === null || $value === '' || $value === []);

            if ($required && $empty && $type !== 'repeat_group') {
                $errors[] = sprintf('Field "%s" is required.', $label_path);
                continue;
            }

            if ($empty && $type !== 'repeat_group') {
                continue;
            }

            switch ($type) {
                case 'number':
                    if (!is_numeric($value)) {
                        $errors[] = sprintf('Field "%s" must be numeric.', $label_path);
                        break;
                    }
                    $num = $value + 0;
                    if (isset($field['min']) && $num < $field['min']) {
                        $errors[] = sprintf('Field "%s" must be >= %s.', $label_path, $field['min']);
                    }
                    if (isset($field['max']) && $num > $field['max']) {
                        $errors[] = sprintf('Field "%s" must be <= %s.', $label_path, $field['max']);
                    }
                    break;

                case 'date':
                    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                        $errors[] = sprintf('Field "%s" must be in YYYY-MM-DD format.', $label_path);
                    }
                    break;

                case 'yesno':
                    if (!in_array($value, ['yes', 'no', true, false, 1, 0, '1', '0'], true)) {
                        $errors[] = sprintf('Field "%s" must be yes or no.', $label_path);
                    }
                    break;

                case 'select':
                    $options = isset($field['options']) && is_array($field['options']) ? $field['options'] : [];
                    if (!empty($options)) {
                        $option_values = array_keys($options) === range(0, count($options) - 1)
                            ? $options
                            : array_keys($options);
                        if (!in_array($value, $option_values, true)) {
                            $errors[] = sprintf('Field "%s" has an invalid selected value.', $label_path);
                        }
                    }
                    break;

                case 'checkbox':
                    if (!in_array($value, [true, false, 1, 0, '1', '0', 'on', 'off', 'yes', 'no'], true)) {
                        $errors[] = sprintf('Field "%s" must be a boolean.', $label_path);
                    }
                    break;

                case 'repeat_group':
                    if (!is_array($value)) {
                        if ($required) {
                            $errors[] = sprintf('Field "%s" must be a list of rows.', $label_path);
                        }
                        break;
                    }
                    $child_schema = is_array($field['fields'] ?? null) ? $field['fields'] : [];
                    foreach ($value as $i => $row) {
                        if (!is_array($row)) {
                            $errors[] = sprintf('Field "%s[%d]" must be an object.', $label_path, (int) $i);
                            continue;
                        }
                        $row_errors = self::validate_fields($child_schema, $row, $label_path . '[' . (int) $i . ']');
                        $errors = array_merge($errors, $row_errors);
                    }
                    break;

                case 'text':
                case 'textarea':
                default:
                    if (!is_scalar($value)) {
                        $errors[] = sprintf('Field "%s" must be a string.', $label_path);
                    }
                    break;
            }
        }

        return $errors;
    }

    /**
     * Evaluate a field's `show_when` condition against current form data.
     *
     * Supports two forms:
     *   ['field' => 'x', 'equals'            => 'value']
     *   ['field' => 'x', 'not_equals_field'  => 'y']  // true when form[x] !== form[y]
     */
    public static function field_is_visible(array $field, array $form_data): bool {
        if (empty($field['show_when']) || !is_array($field['show_when'])) {
            return true;
        }

        $cond = $field['show_when'];
        if (empty($cond['field'])) {
            return true;
        }

        $actual = $form_data[$cond['field']] ?? null;

        if (array_key_exists('equals', $cond)) {
            return (string) $actual === (string) $cond['equals'];
        }

        if (!empty($cond['not_equals_field'])) {
            $compare_to = $form_data[$cond['not_equals_field']] ?? null;
            return (string) $actual !== (string) $compare_to;
        }

        return true;
    }
}
