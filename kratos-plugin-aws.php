<?php

/**
 * Plugin Name: 对象存储工具包
 * Description: 使用对象存储服务所需的工具包，对象存储是一种专为海量非结构化数据设计的云存储架构，为客户提供海量、安全、高可靠、低成本的数据存储能力。
 * Version: 2025.12.21
 * Plugin URI: https://github.com/seatonjiang/kratos-plugin-aws
 * Author: Seaton Jiang
 * Author URI: https://seatonjiang.com
 * License: MIT License
 * License URI: https://github.com/seatonjiang/kratos-plugin-aws/blob/main/LICENSE
 */

if (!defined('ABSPATH')) {
    exit;
}

if (file_exists(plugin_dir_path(__FILE__) . 'vendor/autoload.php')) {
    require_once plugin_dir_path(__FILE__) . 'vendor/autoload.php';
}

if (file_exists(plugin_dir_path(__FILE__) . 'kratos-update-checker.php')) {
    require_once plugin_dir_path(__FILE__) . 'kratos-update-checker.php';
}

add_action('init', function () {
    new Kratos_Update_Checker\Plugin('https://update.seatonjiang.com/theme.json', 'kratos_update_checker_plugin_aws');
});
