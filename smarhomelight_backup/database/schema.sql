-- ============================================================
--  SmartHome Light - XAMPP / MySQL (MariaDB) schema
--  Run via install.php, phpMyAdmin, or:
--  mysql -u root < database/schema.sql
-- ============================================================

CREATE DATABASE IF NOT EXISTS `smartlight`
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `smartlight`;

-- ---------- users ----------
CREATE TABLE IF NOT EXISTS `users` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username`      VARCHAR(64)  NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `full_name`     VARCHAR(120) NOT NULL DEFAULT '',
  `created_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- devices (one row per ESP32) ----------
CREATE TABLE IF NOT EXISTS `devices` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `device_key`     VARCHAR(64)  NOT NULL,
  `name`           VARCHAR(80)  NOT NULL,
  `room`           VARCHAR(80)  NOT NULL DEFAULT 'Living Room',
  `ip_address`     VARCHAR(45)  NOT NULL DEFAULT '',
  `firmware`       VARCHAR(40)  NOT NULL DEFAULT '',
  `rssi`           SMALLINT     NOT NULL DEFAULT 0,
  `is_online`      TINYINT(1)   NOT NULL DEFAULT 0,
  `last_seen`      DATETIME     NULL DEFAULT NULL,
  `boot_count`     INT UNSIGNED NOT NULL DEFAULT 0,
  `free_heap`      INT UNSIGNED NOT NULL DEFAULT 0,
  -- What the device LAST REPORTED it is really doing.
  -- Kept separate from light_state (the DESIRED state) so that a device
  -- reporting its current status can never overwrite the user's command.
  `actual_on`        TINYINT(1)   NOT NULL DEFAULT 0,
  `actual_brightness` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `actual_r`         SMALLINT UNSIGNED NOT NULL DEFAULT 255,
  `actual_g`         SMALLINT UNSIGNED NOT NULL DEFAULT 214,
  `actual_b`         SMALLINT UNSIGNED NOT NULL DEFAULT 175,
  `actual_mode`      ENUM('white','color') NOT NULL DEFAULT 'white',
  `ldr`              SMALLINT UNSIGNED NOT NULL DEFAULT 0,  -- last LDR raw reading (0..4095)
  `room_dark`        TINYINT(1)   NOT NULL DEFAULT 0,       -- 1 when the room read as dark
  -- Who currently owns the relay: the LDR sensor (auto), the dashboard
  -- (manual - a bounded 60 s hold after each web command) or the schedule
  -- runner (schedule). The dashboard never sends this directly; the backend
  -- and the ESP32 heartbeat/poll cycle maintain it.
  `op_mode`          ENUM('auto','manual','schedule') NOT NULL DEFAULT 'auto',
  `reported_at`      DATETIME     NULL DEFAULT NULL,
  `created_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_devices_key` (`device_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- live state of each light ----------
CREATE TABLE IF NOT EXISTS `light_state` (
  `device_id`  INT UNSIGNED NOT NULL,
  `is_on`      TINYINT(1)   NOT NULL DEFAULT 0,
  `brightness` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `color_r`    SMALLINT UNSIGNED NOT NULL DEFAULT 255,
  `color_g`    SMALLINT UNSIGNED NOT NULL DEFAULT 214,
  `color_b`    SMALLINT UNSIGNED NOT NULL DEFAULT 175,
  `mode`       ENUM('white','color') NOT NULL DEFAULT 'white',
  `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`device_id`),
  CONSTRAINT `fk_state_device` FOREIGN KEY (`device_id`)
    REFERENCES `devices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- command queue (web/schedule -> ESP32) ----------
CREATE TABLE IF NOT EXISTS `commands` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `device_id`   INT UNSIGNED NOT NULL,
  `command`     VARCHAR(32)  NOT NULL,
  `payload`     TEXT         NULL,
  `status`      ENUM('pending','sent','done','failed') NOT NULL DEFAULT 'pending',
  `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `executed_at` DATETIME     NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_cmd_device_status` (`device_id`,`status`,`id`),
  CONSTRAINT `fk_cmd_device` FOREIGN KEY (`device_id`)
    REFERENCES `devices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- schedules ----------
CREATE TABLE IF NOT EXISTS `schedules` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `device_id`   INT UNSIGNED NOT NULL,
  `label`       VARCHAR(80)  NOT NULL DEFAULT 'Schedule',
  `time_of_day` TIME         NOT NULL,
  `days_of_week` VARCHAR(20)  NOT NULL DEFAULT '1111111',
  `is_on`       TINYINT(1)   NOT NULL DEFAULT 1,
  `brightness`  TINYINT UNSIGNED NOT NULL DEFAULT 100,
  `color_r`     SMALLINT UNSIGNED NOT NULL DEFAULT 255,
  `color_g`     SMALLINT UNSIGNED NOT NULL DEFAULT 214,
  `color_b`     SMALLINT UNSIGNED NOT NULL DEFAULT 175,
  `enabled`     TINYINT(1)   NOT NULL DEFAULT 1,
  `last_run`    DATETIME     NULL DEFAULT NULL,
  `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_sched_enabled` (`enabled`,`time_of_day`),
  CONSTRAINT `fk_sched_device` FOREIGN KEY (`device_id`)
    REFERENCES `devices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- hourly energy buckets ----------
CREATE TABLE IF NOT EXISTS `energy_readings` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `device_id`    INT UNSIGNED NOT NULL,
  `bucket_hour`  DATETIME     NOT NULL,
  `minutes_on`   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `energy_wh`    DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_energy_device_hour` (`device_id`,`bucket_hour`),
  CONSTRAINT `fk_energy_device` FOREIGN KEY (`device_id`)
    REFERENCES `devices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- activity log ----------
CREATE TABLE IF NOT EXISTS `activity_log` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `device_id`  INT UNSIGNED NULL DEFAULT NULL,
  `source`     ENUM('web','esp32','schedule','system') NOT NULL DEFAULT 'web',
  `action`     VARCHAR(80)  NOT NULL,
  `detail`     TEXT         NULL,
  `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_log_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- key/value settings ----------
CREATE TABLE IF NOT EXISTS `settings` (
  `setting_key`   VARCHAR(64)  NOT NULL,
  `setting_value` VARCHAR(255) NOT NULL,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- default settings ----------
INSERT INTO `settings` (`setting_key`,`setting_value`) VALUES
  ('bulb_watts',      '9'),
  ('offline_after',   '90'),
  ('poll_interval',   '5'),
  ('site_name',       'SmartHome Light')
ON DUPLICATE KEY UPDATE `setting_value` = `setting_value`;