<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_kbagamification_get_rewards_catalog' => [
        'classname' => 'local_kbagamification\\external_service',
        'methodname' => 'get_rewards_catalog',
        'description' => 'Get the enabled KBA Rewards catalogue and eligibility for one Moodle user.',
        'type' => 'read',
        'capabilities' => 'local/kbagamification:externalrewards',
    ],
    'local_kbagamification_get_user_summary' => [
        'classname' => 'local_kbagamification\\external_service',
        'methodname' => 'get_user_summary',
        'description' => 'Get KBA XP, Coins, level and achievement summary for one Moodle user.',
        'type' => 'read',
        'capabilities' => 'local/kbagamification:externalrewards',
    ],
    'local_kbagamification_get_user_achievements' => [
        'classname' => 'local_kbagamification\\external_service',
        'methodname' => 'get_user_achievements',
        'description' => 'Get awarded KBA Gamification achievements for one Moodle user.',
        'type' => 'read',
        'capabilities' => 'local/kbagamification:externalrewards',
    ],
    'local_kbagamification_check_reward_eligibility' => [
        'classname' => 'local_kbagamification\\external_service',
        'methodname' => 'check_reward_eligibility',
        'description' => 'Check KBA Points balance, stock and level eligibility for one reward.',
        'type' => 'read',
        'capabilities' => 'local/kbagamification:externalrewards',
    ],
    'local_kbagamification_reserve_reward' => [
        'classname' => 'local_kbagamification\\external_service',
        'methodname' => 'reserve_reward',
        'description' => 'Reserve KBA Points and finite reward stock for a trusted external checkout.',
        'type' => 'write',
        'capabilities' => 'local/kbagamification:externalrewards',
    ],
    'local_kbagamification_get_reward_reservation' => [
        'classname' => 'local_kbagamification\\external_service',
        'methodname' => 'get_reward_reservation',
        'description' => 'Read the current state of a KBA Rewards reservation.',
        'type' => 'read',
        'capabilities' => 'local/kbagamification:externalrewards',
    ],
    'local_kbagamification_commit_reward_reservation' => [
        'classname' => 'local_kbagamification\\external_service',
        'methodname' => 'commit_reward_reservation',
        'description' => 'Commit a reward reservation after the external marketplace creates its order.',
        'type' => 'write',
        'capabilities' => 'local/kbagamification:externalrewards',
    ],
    'local_kbagamification_cancel_reward_reservation' => [
        'classname' => 'local_kbagamification\\external_service',
        'methodname' => 'cancel_reward_reservation',
        'description' => 'Cancel an uncommitted reward reservation and release protected points/stock.',
        'type' => 'write',
        'capabilities' => 'local/kbagamification:externalrewards',
    ],
    'local_kbagamification_get_user_reward_orders' => [
        'classname' => 'local_kbagamification\\external_service',
        'methodname' => 'get_user_reward_orders',
        'description' => 'Get KBA Rewards order history for one Moodle user.',
        'type' => 'read',
        'capabilities' => 'local/kbagamification:externalrewards',
    ],
    'local_kbagamification_create_reward_order' => [
        'classname' => 'local_kbagamification\\external_service',
        'methodname' => 'create_reward_order',
        'description' => 'Create a KBA Rewards order and debit KBA Coins for one Moodle user.',
        'type' => 'write',
        'capabilities' => 'local/kbagamification:externalrewards',
    ],
    'local_kbagamification_get_company_snapshot' => [
        'classname' => 'local_kbagamification\\external_service',
        'methodname' => 'get_company_snapshot',
        'description' => 'Get aggregate KBA Gamification figures and leaderboard for a supplied company employee cohort.',
        'type' => 'read',
        'capabilities' => 'local/kbagamification:externalcorporate',
    ],
];

$services = [
    'KBA Gamification Rewards API' => [
        'functions' => [
            'local_kbagamification_get_rewards_catalog',
            'local_kbagamification_get_user_summary',
            'local_kbagamification_get_user_achievements',
            'local_kbagamification_get_user_reward_orders',
            'local_kbagamification_check_reward_eligibility',
            'local_kbagamification_reserve_reward',
            'local_kbagamification_get_reward_reservation',
            'local_kbagamification_commit_reward_reservation',
            'local_kbagamification_cancel_reward_reservation',
            'local_kbagamification_create_reward_order',
        ],
        'requiredcapability' => 'local/kbagamification:externalrewards',
        'restrictedusers' => 1,
        'enabled' => 0,
        'shortname' => 'kba_gamification_rewards_api',
        'downloadfiles' => 0,
        'uploadfiles' => 0,
    ],
    'KBA Gamification Corporate API' => [
        'functions' => [
            'local_kbagamification_get_company_snapshot',
        ],
        'requiredcapability' => 'local/kbagamification:externalcorporate',
        'restrictedusers' => 1,
        'enabled' => 0,
        'shortname' => 'kba_gamification_corporate_api',
        'downloadfiles' => 0,
        'uploadfiles' => 0,
    ],
];
