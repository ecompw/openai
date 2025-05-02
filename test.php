<?php
require_once __DIR__ . '/includes/plugin-update-checker-master/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

if (class_exists('PucFactory')) {
    echo "PucFactory class loaded successfully!";
} else {
    echo "PucFactory class NOT found!";
}