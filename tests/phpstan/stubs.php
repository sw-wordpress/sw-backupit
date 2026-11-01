<?php

/**
 * PHPStan stubs for WP-CLI (only present when the plugin runs under `wp`; never loaded at runtime).
 *
 * @package sw-backupit
 */

// phpcs:disable Squiz.Classes.ValidClassName.NotCamelCaps,PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- mirrors the WP-CLI API.

namespace {
    class WP_CLI
    {
        /**
         * @param string $name
         * @param callable|object|string $callable
         * @param array<string, mixed> $args
         */
        public static function add_command($name, $callable, $args = [])
        {
        }

        /**
         * @param string|\WP_Error $message
         * @param bool|int $exit
         * @return ($exit is false ? void : never)
         */
        public static function error($message, $exit = true)
        {
        }

        /** @param string $message */
        public static function success($message)
        {
        }

        /** @param string $message */
        public static function warning($message)
        {
        }

        /** @param string $message */
        public static function log($message)
        {
        }

        /** @param string $message */
        public static function line($message = '')
        {
        }

        /**
         * @param string $message
         * @param string|bool $group
         */
        public static function debug($message, $group = false)
        {
        }
    }
}

namespace WP_CLI\Utils {
    /**
     * @param string $format
     * @param iterable<mixed> $items
     * @param string|array<int, string> $fields
     */
    function format_items($format, $items, $fields)
    {
    }
}
