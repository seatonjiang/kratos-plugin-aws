<?php

/**
 * Plugin Name: 对象存储工具包
 * Description: 使用分布式存储服务所需的工具包，提供 CDN 加速以及涉黄、涉恐、涉暴内容自动审核功能，具有高扩展性、低成本、可靠安全等优点。
 * Version: 2025.12.06
 * Plugin URI: https://github.com/seatonjiang/kratos-plugin-aws
 * Author: Seaton Jiang
 * Author URI: https://seatonjiang.com
 * License: MIT License
 * License URI: https://github.com/seatonjiang/kratos-plugin-aws/blob/main/LICENSE
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!has_action('kratos-plugin-aws')) {
    add_action('kratos-plugin-aws', function () {
        require_once plugin_dir_path(__FILE__) . '/vendor/autoload.php';
    });
}

require_once(plugin_dir_path(__FILE__) . '/update-checker/autoload.php');
$kratos_plugin_update_check = KratosUpdateChecker\Factory::buildUpdateChecker(
    'https://dl.seatonjiang.com/kratos/info/plugin-aws.json',
    __FILE__,
    'kratos-plugin-aws',
    24,
    'kratos-plugin-aws-update'
);
