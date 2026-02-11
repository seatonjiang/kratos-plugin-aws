<?php

/**
 * Plugin Name: 对象存储工具包
 * Description: 使用对象存储服务所需的工具包，对象存储是一种专为海量非结构化数据设计的云存储架构，为客户提供海量、安全、高可靠、低成本的数据存储能力。
 * Version: 2026.02.11
 * Plugin URI: https://github.com/seatonjiang/kratos-plugin-aws
 * Author: Seaton Jiang
 * Author URI: https://seatonjiang.com
 * License: MIT License
 * License URI: https://github.com/seatonjiang/kratos-plugin-aws/blob/main/LICENSE
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once plugin_dir_path(__FILE__) . 'vendor/autoload.php';
require_once plugin_dir_path(__FILE__) . 'includes/kratos-update-checker.php';

add_action('init', function () {
    if (is_admin()) {
        new Kratos_Update_Checker_AWS(
            __FILE__,
            'https://dl.seatonjiang.com/kratos/info/kratos-plugin-aws.json',
            'kratos_plugin_aws'
        );
    }
});
