<?php
if (!defined('ABSPATH')) exit;

/** Databastabeller och uppgradering. Körs även efter uppdatering via ZIP, inte bara vid aktivering. */
final class EKA_DB {
    const VERSION = '2';

    public static function init() {
        add_action('plugins_loaded', [__CLASS__, 'maybe_upgrade']);
    }

    public static function table($name) {
        global $wpdb;
        return $wpdb->prefix . 'eka_' . $name;
    }

    public static function maybe_upgrade() {
        if (get_option('eka_db_version') !== self::VERSION) self::install();
    }

    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $registrations = self::table('registrations');
        $participants = self::table('participants');
        $subscribers = self::table('subscribers');
        $mail_log = self::table('mail_log');

        dbDelta("CREATE TABLE $registrations (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  course_id bigint(20) unsigned NOT NULL,
  session_key varchar(191) NOT NULL,
  name varchar(191) NOT NULL,
  email varchar(191) NOT NULL,
  phone varchar(50) NOT NULL,
  participants smallint unsigned NOT NULL DEFAULT 1,
  total_amount decimal(10,2) NOT NULL DEFAULT 0,
  deposit_amount decimal(10,2) NOT NULL DEFAULT 0,
  payment_reference varchar(50) NOT NULL,
  payment_status varchar(20) NOT NULL DEFAULT 'pending',
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY course_id (course_id),
  KEY course_session (course_id,session_key),
  UNIQUE KEY payment_reference (payment_reference)
) $charset;");

        dbDelta("CREATE TABLE $participants (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  registration_id bigint(20) unsigned NOT NULL,
  name varchar(191) NOT NULL,
  personal_number text NULL,
  PRIMARY KEY  (id),
  KEY registration_id (registration_id)
) $charset;");

        dbDelta("CREATE TABLE $subscribers (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  course_id bigint(20) unsigned NOT NULL,
  email varchar(191) NOT NULL,
  name varchar(191) NOT NULL DEFAULT '',
  newsletter tinyint(1) NOT NULL DEFAULT 0,
  status varchar(20) NOT NULL DEFAULT 'subscribed',
  token varchar(64) NOT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY course_email (course_id,email),
  UNIQUE KEY token (token)
) $charset;");

        dbDelta("CREATE TABLE $mail_log (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  course_id bigint(20) unsigned NOT NULL,
  subject varchar(255) NOT NULL,
  audience varchar(30) NOT NULL,
  recipients int unsigned NOT NULL DEFAULT 0,
  failed int unsigned NOT NULL DEFAULT 0,
  sent_by bigint(20) unsigned NOT NULL DEFAULT 0,
  sent_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY course_id (course_id)
) $charset;");

        update_option('eka_db_version', self::VERSION);
    }
}
