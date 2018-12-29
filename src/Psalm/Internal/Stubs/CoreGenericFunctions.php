<?php
/**
 * @psalm-template T
 *
 * @param array<T, mixed> $arr
 * @param mixed           $search_value
 * @param bool            $strict
 * @return array<int, T>
 */
function array_keys(array $arr, $search_value = null, bool $strict = false) {}

/**
 * @psalm-template T
 *
 * @param array<mixed, T> $arr
 * @return array<int, T>
 */
function array_values(array $arr) {}

/**
 * @psalm-template T
 *
 * @param array<mixed, T> $arr
 * @param int $sort_flags
 * @return array<int, T>
 */
function array_unique(array $arr, int $sort_flags = 0) {}

/**
 * @psalm-template TKey
 * @psalm-template TValue
 *
 * @param array<TKey, TValue> $arr
 * @param array $arr2
 * @param array|null $arr3
 * @param array|null $arr4
 * @return array<TKey, TValue>
 */
function array_intersect(array $arr, array $arr2, array $arr3 = null, array $arr4 = null) {}

/**
 * @psalm-template TKey
 * @psalm-template TValue
 *
 * @param array<TKey, TValue> $arr
 * @param array $arr2
 * @param array|null $arr3
 * @param array|null $arr4
 * @return array<TKey, TValue>
 */
function array_intersect_key(array $arr, array $arr2, array $arr3 = null, array $arr4 = null) {}

/**
 * @psalm-template TKey
 * @psalm-template TValue
 *
 * @param array<mixed, TKey> $arr
 * @param array<mixed, TValue> $arr2
 * @return array<TKey, TValue>
 */
function array_combine(array $arr, array $arr2) {}

/**
 * @psalm-template TKey
 * @psalm-template TValue
 *
 * @param array<TKey, TValue> $arr
 * @param array $arr2
 * @param array|null $arr3
 * @param array|null $arr4
 * @return array<TKey, TValue>
 */
function array_diff(array $arr, array $arr2, array $arr3 = null, array $arr4 = null) {}

/**
 * @psalm-template TKey
 * @psalm-template TValue
 *
 * @param array<TKey, TValue> $arr
 * @param array $arr2
 * @param array|null $arr3
 * @param array|null $arr4
 * @return array<TKey, TValue>
 */
function array_diff_key(array $arr, array $arr2, array $arr3 = null, array $arr4 = null) {}

/**
 * @psalm-template TKey
 * @psalm-template TValue
 *
 * @param array<TKey, TValue> $arr
 * @param bool            $preserve_keys
 * @return array<TKey, TValue>
 */
function array_reverse(array $arr, bool $preserve_keys = false) {}

/**
 * @psalm-template TKey
 * @psalm-template TValue
 *
 * @param array<TKey, TValue> $arr
 * @return array<TValue, TKey>
 */
function array_flip(array $arr) {}

/**
 * @psalm-template TKey
 *
 * @param array<TKey, mixed> $arr
 * @return TKey|null
 * @psalm-ignore-nullable-return
 */
function key($arr) {}

/**
 * @psalm-template T
 *
 * @param mixed           $needle
 * @param array<T, mixed> $haystack
 * @param bool            $strict
 * @return T|false
 */
function array_search($needle, array $haystack, bool $strict = false) {}
