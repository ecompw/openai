<?php
require_once __DIR__ . '/includes/plugin-update-checker-master/plugin-update-checker.php';

if (class_exists('PucFactory')) {
    echo "PucFactory class loaded successfully!";
} else {
    echo "PucFactory class NOT found!";
}