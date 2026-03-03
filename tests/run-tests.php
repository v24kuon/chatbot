<?php

/**
 * Fallback Test Runner
 * This script runs the tests when PHPUnit is not available.
 * It is designed to be compatible with PHPUnit test classes.
 */

require_once __DIR__ . '/bootstrap.php';

$test_files = glob(__DIR__ . '/*Test.php');
$failed = false;

foreach ($test_files as $file) {
    require_once $file;
    $class_name = basename($file, '.php');
    if (class_exists($class_name)) {
        $test_instance = new $class_name();
        $methods = get_class_methods($class_name);
        foreach ($methods as $method) {
            if (strpos($method, 'test_') === 0) {
                echo "Running {$class_name}::{$method}... ";
                try {
                    $test_instance->setUp();
                    $test_instance->$method();
                    echo "PASSED\n";
                } catch (\Exception $e) {
                    echo "FAILED: " . $e->getMessage() . "\n";
                    echo $e->getTraceAsString() . "\n";
                    $failed = true;
                } finally {
                    $test_instance->tearDown();
                }
            }
        }
    }
}

if ($failed) {
    exit(1);
} else {
    echo "All tests passed!\n";
    exit(0);
}
