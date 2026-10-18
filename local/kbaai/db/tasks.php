<?php
defined('MOODLE_INTERNAL') || die();

$tasks = array(
    array(
        'classname' => '\\local_kbaai\\task\\scan_submissions',
        'blocking' => 0,
        'minute' => '*',
        'hour' => '*',
        'day' => '*',
        'month' => '*',
        'dayofweek' => '*',
    ),
);
