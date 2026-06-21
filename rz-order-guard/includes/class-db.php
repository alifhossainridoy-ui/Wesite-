<?php
namespace RZOG;

defined('ABSPATH') || exit;

class DB {

    public static function install(): void {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // Manual IP/phone blocklist (kept from old plugin — simple, useful, no reason to drop)
        $table_blocklist = self::table('blocklist');
        dbDelta("CREATE TABLE {$table_blocklist} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            ip_address VARCHAR(45) NULL,
            phone_number VARCHAR(20) NULL,
            reason VARCHAR(191) NULL,
            block_start DATETIME NOT NULL,
            block_end DATETIME NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY idx_ip (ip_address),
            KEY idx_phone (phone_number)
        ) {$charset_collate};");

        // Incomplete / abandoned leads captured before order submit (manual follow-up only — no SMS automation)
        $table_leads = self::table('leads');
        dbDelta("CREATE TABLE {$table_leads} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            session_id VARCHAR(64) NULL,
            name VARCHAR(191) NULL,
            phone VARCHAR(20) NULL,
            address TEXT NULL,
            product_id BIGINT UNSIGNED NULL,
            product_name VARCHAR(191) NULL,
            value DECIMAL(10,2) NULL,
            currency VARCHAR(10) NULL,
            fbp VARCHAR(191) NULL,
            fbc VARCHAR(191) NULL,
            source_url VARCHAR(500) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'new',
            order_id BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY idx_phone (phone),
            KEY idx_session (session_id),
            KEY idx_status (status)
        ) {$charset_collate};");

        // Fraud-check result cache — avoids hammering external API on repeat checkout attempts
        $table_cache = self::table('fraud_cache');
        dbDelta("CREATE TABLE {$table_cache} (
            phone_number VARCHAR(20) NOT NULL,
            source VARCHAR(20) NOT NULL,
            total INT UNSIGNED NOT NULL DEFAULT 0,
            delivered INT UNSIGNED NOT NULL DEFAULT 0,
            cancelled INT UNSIGNED NOT NULL DEFAULT 0,
            checked_at DATETIME NOT NULL,
            PRIMARY KEY (phone_number, source)
        ) {$charset_collate};");

        update_option('rzog_db_version', RZOG_VERSION);
    }

    public static function table(string $name): string {
        global $wpdb;
        return $wpdb->prefix . 'rzog_' . $name;
    }
}
