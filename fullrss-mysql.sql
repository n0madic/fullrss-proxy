SET NAMES utf8;
SET time_zone = '+00:00';
SET foreign_key_checks = 0;
SET sql_mode = 'NO_AUTO_VALUE_ON_ZERO';

DROP TABLE IF EXISTS `feeds`;
CREATE TABLE `feeds` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `enabled` int(1) NOT NULL DEFAULT '1',
  `name` varchar(20) NOT NULL,
  `description` varchar(100) NOT NULL,
  `charset` varchar(20) NOT NULL DEFAULT 'UTF-8',
  `url` varchar(255) NOT NULL,
  `method` char(20) NOT NULL,
  `method_detail` text NOT NULL,
  `filter` text NOT NULL,
  `imgfix` varchar(255) NOT NULL DEFAULT '',
  `xml` mediumtext NOT NULL,
  `lastupdate` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;


DROP TABLE IF EXISTS `log`;
CREATE TABLE `log` (
  `id` varchar(20) NOT NULL DEFAULT '',
  `time` int(11) NOT NULL,
  `text` varchar(200) NOT NULL,
  KEY `log_id` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;


DROP TABLE IF EXISTS `settings`;
CREATE TABLE `settings` (
  `admin_pass` char(50) NOT NULL DEFAULT '1411678a0b9e25ee2f7c8b2f7ac92b6a74b3f9c5',
  `locale` char(5) NOT NULL DEFAULT 'ru_RU'
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

INSERT INTO `settings` (`admin_pass`, `locale`) VALUES
('1411678a0b9e25ee2f7c8b2f7ac92b6a74b3f9c5',	'ru_RU');
