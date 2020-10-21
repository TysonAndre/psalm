<?php

class tag_array {
    /**
     * @param array $array
     * @param int|string $field
     * @param mixed $default
     * @return int
     */
    public static function get($array, $field, $default = null) {
        return $array[$field] ?? $default;
    }
}

/**
 * @param array<string,string> $data
 * @return string
 */
function test_arrayutil_get($data) {
    return tag_array::get($data, 'key', 'default');
}

/**
 * @param array<string,string> $data
 * @return string (should warn)
 */
function test_arrayutil_get_null($data) {
    return tag_array::get($data, 'key');
}

/**
 * @param array<string,string> $data
 * @return string (should warn)
 */
function test_arrayutil_get_explicit_null($data) {
    return tag_array::get($data, 'key', null);
}

/**
 * @param array{field:string} $data
 * @return string
 */
function test_arrayutil_get_has_objectlike($data) {
    return tag_array::get($data, 'field');
}

/**
 * @param array{field:string} $data
 * @return string
 */
function test_arrayutil_get_missing_objectlike($data) {
    return tag_array::get($data, 'otherField');  // should be inferred as null or warn about missing fields
}
