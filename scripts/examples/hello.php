<?php
/**
 * Greeting (inline): shows how parameters reach a script.
 * @param name   who to greet (default: "world")
 * @example name="Anna"
 */

defined('PAGEYARD') or exit;

/** @var array<string, string|true> $params */
$name = is_string($params['name'] ?? null) ? $params['name'] : 'world';

// Always escape what you print: e() is Pageyard's htmlspecialchars.
echo '<strong>Hello, ' . e($name) . '!</strong>';
