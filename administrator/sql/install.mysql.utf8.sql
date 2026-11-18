CREATE TABLE IF NOT EXISTS `#__zabbix_settings` (
  `name` VARCHAR(100) NOT NULL,
  `value` MEDIUMTEXT NOT NULL,
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__zabbix_usermap` (
  `joomla_user_id` INT UNSIGNED NOT NULL,
  `zabbix_userid` VARCHAR(20) NULL DEFAULT NULL,
  `zabbix_username` VARCHAR(100) NULL DEFAULT NULL,
  `email` VARCHAR(320) NOT NULL DEFAULT '',
  `status` VARCHAR(20) NOT NULL,
  `source` VARCHAR(10) NOT NULL DEFAULT 'auto',
  `matched_at` DATETIME NULL DEFAULT NULL,
  `checked_at` DATETIME NOT NULL,
  `modified_by` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`joomla_user_id`),
  KEY `idx_zabbix_userid` (`zabbix_userid`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__zabbix_cache` (
  `cache_key` VARCHAR(191) NOT NULL,
  `user_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `expires` BIGINT NOT NULL,
  `value` MEDIUMTEXT NOT NULL,
  PRIMARY KEY (`cache_key`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_expires` (`expires`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__zabbix_audit` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `actor_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `action` VARCHAR(50) NOT NULL,
  `result` VARCHAR(20) NOT NULL,
  `detail` VARCHAR(255) NOT NULL DEFAULT '',
  `created` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_action_created` (`user_id`, `action`, `created`),
  KEY `idx_action_created` (`action`, `created`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;
