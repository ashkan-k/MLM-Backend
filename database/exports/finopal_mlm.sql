-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: finopal_mlm
-- ------------------------------------------------------
-- Server version	10.4.32-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Current Database: `finopal_mlm`
--

CREATE DATABASE /*!32312 IF NOT EXISTS*/ `finopal_mlm` /*!40100 DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci */;

USE `finopal_mlm`;

--
-- Table structure for table `audit_logs`
--

DROP TABLE IF EXISTS `audit_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `audit_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `actor_user_id` bigint(20) unsigned DEFAULT NULL,
  `action` varchar(255) NOT NULL,
  `auditable_type` varchar(255) NOT NULL,
  `auditable_id` bigint(20) unsigned DEFAULT NULL,
  `old_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`old_values`)),
  `new_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`new_values`)),
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `audit_logs_actor_user_id_foreign` (`actor_user_id`),
  KEY `audit_logs_auditable_type_auditable_id_index` (`auditable_type`,`auditable_id`),
  CONSTRAINT `audit_logs_actor_user_id_foreign` FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `audit_logs`
--

LOCK TABLES `audit_logs` WRITE;
/*!40000 ALTER TABLE `audit_logs` DISABLE KEYS */;
/*!40000 ALTER TABLE `audit_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `benefit_transfer_items`
--

DROP TABLE IF EXISTS `benefit_transfer_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `benefit_transfer_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `benefit_transfer_id` bigint(20) unsigned NOT NULL,
  `gateway_representative_id` bigint(20) unsigned DEFAULT NULL,
  `share_percent` decimal(8,3) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `benefit_transfer_items_benefit_transfer_id_foreign` (`benefit_transfer_id`),
  KEY `benefit_transfer_items_gateway_representative_id_foreign` (`gateway_representative_id`),
  CONSTRAINT `benefit_transfer_items_benefit_transfer_id_foreign` FOREIGN KEY (`benefit_transfer_id`) REFERENCES `benefit_transfers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `benefit_transfer_items_gateway_representative_id_foreign` FOREIGN KEY (`gateway_representative_id`) REFERENCES `gateway_representatives` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `benefit_transfer_items`
--

LOCK TABLES `benefit_transfer_items` WRITE;
/*!40000 ALTER TABLE `benefit_transfer_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `benefit_transfer_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `benefit_transfers`
--

DROP TABLE IF EXISTS `benefit_transfers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `benefit_transfers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `from_user_id` bigint(20) unsigned NOT NULL,
  `to_user_id` bigint(20) unsigned NOT NULL,
  `gateway_sale_id` bigint(20) unsigned DEFAULT NULL,
  `transfer_type` varchar(255) NOT NULL,
  `status` varchar(255) NOT NULL DEFAULT 'active',
  `effective_from` datetime NOT NULL,
  `reason` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `benefit_transfers_from_user_id_foreign` (`from_user_id`),
  KEY `benefit_transfers_to_user_id_foreign` (`to_user_id`),
  KEY `benefit_transfers_gateway_sale_id_foreign` (`gateway_sale_id`),
  CONSTRAINT `benefit_transfers_from_user_id_foreign` FOREIGN KEY (`from_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `benefit_transfers_gateway_sale_id_foreign` FOREIGN KEY (`gateway_sale_id`) REFERENCES `gateway_sales` (`id`) ON DELETE SET NULL,
  CONSTRAINT `benefit_transfers_to_user_id_foreign` FOREIGN KEY (`to_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `benefit_transfers`
--

LOCK TABLES `benefit_transfers` WRITE;
/*!40000 ALTER TABLE `benefit_transfers` DISABLE KEYS */;
/*!40000 ALTER TABLE `benefit_transfers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cache`
--

DROP TABLE IF EXISTS `cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cache` (
  `key` varchar(255) NOT NULL,
  `value` mediumtext NOT NULL,
  `expiration` int(11) NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cache`
--

LOCK TABLES `cache` WRITE;
/*!40000 ALTER TABLE `cache` DISABLE KEYS */;
/*!40000 ALTER TABLE `cache` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cache_locks`
--

DROP TABLE IF EXISTS `cache_locks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cache_locks` (
  `key` varchar(255) NOT NULL,
  `owner` varchar(255) NOT NULL,
  `expiration` int(11) NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_locks_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cache_locks`
--

LOCK TABLES `cache_locks` WRITE;
/*!40000 ALTER TABLE `cache_locks` DISABLE KEYS */;
/*!40000 ALTER TABLE `cache_locks` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `commission_rule_versions`
--

DROP TABLE IF EXISTS `commission_rule_versions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `commission_rule_versions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `commission_rule_id` bigint(20) unsigned NOT NULL,
  `version` int(10) unsigned NOT NULL,
  `percent` decimal(8,3) NOT NULL,
  `qualified_percent` decimal(8,3) DEFAULT NULL,
  `conditions` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`conditions`)),
  `effective_from` datetime NOT NULL,
  `effective_to` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `commission_rule_versions_commission_rule_id_version_unique` (`commission_rule_id`,`version`),
  CONSTRAINT `commission_rule_versions_commission_rule_id_foreign` FOREIGN KEY (`commission_rule_id`) REFERENCES `commission_rules` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `commission_rule_versions`
--

LOCK TABLES `commission_rule_versions` WRITE;
/*!40000 ALTER TABLE `commission_rule_versions` DISABLE KEYS */;
INSERT INTO `commission_rule_versions` VALUES (1,1,1,4.000,4.000,'{\"code\":\"senior_manager\",\"name\":\"\\u0645\\u062f\\u06cc\\u0631 \\u0627\\u0631\\u0634\\u062f\",\"percent\":\"4.000\",\"qualified\":\"4.000\",\"type\":null}','2025-09-17 09:28:25',NULL,'2026-09-17 05:58:25','2026-09-17 05:58:25'),(2,2,1,4.500,6.000,'{\"code\":\"development_manager\",\"name\":\"\\u0645\\u062f\\u06cc\\u0631 \\u062a\\u0648\\u0633\\u0639\\u0647\",\"percent\":\"4.500\",\"qualified\":\"6.000\",\"type\":\"monthly_gateways\"}','2025-09-17 09:28:25',NULL,'2026-09-17 05:58:25','2026-09-17 05:58:25'),(3,3,1,6.000,8.000,'{\"code\":\"sales_manager\",\"name\":\"\\u0645\\u062f\\u06cc\\u0631 \\u0641\\u0631\\u0648\\u0634\",\"percent\":\"6.000\",\"qualified\":\"8.000\",\"type\":\"monthly_gateways\"}','2025-09-17 09:28:25',NULL,'2026-09-17 05:58:25','2026-09-17 05:58:25'),(4,4,1,2.000,2.000,'{\"code\":\"representative_referrer\",\"name\":\"\\u0646\\u0645\\u0627\\u06cc\\u0646\\u062f\\u0647 \\u0645\\u0639\\u0631\\u0641\",\"percent\":\"2.000\",\"qualified\":\"2.000\",\"type\":null}','2025-09-17 09:28:25',NULL,'2026-09-17 05:58:25','2026-09-17 05:58:25'),(5,5,1,15.000,20.000,'{\"code\":\"representative\",\"name\":\"\\u0646\\u0645\\u0627\\u06cc\\u0646\\u062f\\u0647\",\"percent\":\"15.000\",\"qualified\":\"20.000\",\"type\":\"monthly_points\"}','2025-09-17 09:28:25',NULL,'2026-09-17 05:58:25','2026-09-17 05:58:25');
/*!40000 ALTER TABLE `commission_rule_versions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `commission_rules`
--

DROP TABLE IF EXISTS `commission_rules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `commission_rules` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `default_percent` decimal(8,3) NOT NULL,
  `qualification_type` varchar(255) DEFAULT NULL,
  `conditions` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`conditions`)),
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `commission_rules_code_unique` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `commission_rules`
--

LOCK TABLES `commission_rules` WRITE;
/*!40000 ALTER TABLE `commission_rules` DISABLE KEYS */;
INSERT INTO `commission_rules` VALUES (1,'senior_manager','مدیر ارشد',4.000,NULL,'{\"qualified_percent\":\"4.000\"}',1,'2026-09-17 05:58:25','2026-09-17 05:58:25'),(2,'development_manager','مدیر توسعه',4.500,'monthly_gateways','{\"qualified_percent\":\"6.000\"}',1,'2026-09-17 05:58:25','2026-09-17 05:58:25'),(3,'sales_manager','مدیر فروش',6.000,'monthly_gateways','{\"qualified_percent\":\"8.000\"}',1,'2026-09-17 05:58:25','2026-09-17 05:58:25'),(4,'representative_referrer','نماینده معرف',2.000,NULL,'{\"qualified_percent\":\"2.000\"}',1,'2026-09-17 05:58:25','2026-09-17 05:58:25'),(5,'representative','نماینده',15.000,'monthly_points','{\"qualified_percent\":\"20.000\"}',1,'2026-09-17 05:58:25','2026-09-17 05:58:25');
/*!40000 ALTER TABLE `commission_rules` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `commissions`
--

DROP TABLE IF EXISTS `commissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `commissions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `role_id` bigint(20) unsigned NOT NULL,
  `gateway_sale_id` bigint(20) unsigned NOT NULL,
  `finopal_transaction_id` bigint(20) unsigned DEFAULT NULL,
  `rule_version_id` bigint(20) unsigned DEFAULT NULL,
  `base_amount` decimal(18,2) NOT NULL,
  `commission_percent` decimal(8,3) NOT NULL,
  `commission_amount` decimal(18,3) NOT NULL,
  `status` varchar(255) NOT NULL DEFAULT 'posted',
  `idempotency_key` varchar(255) NOT NULL,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `commissions_idempotency_key_unique` (`idempotency_key`),
  KEY `commissions_role_id_foreign` (`role_id`),
  KEY `commissions_gateway_sale_id_foreign` (`gateway_sale_id`),
  KEY `commissions_rule_version_id_foreign` (`rule_version_id`),
  KEY `commissions_user_id_role_id_created_at_index` (`user_id`,`role_id`,`created_at`),
  KEY `commissions_finopal_transaction_id_foreign` (`finopal_transaction_id`),
  CONSTRAINT `commissions_finopal_transaction_id_foreign` FOREIGN KEY (`finopal_transaction_id`) REFERENCES `finopal_transactions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `commissions_gateway_sale_id_foreign` FOREIGN KEY (`gateway_sale_id`) REFERENCES `gateway_sales` (`id`) ON DELETE CASCADE,
  CONSTRAINT `commissions_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `commissions_rule_version_id_foreign` FOREIGN KEY (`rule_version_id`) REFERENCES `commission_rule_versions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `commissions_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `commissions`
--

LOCK TABLES `commissions` WRITE;
/*!40000 ALTER TABLE `commissions` DISABLE KEYS */;
INSERT INTO `commissions` VALUES (1,6,5,1,1,5,1000000.00,15.000,150000.000,'posted','commission:tx:1:6:5','{\"share_percent\":\"100.000\",\"sales_points\":\"100.000\",\"finopal_transaction_id\":1,\"qualified_percent\":\"15.000\"}','2026-09-17 05:58:38','2026-09-17 05:58:38'),(2,5,4,1,1,4,1000000.00,2.000,20000.000,'posted','commission:tx:1:5:4','{\"share_percent\":\"100.000\",\"finopal_transaction_id\":1,\"qualified_percent\":\"2.000\"}','2026-09-17 05:58:38','2026-09-17 05:58:38'),(3,2,1,1,1,1,1000000.00,4.000,40000.000,'posted','commission:tx:1:2:1','{\"manager_role\":\"senior_manager\",\"finopal_transaction_id\":1,\"qualified_percent\":\"4.000\"}','2026-09-17 05:58:38','2026-09-17 05:58:38'),(4,3,2,1,1,2,1000000.00,4.500,45000.000,'posted','commission:tx:1:3:2','{\"manager_role\":\"development_manager\",\"finopal_transaction_id\":1,\"qualified_percent\":\"4.500\"}','2026-09-17 05:58:38','2026-09-17 05:58:38'),(5,4,3,1,1,3,1000000.00,6.000,60000.000,'posted','commission:tx:1:4:3','{\"manager_role\":\"sales_manager\",\"finopal_transaction_id\":1,\"qualified_percent\":\"6.000\"}','2026-09-17 05:58:39','2026-09-17 05:58:39'),(6,9,5,2,2,5,2000000.00,7.500,150000.000,'posted','commission:tx:2:9:5','{\"share_percent\":\"50.000\",\"sales_points\":\"50.000\",\"finopal_transaction_id\":2,\"qualified_percent\":\"7.500\"}','2026-09-17 05:58:42','2026-09-17 05:58:42'),(7,10,5,2,2,5,2000000.00,7.500,150000.000,'posted','commission:tx:2:10:5','{\"share_percent\":\"50.000\",\"sales_points\":\"50.000\",\"finopal_transaction_id\":2,\"qualified_percent\":\"7.500\"}','2026-09-17 05:58:42','2026-09-17 05:58:42'),(8,5,4,2,2,4,2000000.00,2.000,40000.000,'posted','commission:tx:2:5:4','{\"share_percent\":\"100.000\",\"finopal_transaction_id\":2,\"qualified_percent\":\"2.000\"}','2026-09-17 05:58:42','2026-09-17 05:58:42'),(9,2,1,2,2,1,2000000.00,4.000,80000.000,'posted','commission:tx:2:2:1','{\"manager_role\":\"senior_manager\",\"finopal_transaction_id\":2,\"qualified_percent\":\"4.000\"}','2026-09-17 05:58:42','2026-09-17 05:58:42'),(10,3,2,2,2,2,2000000.00,4.500,90000.000,'posted','commission:tx:2:3:2','{\"manager_role\":\"development_manager\",\"finopal_transaction_id\":2,\"qualified_percent\":\"4.500\"}','2026-09-17 05:58:42','2026-09-17 05:58:42'),(11,4,3,2,2,3,2000000.00,6.000,120000.000,'posted','commission:tx:2:4:3','{\"manager_role\":\"sales_manager\",\"finopal_transaction_id\":2,\"qualified_percent\":\"6.000\"}','2026-09-17 05:58:42','2026-09-17 05:58:42'),(12,8,5,3,3,5,1800000.00,15.000,270000.000,'posted','commission:tx:3:8:5','{\"share_percent\":\"100.000\",\"sales_points\":\"100.000\",\"finopal_transaction_id\":3,\"qualified_percent\":\"15.000\"}','2026-09-17 05:58:42','2026-09-17 05:58:42'),(13,2,1,3,3,1,1800000.00,4.000,72000.000,'posted','commission:tx:3:2:1','{\"manager_role\":\"senior_manager\",\"finopal_transaction_id\":3,\"qualified_percent\":\"4.000\"}','2026-09-17 05:58:42','2026-09-17 05:58:42'),(14,2,2,3,3,2,1800000.00,4.500,81000.000,'posted','commission:tx:3:2:2','{\"manager_role\":\"development_manager\",\"finopal_transaction_id\":3,\"qualified_percent\":\"4.500\"}','2026-09-17 05:58:42','2026-09-17 05:58:42'),(15,2,3,3,3,3,1800000.00,6.000,108000.000,'posted','commission:tx:3:2:3','{\"manager_role\":\"sales_manager\",\"finopal_transaction_id\":3,\"qualified_percent\":\"6.000\"}','2026-09-17 05:58:42','2026-09-17 05:58:42');
/*!40000 ALTER TABLE `commissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `conversation_authorizations`
--

DROP TABLE IF EXISTS `conversation_authorizations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `conversation_authorizations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `conversation_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `authorization_type` varchar(255) NOT NULL DEFAULT 'tree',
  `allowed` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `conversation_authorizations_conversation_id_foreign` (`conversation_id`),
  KEY `conversation_authorizations_user_id_foreign` (`user_id`),
  CONSTRAINT `conversation_authorizations_conversation_id_foreign` FOREIGN KEY (`conversation_id`) REFERENCES `conversations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `conversation_authorizations_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `conversation_authorizations`
--

LOCK TABLES `conversation_authorizations` WRITE;
/*!40000 ALTER TABLE `conversation_authorizations` DISABLE KEYS */;
/*!40000 ALTER TABLE `conversation_authorizations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `conversation_participants`
--

DROP TABLE IF EXISTS `conversation_participants`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `conversation_participants` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `conversation_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `joined_at` timestamp NULL DEFAULT NULL,
  `left_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `conversation_participants_conversation_id_user_id_unique` (`conversation_id`,`user_id`),
  KEY `conversation_participants_user_id_foreign` (`user_id`),
  CONSTRAINT `conversation_participants_conversation_id_foreign` FOREIGN KEY (`conversation_id`) REFERENCES `conversations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `conversation_participants_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `conversation_participants`
--

LOCK TABLES `conversation_participants` WRITE;
/*!40000 ALTER TABLE `conversation_participants` DISABLE KEYS */;
/*!40000 ALTER TABLE `conversation_participants` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `conversations`
--

DROP TABLE IF EXISTS `conversations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `conversations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `type` varchar(255) NOT NULL DEFAULT 'direct',
  `title` varchar(255) DEFAULT NULL,
  `created_by` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `conversations_created_by_foreign` (`created_by`),
  CONSTRAINT `conversations_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `conversations`
--

LOCK TABLES `conversations` WRITE;
/*!40000 ALTER TABLE `conversations` DISABLE KEYS */;
/*!40000 ALTER TABLE `conversations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `course_levels`
--

DROP TABLE IF EXISTS `course_levels`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `course_levels` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `course_id` bigint(20) unsigned NOT NULL,
  `title` varchar(255) NOT NULL,
  `sort_order` int(10) unsigned NOT NULL DEFAULT 1,
  `passing_score` decimal(8,2) NOT NULL DEFAULT 70.00,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `content_type` varchar(255) NOT NULL DEFAULT 'text',
  `content_body` text DEFAULT NULL,
  `content_url` varchar(255) DEFAULT NULL,
  `attachment_path` varchar(255) DEFAULT NULL,
  `attachment_name` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `course_levels_course_id_foreign` (`course_id`),
  CONSTRAINT `course_levels_course_id_foreign` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `course_levels`
--

LOCK TABLES `course_levels` WRITE;
/*!40000 ALTER TABLE `course_levels` DISABLE KEYS */;
INSERT INTO `course_levels` VALUES (1,1,'آشنایی با محصول',1,70.00,1,'2026-09-17 05:58:30','2026-09-17 05:58:30','text','متن آموزشی نمونه:\nسازمان فروش فاینوپال چگونه کار می‌کند.',NULL,NULL,NULL),(2,1,'مهارت فروش',2,75.00,1,'2026-09-17 05:58:30','2026-09-17 05:58:30','video','ویدیوی نمونه مهارت فروش.','https://www.youtube.com/embed/dQw4w9WgXcQ',NULL,NULL);
/*!40000 ALTER TABLE `course_levels` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `course_role_targets`
--

DROP TABLE IF EXISTS `course_role_targets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `course_role_targets` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `course_id` bigint(20) unsigned NOT NULL,
  `role_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `course_role_targets_course_id_role_id_unique` (`course_id`,`role_id`),
  KEY `course_role_targets_role_id_foreign` (`role_id`),
  CONSTRAINT `course_role_targets_course_id_foreign` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `course_role_targets_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `course_role_targets`
--

LOCK TABLES `course_role_targets` WRITE;
/*!40000 ALTER TABLE `course_role_targets` DISABLE KEYS */;
INSERT INTO `course_role_targets` VALUES (1,1,1),(2,1,2),(3,1,3),(4,1,4),(5,1,5);
/*!40000 ALTER TABLE `course_role_targets` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `courses`
--

DROP TABLE IF EXISTS `courses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `courses` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `is_required_for_promotion` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `courses`
--

LOCK TABLES `courses` WRITE;
/*!40000 ALTER TABLE `courses` DISABLE KEYS */;
INSERT INTO `courses` VALUES (1,'آموزش سازمان فروش','دوره پایه نمایندگان و مدیران',1,1,'2026-09-17 05:58:30','2026-09-17 05:58:30');
/*!40000 ALTER TABLE `courses` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `customers`
--

DROP TABLE IF EXISTS `customers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `customers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `mobile` varchar(255) DEFAULT NULL,
  `person_type` varchar(255) NOT NULL DEFAULT 'individual',
  `national_id` varchar(20) DEFAULT NULL,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `father_name` varchar(255) DEFAULT NULL,
  `birth_date` date DEFAULT NULL,
  `birth_certificate_no` varchar(255) DEFAULT NULL,
  `birth_place` varchar(255) DEFAULT NULL,
  `gender` varchar(16) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `province` varchar(255) DEFAULT NULL,
  `city` varchar(255) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `postal_code` varchar(20) DEFAULT NULL,
  `sheba` varchar(34) DEFAULT NULL,
  `bank_name` varchar(255) DEFAULT NULL,
  `account_number` varchar(255) DEFAULT NULL,
  `account_holder` varchar(255) DEFAULT NULL,
  `shop_name` varchar(255) DEFAULT NULL,
  `shop_category` varchar(255) DEFAULT NULL,
  `website` varchar(255) DEFAULT NULL,
  `company_name` varchar(255) DEFAULT NULL,
  `registration_no` varchar(255) DEFAULT NULL,
  `economic_code` varchar(255) DEFAULT NULL,
  `legal_national_id` varchar(255) DEFAULT NULL,
  `documents` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`documents`)),
  PRIMARY KEY (`id`),
  KEY `customers_mobile_index` (`mobile`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `customers`
--

LOCK TABLES `customers` WRITE;
/*!40000 ALTER TABLE `customers` DISABLE KEYS */;
INSERT INTO `customers` VALUES (1,'مشتری یک','09121230001','individual',NULL,NULL,'2026-09-17 05:58:30','2026-09-17 05:58:30',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL),(2,'مشتری اشتراکی','09121230002','individual',NULL,NULL,'2026-09-17 05:58:36','2026-09-17 05:58:36',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL),(3,'مشتری انتقال مزایا','09121230077','individual',NULL,NULL,'2026-09-17 05:58:38','2026-09-17 05:58:38',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL),(4,'فروشگاه نمونه انتقال','09121230078','individual',NULL,NULL,'2026-09-17 05:58:44','2026-09-17 05:58:44',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL),(5,'مشتری خدماتی انتقال','09121230079','individual',NULL,NULL,'2026-09-17 05:58:44','2026-09-17 05:58:44',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL),(6,'مشتری بازرسی','09121230091','individual','0012345691',NULL,'2026-09-17 05:58:44','2026-09-17 05:58:44',NULL,NULL,NULL,'قزوین — قزوین',NULL,NULL,'قزوین','قزوین',NULL,NULL,'IR120170000000123456789091',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL),(7,'مشتری شاپرک','09121230092','individual','0012345692',NULL,'2026-09-17 05:58:44','2026-09-17 05:58:44',NULL,NULL,NULL,NULL,NULL,NULL,'تهران','تهران',NULL,NULL,'IR120170000000123456789092',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL);
/*!40000 ALTER TABLE `customers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `failed_jobs`
--

DROP TABLE IF EXISTS `failed_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `failed_jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(255) NOT NULL,
  `connection` text NOT NULL,
  `queue` text NOT NULL,
  `payload` longtext NOT NULL,
  `exception` longtext NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `failed_jobs`
--

LOCK TABLES `failed_jobs` WRITE;
/*!40000 ALTER TABLE `failed_jobs` DISABLE KEYS */;
/*!40000 ALTER TABLE `failed_jobs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `finopal_transactions`
--

DROP TABLE IF EXISTS `finopal_transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `finopal_transactions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `gateway_id` bigint(20) unsigned NOT NULL,
  `gateway_sale_id` bigint(20) unsigned DEFAULT NULL,
  `merchant_code` varchar(255) NOT NULL,
  `event` varchar(255) NOT NULL DEFAULT 'transaction.verified',
  `authority` varchar(255) DEFAULT NULL,
  `ref_id` varchar(255) DEFAULT NULL,
  `order_id` varchar(255) DEFAULT NULL,
  `amount` decimal(18,3) NOT NULL,
  `profit` decimal(18,3) NOT NULL,
  `currency` varchar(8) NOT NULL DEFAULT 'IRT',
  `status` varchar(255) NOT NULL DEFAULT 'verified',
  `code` int(10) unsigned DEFAULT NULL,
  `paid_at` timestamp NULL DEFAULT NULL,
  `idempotency_key` varchar(255) NOT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`payload`)),
  `processed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `finopal_transactions_idempotency_key_unique` (`idempotency_key`),
  KEY `finopal_transactions_gateway_id_foreign` (`gateway_id`),
  KEY `finopal_transactions_gateway_sale_id_foreign` (`gateway_sale_id`),
  KEY `finopal_transactions_merchant_code_paid_at_index` (`merchant_code`,`paid_at`),
  KEY `finopal_transactions_authority_index` (`authority`),
  CONSTRAINT `finopal_transactions_gateway_id_foreign` FOREIGN KEY (`gateway_id`) REFERENCES `gateways` (`id`) ON DELETE CASCADE,
  CONSTRAINT `finopal_transactions_gateway_sale_id_foreign` FOREIGN KEY (`gateway_sale_id`) REFERENCES `gateway_sales` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `finopal_transactions`
--

LOCK TABLES `finopal_transactions` WRITE;
/*!40000 ALTER TABLE `finopal_transactions` DISABLE KEYS */;
INSERT INTO `finopal_transactions` VALUES (1,1,1,'fino-seed-solo-0001','transaction.verified','FP_SEED_SOLO_1',NULL,NULL,1000000.000,1000000.000,'IRT','verified',100,'2026-09-17 05:58:38','finopal-fino-seed-solo-0001-FP_SEED_SOLO_1','{\"event\":\"transaction.verified\",\"merchant_id\":\"fino-seed-solo-0001\",\"authority\":\"FP_SEED_SOLO_1\",\"amount\":1000000,\"profit\":1000000,\"currency\":\"IRT\",\"status\":\"verified\",\"code\":100}','2026-09-17 05:58:39','2026-09-17 05:58:38','2026-09-17 05:58:39'),(2,2,2,'fino-seed-share-0001','transaction.verified','FP_SEED_SHARE_1',NULL,NULL,2000000.000,2000000.000,'IRT','verified',100,'2026-09-17 05:58:39','finopal-fino-seed-share-0001-FP_SEED_SHARE_1','{\"event\":\"transaction.verified\",\"merchant_id\":\"fino-seed-share-0001\",\"authority\":\"FP_SEED_SHARE_1\",\"amount\":2000000,\"profit\":2000000,\"currency\":\"IRT\",\"status\":\"verified\",\"code\":100}','2026-09-17 05:58:42','2026-09-17 05:58:39','2026-09-17 05:58:42'),(3,3,3,'fino-seed-multib-0001','transaction.verified','FP_SEED_MULTIB_1',NULL,NULL,1800000.000,1800000.000,'IRT','verified',100,'2026-09-17 05:58:42','finopal-fino-seed-multib-0001-FP_SEED_MULTIB_1','{\"event\":\"transaction.verified\",\"merchant_id\":\"fino-seed-multib-0001\",\"authority\":\"FP_SEED_MULTIB_1\",\"amount\":1800000,\"profit\":1800000,\"currency\":\"IRT\",\"status\":\"verified\",\"code\":100}','2026-09-17 05:58:42','2026-09-17 05:58:42','2026-09-17 05:58:42');
/*!40000 ALTER TABLE `finopal_transactions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `frasoft_mappings`
--

DROP TABLE IF EXISTS `frasoft_mappings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `frasoft_mappings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `entity_type` varchar(255) NOT NULL,
  `internal_id` bigint(20) unsigned NOT NULL,
  `external_id` varchar(255) NOT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`payload`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `frasoft_mappings_entity_type_external_id_unique` (`entity_type`,`external_id`),
  KEY `frasoft_mappings_entity_type_internal_id_index` (`entity_type`,`internal_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `frasoft_mappings`
--

LOCK TABLES `frasoft_mappings` WRITE;
/*!40000 ALTER TABLE `frasoft_mappings` DISABLE KEYS */;
/*!40000 ALTER TABLE `frasoft_mappings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `frasoft_sync_logs`
--

DROP TABLE IF EXISTS `frasoft_sync_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `frasoft_sync_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `direction` varchar(255) NOT NULL,
  `event_type` varchar(255) NOT NULL,
  `idempotency_key` varchar(255) NOT NULL,
  `status` varchar(255) NOT NULL,
  `attempts` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`payload`)),
  `error` text DEFAULT NULL,
  `processed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `frasoft_sync_logs_idempotency_key_unique` (`idempotency_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `frasoft_sync_logs`
--

LOCK TABLES `frasoft_sync_logs` WRITE;
/*!40000 ALTER TABLE `frasoft_sync_logs` DISABLE KEYS */;
/*!40000 ALTER TABLE `frasoft_sync_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `gateway_managers`
--

DROP TABLE IF EXISTS `gateway_managers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `gateway_managers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `gateway_sale_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `role_id` bigint(20) unsigned NOT NULL,
  `commission_percent` decimal(8,3) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `gateway_managers_gateway_sale_id_user_id_role_id_unique` (`gateway_sale_id`,`user_id`,`role_id`),
  KEY `gateway_managers_user_id_foreign` (`user_id`),
  KEY `gateway_managers_role_id_foreign` (`role_id`),
  CONSTRAINT `gateway_managers_gateway_sale_id_foreign` FOREIGN KEY (`gateway_sale_id`) REFERENCES `gateway_sales` (`id`) ON DELETE CASCADE,
  CONSTRAINT `gateway_managers_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `gateway_managers_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=22 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `gateway_managers`
--

LOCK TABLES `gateway_managers` WRITE;
/*!40000 ALTER TABLE `gateway_managers` DISABLE KEYS */;
INSERT INTO `gateway_managers` VALUES (1,1,4,3,0.000,'2026-09-17 05:58:30','2026-09-17 05:58:30'),(2,1,3,2,0.000,'2026-09-17 05:58:30','2026-09-17 05:58:30'),(3,1,2,1,0.000,'2026-09-17 05:58:30','2026-09-17 05:58:30'),(4,2,4,3,0.000,'2026-09-17 05:58:38','2026-09-17 05:58:38'),(5,2,3,2,0.000,'2026-09-17 05:58:38','2026-09-17 05:58:38'),(6,2,2,1,0.000,'2026-09-17 05:58:38','2026-09-17 05:58:38'),(7,3,2,3,0.000,'2026-09-17 05:58:38','2026-09-17 05:58:38'),(8,3,2,2,0.000,'2026-09-17 05:58:38','2026-09-17 05:58:38'),(9,3,2,1,0.000,'2026-09-17 05:58:38','2026-09-17 05:58:38'),(10,4,2,3,0.000,'2026-09-17 05:58:44','2026-09-17 05:58:44'),(11,4,2,2,0.000,'2026-09-17 05:58:44','2026-09-17 05:58:44'),(12,4,2,1,0.000,'2026-09-17 05:58:44','2026-09-17 05:58:44'),(13,5,2,3,0.000,'2026-09-17 05:58:44','2026-09-17 05:58:44'),(14,5,2,2,0.000,'2026-09-17 05:58:44','2026-09-17 05:58:44'),(15,5,2,1,0.000,'2026-09-17 05:58:44','2026-09-17 05:58:44'),(16,6,4,3,0.000,'2026-09-17 05:58:44','2026-09-17 05:58:44'),(17,6,3,2,0.000,'2026-09-17 05:58:44','2026-09-17 05:58:44'),(18,6,2,1,0.000,'2026-09-17 05:58:44','2026-09-17 05:58:44'),(19,7,4,3,0.000,'2026-09-17 05:58:44','2026-09-17 05:58:44'),(20,7,3,2,0.000,'2026-09-17 05:58:44','2026-09-17 05:58:44'),(21,7,2,1,0.000,'2026-09-17 05:58:44','2026-09-17 05:58:44');
/*!40000 ALTER TABLE `gateway_managers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `gateway_referrers`
--

DROP TABLE IF EXISTS `gateway_referrers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `gateway_referrers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `gateway_sale_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `share_percent` decimal(8,3) NOT NULL,
  `commission_percent` decimal(8,3) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `gateway_referrers_gateway_sale_id_user_id_unique` (`gateway_sale_id`,`user_id`),
  KEY `gateway_referrers_user_id_foreign` (`user_id`),
  CONSTRAINT `gateway_referrers_gateway_sale_id_foreign` FOREIGN KEY (`gateway_sale_id`) REFERENCES `gateway_sales` (`id`) ON DELETE CASCADE,
  CONSTRAINT `gateway_referrers_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `gateway_referrers`
--

LOCK TABLES `gateway_referrers` WRITE;
/*!40000 ALTER TABLE `gateway_referrers` DISABLE KEYS */;
INSERT INTO `gateway_referrers` VALUES (1,1,5,100.000,100.000,'2026-09-17 05:58:30','2026-09-17 05:58:30'),(2,2,5,100.000,100.000,'2026-09-17 05:58:37','2026-09-17 05:58:37'),(3,6,5,100.000,100.000,'2026-09-17 05:58:44','2026-09-17 05:58:44'),(4,7,5,100.000,100.000,'2026-09-17 05:58:44','2026-09-17 05:58:44');
/*!40000 ALTER TABLE `gateway_referrers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `gateway_representatives`
--

DROP TABLE IF EXISTS `gateway_representatives`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `gateway_representatives` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `gateway_sale_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `share_percent` decimal(8,3) NOT NULL,
  `sales_points` decimal(10,3) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `gateway_representatives_gateway_sale_id_user_id_unique` (`gateway_sale_id`,`user_id`),
  KEY `gateway_representatives_user_id_foreign` (`user_id`),
  CONSTRAINT `gateway_representatives_gateway_sale_id_foreign` FOREIGN KEY (`gateway_sale_id`) REFERENCES `gateway_sales` (`id`) ON DELETE CASCADE,
  CONSTRAINT `gateway_representatives_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `gateway_representatives`
--

LOCK TABLES `gateway_representatives` WRITE;
/*!40000 ALTER TABLE `gateway_representatives` DISABLE KEYS */;
INSERT INTO `gateway_representatives` VALUES (1,1,6,100.000,100.000,'2026-09-17 05:58:30','2026-09-17 05:58:30'),(2,2,9,50.000,50.000,'2026-09-17 05:58:37','2026-09-17 05:58:37'),(3,2,10,50.000,50.000,'2026-09-17 05:58:37','2026-09-17 05:58:37'),(4,3,8,100.000,100.000,'2026-09-17 05:58:38','2026-09-17 05:58:38'),(5,4,8,100.000,100.000,'2026-09-17 05:58:44','2026-09-17 05:58:44'),(6,5,8,100.000,100.000,'2026-09-17 05:58:44','2026-09-17 05:58:44'),(7,6,6,100.000,100.000,'2026-09-17 05:58:44','2026-09-17 05:58:44'),(8,7,6,100.000,100.000,'2026-09-17 05:58:44','2026-09-17 05:58:44');
/*!40000 ALTER TABLE `gateway_representatives` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `gateway_sale_reviews`
--

DROP TABLE IF EXISTS `gateway_sale_reviews`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `gateway_sale_reviews` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `gateway_sale_id` bigint(20) unsigned NOT NULL,
  `actor_id` bigint(20) unsigned DEFAULT NULL,
  `stage` varchar(255) NOT NULL,
  `decision` varchar(255) NOT NULL,
  `note` text DEFAULT NULL,
  `reference` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `gateway_sale_reviews_actor_id_foreign` (`actor_id`),
  KEY `gateway_sale_reviews_gateway_sale_id_stage_index` (`gateway_sale_id`,`stage`),
  CONSTRAINT `gateway_sale_reviews_actor_id_foreign` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `gateway_sale_reviews_gateway_sale_id_foreign` FOREIGN KEY (`gateway_sale_id`) REFERENCES `gateway_sales` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `gateway_sale_reviews`
--

LOCK TABLES `gateway_sale_reviews` WRITE;
/*!40000 ALTER TABLE `gateway_sale_reviews` DISABLE KEYS */;
INSERT INTO `gateway_sale_reviews` VALUES (1,1,NULL,'submitted','submitted',NULL,NULL,'2026-09-17 05:58:30','2026-09-17 05:58:30'),(2,2,NULL,'submitted','submitted',NULL,NULL,'2026-09-17 05:58:38','2026-09-17 05:58:38'),(3,3,NULL,'submitted','submitted',NULL,NULL,'2026-09-17 05:58:38','2026-09-17 05:58:38'),(4,4,NULL,'submitted','submitted',NULL,NULL,'2026-09-17 05:58:44','2026-09-17 05:58:44'),(5,5,NULL,'submitted','submitted',NULL,NULL,'2026-09-17 05:58:44','2026-09-17 05:58:44'),(6,6,NULL,'submitted','submitted',NULL,NULL,'2026-09-17 05:58:44','2026-09-17 05:58:44'),(7,7,NULL,'submitted','submitted',NULL,NULL,'2026-09-17 05:58:45','2026-09-17 05:58:45');
/*!40000 ALTER TABLE `gateway_sale_reviews` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `gateway_sales`
--

DROP TABLE IF EXISTS `gateway_sales`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `gateway_sales` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `gateway_id` bigint(20) unsigned NOT NULL,
  `customer_id` bigint(20) unsigned DEFAULT NULL,
  `shared_link_id` bigint(20) unsigned DEFAULT NULL,
  `amount` decimal(18,2) NOT NULL,
  `full_sales_points` int(10) unsigned NOT NULL DEFAULT 100,
  `status` varchar(255) NOT NULL DEFAULT 'successful',
  `sold_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `idempotency_key` varchar(255) DEFAULT NULL,
  `shaparak_reference` varchar(255) DEFAULT NULL,
  `inspected_at` timestamp NULL DEFAULT NULL,
  `inspected_by` bigint(20) unsigned DEFAULT NULL,
  `shaparak_at` timestamp NULL DEFAULT NULL,
  `shaparak_by` bigint(20) unsigned DEFAULT NULL,
  `rejected_at` timestamp NULL DEFAULT NULL,
  `rejected_by` bigint(20) unsigned DEFAULT NULL,
  `rejection_note` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `gateway_sales_idempotency_key_unique` (`idempotency_key`),
  KEY `gateway_sales_gateway_id_foreign` (`gateway_id`),
  KEY `gateway_sales_customer_id_foreign` (`customer_id`),
  KEY `gateway_sales_shared_link_id_foreign` (`shared_link_id`),
  KEY `gateway_sales_status_sold_at_index` (`status`,`sold_at`),
  KEY `gateway_sales_inspected_by_foreign` (`inspected_by`),
  KEY `gateway_sales_shaparak_by_foreign` (`shaparak_by`),
  KEY `gateway_sales_rejected_by_foreign` (`rejected_by`),
  CONSTRAINT `gateway_sales_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `gateway_sales_gateway_id_foreign` FOREIGN KEY (`gateway_id`) REFERENCES `gateways` (`id`) ON DELETE CASCADE,
  CONSTRAINT `gateway_sales_inspected_by_foreign` FOREIGN KEY (`inspected_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `gateway_sales_rejected_by_foreign` FOREIGN KEY (`rejected_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `gateway_sales_shaparak_by_foreign` FOREIGN KEY (`shaparak_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `gateway_sales_shared_link_id_foreign` FOREIGN KEY (`shared_link_id`) REFERENCES `shared_links` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `gateway_sales`
--

LOCK TABLES `gateway_sales` WRITE;
/*!40000 ALTER TABLE `gateway_sales` DISABLE KEYS */;
INSERT INTO `gateway_sales` VALUES (1,1,1,NULL,1000000.00,100,'successful','2026-09-17 05:58:30','seed-solo-1',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-09-17 05:58:30','2026-09-17 05:58:30'),(2,2,2,1,2000000.00,100,'successful','2026-09-17 05:58:36','seed-share-1',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-09-17 05:58:36','2026-09-17 05:58:36'),(3,3,3,NULL,1800000.00,100,'successful','2026-09-17 05:58:38','demo-multi-b-gateway-1',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-09-17 05:58:38','2026-09-17 05:58:38'),(4,4,4,NULL,2450000.00,100,'successful','2026-09-17 05:58:44','demo-multi-b-gateway-2',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-09-17 05:58:44','2026-09-17 05:58:44'),(5,5,5,NULL,980000.00,100,'successful','2026-09-17 05:58:44','demo-multi-b-gateway-3',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-09-17 05:58:44','2026-09-17 05:58:44'),(6,6,6,NULL,1750000.00,100,'pending_inspection','2026-09-17 05:58:44','demo-wait-inspect',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-09-17 05:58:44','2026-09-17 05:58:44'),(7,7,7,NULL,2100000.00,100,'pending_inspection','2026-09-17 05:58:44','demo-wait-shaparak',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-09-17 05:58:44','2026-09-17 05:58:44');
/*!40000 ALTER TABLE `gateway_sales` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `gateways`
--

DROP TABLE IF EXISTS `gateways`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `gateways` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `external_id` varchar(255) NOT NULL,
  `merchant_code` varchar(255) DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `source` varchar(255) NOT NULL DEFAULT 'finopal',
  `sale_amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `gateways_external_id_unique` (`external_id`),
  UNIQUE KEY `gateways_merchant_code_unique` (`merchant_code`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `gateways`
--

LOCK TABLES `gateways` WRITE;
/*!40000 ALTER TABLE `gateways` DISABLE KEYS */;
INSERT INTO `gateways` VALUES (1,'GW-SOLO-1','fino-seed-solo-0001','درگاه انفرادی نماینده','finopal',1000000.00,1,'{\"ownership_type\":\"solo\"}','2026-09-17 05:58:30','2026-09-17 05:58:30'),(2,'GW-SHARE-1','fino-seed-share-0001','درگاه اشتراکی ۵۰-۵۰','finopal',2000000.00,1,'{\"ownership_type\":\"solo\"}','2026-09-17 05:58:36','2026-09-17 05:58:36'),(3,'GW-MULTI-B-1','fino-seed-multib-0001','درگاه کاربر چندنقشی ب','finopal',1800000.00,1,'{\"ownership_type\":\"solo\"}','2026-09-17 05:58:38','2026-09-17 05:58:38'),(4,'GW-MULTI-B-2',NULL,'درگاه فروشگاهی ب','finopal',2450000.00,1,'{\"ownership_type\":\"solo\"}','2026-09-17 05:58:44','2026-09-17 05:58:44'),(5,'GW-MULTI-B-3',NULL,'درگاه خدماتی ب','finopal',980000.00,1,'{\"ownership_type\":\"solo\"}','2026-09-17 05:58:44','2026-09-17 05:58:44'),(6,'GW-WAIT-INSPECT',NULL,'درگاه در انتظار بازرسی','finopal',1750000.00,1,'{\"ownership_type\":\"solo\"}','2026-09-17 05:58:44','2026-09-17 05:58:44'),(7,'GW-WAIT-SHAPARAK',NULL,'درگاه دوم در انتظار تایید','finopal',2100000.00,1,'{\"ownership_type\":\"solo\"}','2026-09-17 05:58:44','2026-09-17 05:58:44');
/*!40000 ALTER TABLE `gateways` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `geo_cities`
--

DROP TABLE IF EXISTS `geo_cities`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `geo_cities` (
  `id` bigint(20) unsigned NOT NULL,
  `state_id` bigint(20) unsigned NOT NULL,
  `code` int(10) unsigned DEFAULT NULL,
  `slug` varchar(40) DEFAULT NULL,
  `title` varchar(80) NOT NULL,
  `sub_title` varchar(80) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `geo_cities_state_id_index` (`state_id`),
  KEY `geo_cities_title_index` (`title`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `geo_cities`
--

LOCK TABLES `geo_cities` WRITE;
/*!40000 ALTER TABLE `geo_cities` DISABLE KEYS */;
INSERT INTO `geo_cities` VALUES (3957,1,101001,'Oskoo','اسکو','اسکو','2026-09-17 05:58:45','2026-09-17 05:58:45'),(3958,1,101002,'Ilikhchi','ایلخچی','اسکو','2026-09-17 05:58:45','2026-09-17 05:58:45'),(3959,1,101003,'Sahand','سهند','اسکو','2026-09-17 05:58:45','2026-09-17 05:58:45'),(3960,1,101004,'Ahar','اهر','اهر','2026-09-17 05:58:45','2026-09-17 05:58:45'),(3961,1,101005,'Horand','هوراند','اهر','2026-09-17 05:58:45','2026-09-17 05:58:45'),(3962,1,101006,'AzarShahr','آذرشهر','آذرشهر','2026-09-17 05:58:45','2026-09-17 05:58:45'),(3963,1,101007,'Googan','گوگان','آذرشهر','2026-09-17 05:58:45','2026-09-17 05:58:45'),(3964,1,101008,'Mamaghan','ممقان','آذرشهر','2026-09-17 05:58:45','2026-09-17 05:58:45'),(3965,1,101009,'BostanAbad','بستان آباد','بستان آباد','2026-09-17 05:58:45','2026-09-17 05:58:45'),(3966,1,101010,'Tikmehdash','تیکمه داش','بستان آباد','2026-09-17 05:58:45','2026-09-17 05:58:45'),(3967,1,101011,'Bonab','بناب','بناب','2026-09-17 05:58:45','2026-09-17 05:58:45'),(3968,1,101012,'Basmenj','باسمنج','تبریز','2026-09-17 05:58:45','2026-09-17 05:58:45'),(3969,1,101013,'Tabriz','تبریز','تبریز','2026-09-17 05:58:45','2026-09-17 05:58:45'),(3970,1,101014,'KhosroShahr','خسروشهر','تبریز','2026-09-17 05:58:45','2026-09-17 05:58:45'),(3971,1,101015,'Sardrood','سردرود','تبریز','2026-09-17 05:58:45','2026-09-17 05:58:45'),(3972,1,101016,'Jolfa','جلفا','جلفا','2026-09-17 05:58:45','2026-09-17 05:58:45'),(3973,1,101017,'Siahrood','سیه رود','جلفا','2026-09-17 05:58:45','2026-09-17 05:58:45'),(3974,1,101018,'HadiShahr','هادیشهر','جلفا','2026-09-17 05:58:45','2026-09-17 05:58:45'),(3975,1,101019,'Gharehaghaj','قره آغاج','چاراویماق','2026-09-17 05:58:45','2026-09-17 05:58:45'),(3976,1,101020,'Khomarlo','خمارلو','خداآفرین','2026-09-17 05:58:45','2026-09-17 05:58:45'),(3977,1,101021,'Doozdoozan','دوزدوزان','سراب','2026-09-17 05:58:45','2026-09-17 05:58:45'),(3978,1,101022,'Sarab','سراب','سراب','2026-09-17 05:58:45','2026-09-17 05:58:45'),(3979,1,101023,'Sharabian','شربیان','سراب','2026-09-17 05:58:45','2026-09-17 05:58:45'),(3980,1,101024,'Mehraban','مهربان','سراب','2026-09-17 05:58:45','2026-09-17 05:58:45'),(3981,1,101025,'Tasoj','تسوج','شبستر','2026-09-17 05:58:45','2026-09-17 05:58:45'),(3982,1,101026,'Khamene','خامنه','شبستر','2026-09-17 05:58:45','2026-09-17 05:58:45'),(3983,1,101027,'Sis','سیس','شبستر','2026-09-17 05:58:45','2026-09-17 05:58:45'),(3984,1,101028,'Shabestar','شبستر','شبستر','2026-09-17 05:58:45','2026-09-17 05:58:45'),(3985,1,101029,'Sharafkhaneh','شرفخانه','شبستر','2026-09-17 05:58:45','2026-09-17 05:58:45'),(3986,1,101030,'ShendAbad','شندآباد','شبستر','2026-09-17 05:58:45','2026-09-17 05:58:45'),(3987,1,101031,'Soofian','صوفیان','شبستر','2026-09-17 05:58:45','2026-09-17 05:58:45'),(3988,1,101032,'Kuzehkonan','کوزه کنان','شبستر','2026-09-17 05:58:45','2026-09-17 05:58:45'),(3989,1,101033,'Vaighan','وایقان','شبستر','2026-09-17 05:58:45','2026-09-17 05:58:45'),(3990,1,101034,'Ajabshir','عجب شیر','عجب شیر','2026-09-17 05:58:45','2026-09-17 05:58:45'),(3991,1,101035,'AbeshAhmad','آبش احمد','کلیبر','2026-09-17 05:58:45','2026-09-17 05:58:45'),(3992,1,101036,'Kaleibar','کلیبر','کلیبر','2026-09-17 05:58:45','2026-09-17 05:58:45'),(3993,1,101037,'khodajoo','خداجو','مراغه','2026-09-17 05:58:45','2026-09-17 05:58:45'),(3994,1,101038,'Maragheh','مراغه','مراغه','2026-09-17 05:58:45','2026-09-17 05:58:45'),(3995,1,101039,'BonabJadid','بناب جدید','مرند','2026-09-17 05:58:45','2026-09-17 05:58:45'),(3996,1,101040,'Zonooz','زنوز','مرند','2026-09-17 05:58:45','2026-09-17 05:58:45'),(3997,1,101041,'Kashksaray','کشکسرای','مرند','2026-09-17 05:58:45','2026-09-17 05:58:45'),(3998,1,101042,'Marand','مرند','مرند','2026-09-17 05:58:45','2026-09-17 05:58:45'),(3999,1,101043,'Yamchi','یامچی','مرند','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4000,1,101044,'Leylan','لیلان','ملکان','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4001,1,101045,'Malekan','ملکان','ملکان','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4002,1,101046,'Aghkand','آقکند','میانه','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4003,1,101047,'Tork','ترک','میانه','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4004,1,101048,'Torkamanchai','ترکمانچای','میانه','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4005,1,101049,'Mianeh','میانه','میانه','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4006,1,101050,'Bakhshayesh','بخشایش','هریس','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4007,1,101051,'Khaje','خواجه','هریس','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4008,1,101052,'Zarnagh','زرنق','هریس','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4009,1,101053,'Kalvangh','کلوانق','هریس','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4010,1,101054,'Haris','هریس','هریس','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4011,1,101055,'Nazarkahrizi','نظرکهریزی','هشترود','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4012,1,101056,'Hashtrood','هشترود','هشترود','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4013,1,101057,'Kharvana','خاروانا','ورزقان','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4014,1,101058,'Varzaghan','ورزقان','ورزقان','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4015,2,102001,'Urumieh','ارومیه','ارومیه','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4016,2,102002,'Sero','سرو','ارومیه','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4017,2,102003,'Silvaneh','سیلوانه','ارومیه','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4018,2,102004,'Ghooshchi','قوشچی','ارومیه','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4019,2,102005,'Noshin','نوشین','ارومیه','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4020,2,102006,'Oshnavieh','اشنویه','اشنویه','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4021,2,102007,'Naloos','نالوس','اشنویه','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4022,2,102008,'Bookan','بوکان','بوکان','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4023,2,102009,'Simineh','سیمینه','بوکان','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4024,2,102010,'Poldasht','پلدشت','پلدشت','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4025,2,102011,'NazokOlia','نازک علیا','پلدشت','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4026,2,102012,'Piranshahr','پیرانشهر','پیرانشهر','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4027,2,102013,'GerdKashaneh','گردکشانه','پیرانشهر','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4028,2,102014,'Takab','تکاب','تکاب','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4029,2,102015,'Avajigh','آواجیق','چالدران','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4030,2,102016,'Siyahcheshmeh','سیه چشمه','چالدران','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4031,2,102017,'GhareZiaDin','قره ضیاء الدین','چایپاره','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4032,2,102018,'Ivooghli','ایواوغلی','خوی','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4033,2,102019,'Khoy','خوی','خوی','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4034,2,102020,'DizajDiz','دیزج دیز','خوی','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4035,2,102021,'ZarAbad','زرآباد','خوی','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4036,2,102022,'Firooragh','فیرورق','خوی','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4037,2,102023,'Ghotur','قطور','خوی','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4038,2,102024,'Rabt','ربط','سردشت','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4039,2,102025,'Sardasht','سردشت','سردشت','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4040,2,102026,'MirAbad','میرآباد','سردشت','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4041,2,102027,'TazehShahr','تازه شهر','سلماس','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4042,2,102028,'Salmas','سلماس','سلماس','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4043,2,102029,'Shahindezh','شاهین دژ','شاهین دژ','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4044,2,102030,'Keshavarz','کشاورز','شاهین دژ','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4045,2,102031,'MahmoodAbad','محمودآباد','شاهین دژ','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4046,2,102032,'Shoot','شوط','شوط','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4047,2,102033,'Marganlar','مرگنلر','شوط','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4048,2,102034,'Bazargan','بازرگان','ماکو','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4049,2,102035,'Makoo','ماکو','ماکو','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4050,2,102036,'Khalifan','خلیفان','مهاباد','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4051,2,102037,'Mahabad','مهاباد','مهاباد','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4052,2,102038,'Barogh','باروق','میاندوآب','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4053,2,102039,'ChaharBorj','چهاربرج','میاندوآب','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4054,2,102040,'Miandoab','میاندوآب','میاندوآب','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4055,2,102041,'Mohammadyar','محمدیار','نقده','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4056,2,102042,'Naghadeh','نقده','نقده','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4057,3,103001,'Ardebil','اردبیل','اردبیل','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4058,3,103002,'Hir','هیر','اردبیل','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4059,3,103003,'Bilesavar','بیله سوار','بیله سوار','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4060,3,103004,'JafarAbad','جعفرآباد','بیله سوار','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4061,3,103005,'Aslandooz','اصلاندوز','پارس آباد','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4062,3,103006,'ParsAbad','پارس آباد','پارس آباد','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4063,3,103007,'TazeKand','تازه کند','پارس آباد','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4064,3,103008,'Khalkhal','خلخال','خلخال','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4065,3,103009,'Kaloor','کلور','خلخال','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4066,3,103010,'Hashtjin','هشتجین','خلخال','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4067,3,103011,'Sarein','سرعین','سرعین','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4068,3,103012,'Givi','گیوی','کوثر','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4069,3,103013,'TazeKandeAngoot','تازه کندانگوت','گرمی','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4070,3,103014,'Germi','گرمی','گرمی','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4071,3,103015,'Razi','رضی','مشگین شهر','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4072,3,103016,'FakhrAbad','فخرآباد','مشگین شهر','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4073,3,103017,'Lahrood','لاهرود','مشگین شهر','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4074,3,103018,'Moradloo','مرادلو','مشگین شهر','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4075,3,103019,'MeshginShahr','مشگین شهر','مشگین شهر','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4076,3,103020,'AbiBigloo','آبی بیگلو','نمین','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4077,3,103021,'Anbaran','عنبران','نمین','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4078,3,103022,'Namin','نمین','نمین','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4079,3,103023,'Kooraeem','کوراییم','نیر','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4080,3,103024,'Nayer','نیر','نیر','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4081,4,104001,'Ardestan','اردستان','اردستان','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4082,4,104002,'Zavareh','زواره','اردستان','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4083,4,104003,'Mahabad','مهاباد','اردستان','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4084,4,104004,'Azhiyeh','اژیه','اصفهان','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4085,4,104005,'Isfahan','اصفهان','اصفهان','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4086,4,104006,'Baharestan','بهارستان','اصفهان','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4087,4,104007,'Toodeshk','تودشک','اصفهان','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4088,4,104008,'HasanAbad','حسن اباد','اصفهان','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4089,4,104009,'Khoorasgan','خوراسگان','اصفهان','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4090,4,104010,'Segzi','سگزی','اصفهان','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4091,4,104011,'Koohpayeh','کوهپایه','اصفهان','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4092,4,104012,'MohammadAbad','محمدآباد','اصفهان','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4093,4,104013,'NasrAbad','نصرآباد','اصفهان','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4094,4,104014,'NikAbad','نیک آباد','اصفهان','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4095,4,104015,'Harand','هرند','اصفهان','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4096,4,104016,'Varzaneh','ورزنه','اصفهان','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4097,4,104017,'AboozeidAbad','ابوزیدآباد','آران وبیدگل','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4098,4,104018,'AranBidgol','آران وبیدگل','آران وبیدگل','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4099,4,104019,'SefidShahr','سفیدشهر','آران وبیدگل','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4100,4,104020,'NooshAbad','نوش آباد','آران وبیدگل','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4101,4,104021,'HabibAbad','حبیب آباد','برخوار','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4102,4,104022,'Khorzough','خورزوق','برخوار','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4103,4,104023,'Dastgerd','دستگرد','برخوار','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4104,4,104024,'DolatAbad','دولت آباد','برخوار','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4105,4,104025,'ShapoorAbad','شاپورآباد','برخوار','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4106,4,104026,'Komeshcheh','کمشچه','برخوار','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4107,4,104027,'Tiran','تیران','تیران وکرون','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4108,4,104028,'Rezvanshahr','رضوانشهر','تیران وکرون','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4109,4,104029,'Asgaran','عسگران','تیران وکرون','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4110,4,104030,'Chadegan','چادگان','چادگان','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4111,4,104031,'Rozveh','رزوه','چادگان','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4112,4,104032,'KhomeiniShahr','خمینی شهر','خمینی شهر','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4113,4,104033,'Dorche','درچه','خمینی شهر','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4114,4,104034,'Kooshk','کوشک','خمینی شهر','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4115,4,104035,'Khansar','خوانسار','خوانسار','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4116,4,104036,'Jandagh','جندق','خور و بیابانک','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4117,4,104037,'Khoor','خور','خور و بیابانک','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4118,4,104038,'Farkhi','فرخی','خور و بیابانک','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4119,4,104039,'Dehaghan','دهاقان','دهاقان','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4120,4,104040,'Golshan','گلشن','دهاقان','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4121,4,104041,'Hana','حنا','سمیرم','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4122,4,104042,'Samirom','سمیرم','سمیرم','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4123,4,104043,'Kameh','کمه','سمیرم','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4124,4,104044,'Vanak','ونک','سمیرم','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4125,4,104045,'ShahinShahr','شاهین شهر','شاهینشهرومیمه','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4126,4,104046,'Gorgab','گرگاب','شاهینشهرومیمه','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4127,4,104047,'GazBarkhar','گزبرخوار','شاهینشهرومیمه','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4128,4,104048,'Laybid','لای بید','شاهین','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4129,4,104049,'Meimeh','میمه','شاهین','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4130,4,104050,'Vezvan','وزوان','شاهین','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4131,4,104051,'Shahreza','شهرضا','شهرضا','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4132,4,104052,'Manzarieh','منظریه','شهرضا','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4133,4,104053,'Afoos','افوس','فریدن','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4134,4,104054,'BooeenMiandasht','بویین ومیاندشت','فریدن','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4135,4,104055,'Daran','داران','فریدن','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4136,4,104056,'Damane','دامنه','فریدن','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4137,4,104057,'Barfanbar','برف انبار','فریدونشهر','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4138,4,104058,'FereidoonShahr','فریدونشهر','فریدونشهر','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4139,4,104059,'Abrisham','ابریشم','فلاورجان','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4140,4,104060,'ImanShahr','ایمانشهر','فلاورجان','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4141,4,104061,'BaharanShahr','بهاران شهر','فلاورجان','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4142,4,104062,'Pirbakran','پیربکران','فلاورجان','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4143,4,104063,'Falavarjan','فلاورجان','فلاورجان','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4144,4,104064,'Ghahderijan','قهدریجان','فلاورجان','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4145,4,104065,'KelishadSooderjan','کلیشادوسودرجان','فلاورجان','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4146,4,104066,'Barzok','برزک','کاشان','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4147,4,104067,'JosheghanKamo','جوشقان وکامو','کاشان','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4148,4,104068,'Ghamsar','قمصر','کاشان','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4149,4,104069,'Kashan','کاشان','کاشان','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4150,4,104070,'Meshkat','مشکات','کاشان','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4151,4,104071,'Niasar','نیاسر','کاشان','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4152,4,104072,'Golpayegan','گلپایگان','گلپایگان','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4153,4,104073,'Golshahr','گلشهر','گلپایگان','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4154,4,104074,'Gogad','گوگد','گلپایگان','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4155,4,104075,'Baghbahadoran','باغ بهادران','لنجان','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4156,4,104076,'Charmahin','چرمهین','لنجان','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4157,4,104077,'Chamgordan','چمگردان','لنجان','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4158,4,104078,'Zayanderood','زاینده رود','لنجان','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4159,4,104079,'ZarrinShahr','زرین شهر','لنجان','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4160,4,104080,'SedehLenjan','سده لنجان','لنجان','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4161,4,104081,'FooladShahr','فولادشهر','لنجان','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4162,4,104082,'Varnamkhast','ورنامخواست','لنجان','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4163,4,104083,'Dizicheh','دیزیچه','مبارکه','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4164,4,104084,'ZibaShahr','زیباشهر','مبارکه','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4165,4,104085,'Talkhoonche','طالخونچه','مبارکه','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4166,4,104086,'Karkevand','کرکوند','مبارکه','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4167,4,104087,'Mobarakeh','مبارکه','مبارکه','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4168,4,104088,'Majlesi','مجلسی','مبارکه','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4169,4,104089,'Anarak','انارک','نایین','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4170,4,104090,'Bafran','بافران','نایین','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4171,4,104091,'Naeen','نایین','نایین','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4172,4,104092,'Jowzdan','جوزدان','نجف آباد','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4173,4,104093,'Dehagh','دهق','نجف آباد','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4174,4,104094,'Alavijeh','علویجه','نجف آباد','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4175,4,104095,'KahrizSang','کهریزسنگ','نجف آباد','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4176,4,104096,'Goldasht','گلدشت','نجف آباد','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4177,4,104097,'NajafAbad','نجف آباد','نجف آباد','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4178,4,104098,'Badrood','بادرود','نطنز','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4179,4,104099,'KhaledAbad','خالدآباد','نطنز','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4180,4,104100,'Natanz','نطنز','نطنز','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4181,5,105001,'ChaharBagh','چهارباغ','ساوجبلاغ','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4182,5,105002,'ShahrejadidHashtgerd','شهرجدیدهشتگرد','ساوجبلاغ','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4183,5,105003,'Koohsar','کوهسار','ساوجبلاغ','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4184,5,105004,'Golsar','گلسار','ساوجبلاغ','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4185,5,105005,'Hashtgerd','هشتگرد','ساوجبلاغ','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4186,5,105006,'Taleghan','طالقان','طالقان','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4187,5,105007,'Eshtehard','اشتهارد','کرج','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4188,5,105008,'Asara','آسارا','کرج','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4189,5,105009,'Karaj','کرج','کرج','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4190,5,105010,'KamalShahr','کمال شهر','کرج','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4191,5,105011,'Garmdareh','گرمدره','کرج','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4192,5,105012,'Mahdasht','ماهدشت','کرج','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4193,5,105013,'MohammadShahr','محمدشهر','کرج','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4194,5,105014,'Meshkindasht','مشکین دشت','کرج','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4195,5,105015,'Tonkaman','تنکمان','نظرآباد','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4196,5,105016,'NazarAbad','نظرآباد','نظرآباد','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4197,5,105017,'Fardis','فردیس','فردیس','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4198,6,106001,'Ilam','ایلام','ایلام','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4199,6,106002,'Chovar','چوار','ایلام','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4200,6,106003,'Ivan','ایوان','ایوان','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4201,6,106004,'Zarneh','زرنه','ایوان','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4202,6,106005,'Abdanan','آبدانان','آبدانان','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4203,6,106006,'SarabBagh','سراب باغ','آبدانان','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4204,6,106007,'Murmuri','مورموری','آبدانان','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4205,6,106008,'Badreh','بدره','دره شهر','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4206,6,106009,'DarrehShahr','دره شهر','دره شهر','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4207,6,106010,'Pahle','پهله','دهلران','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4208,6,106011,'Dehloran','دهلران','دهلران','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4209,6,106012,'Moosian','موسیان','دهلران','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4210,6,106013,'Meimeh','میمه','دهلران','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4211,6,106014,'AsemanAbad','آسمان آباد','شیروان وچرداول','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4212,6,106015,'Tohid','توحید','شیروان وچرداول','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4213,6,106016,'Sarableh','سرابله','شیروان وچرداول','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4214,6,106017,'Loomar','لومار','شیروان وچرداول','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4215,6,106018,'Arkavaz','ارکواز','ملکشاهی','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4216,6,106019,'Delgosha','دلگشا','ملکشاهی','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4217,6,106020,'SalehAbad','صالح آباد','مهران','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4218,6,106021,'Mehran','مهران','مهران','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4219,7,107001,'Boshehr','بوشهر','بوشهر','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4220,7,107002,'Choghadak','چغادک','بوشهر','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4221,7,107003,'Khark','خارک','بوشهر','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4222,7,107004,'Ahrom','اهرم','تنگستان','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4223,7,107005,'Delvar','دلوار','تنگستان','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4224,7,107006,'Anarestan','انارستان','جم','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4225,7,107007,'Jam','جم','جم','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4226,7,107008,'Riz','ریز','جم','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4227,7,107009,'Abpakhsh','آب پخش','دشتستان','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4228,7,107010,'Borazjan','برازجان','دشتستان','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4229,7,107011,'Tang eram','تنگ ارم','دشتستان','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4230,7,107012,'Dalaki','دالکی','دشتستان','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4231,7,107013,'SaedAbad','سعد آباد','دشتستان','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4232,7,107014,'Shabankareh','شبانکاره','دشتستان','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4233,7,107015,'Kalameh','کلمه','دشتستان','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4234,7,107016,'Vahdatiyeh','وحدتیه','دشتستان','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4235,7,107017,'Khormoj','خورموج','دشتی','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4236,7,107018,'Shanbeh','شنبه','دشتی','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4237,7,107019,'Kaki','کاکی','دشتی','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4238,7,107020,'Abdan','آبدان','دیر','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4239,7,107021,'Bordkhun','بردخون','دیر','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4240,7,107022,'Bardestan','بردستان','دیر','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4241,7,107023,'BandarDayyer','بندردیر','دیر','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4242,7,107024,'EmamHasan','امام حسن','دیلم','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4243,7,107025,'BandarDeylam','بندردیلم','دیلم','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4244,7,107026,'BandarKangan','بندرکنگان','کنگان','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4245,7,107027,'Banak','بنک','کنگان','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4246,7,107028,'Siraf','سیراف','کنگان','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4247,7,107029,'Asaloyeh','عسلویه','کنگان','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4248,7,107030,'Nakhltaghi','نخل تقی','کنگان','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4249,7,107031,'BandarRig','بندرریگ','گناوه','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4250,7,107032,'BandarGenaveh','بندرگناوه','گناوه','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4251,8,108001,'EslamShahr','اسلامشهر','اسلامشهر','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4252,8,108002,'Chahardangeh','چهاردانگه','اسلامشهر','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4253,8,108003,'SalehAbad','صالح آباد','بهارستان','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4254,8,108004,'Golestan','گلستان','بهارستان','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4255,8,108005,'NasimShahr','نسیم شهر','بهارستان','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4256,8,108006,'Pakdasht','پاکدشت','پاکدشت','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4257,8,108007,'SharifAbad','شریف آباد','پاکدشت','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4258,8,108008,'FrounAbad','فرون آباد','پاکدشت','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4259,8,108009,'Pishva','پیشوا','پیشوا','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4260,8,108010,'Boomehen','بومهن','تهران','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4261,8,108011,'Pardis','پردیس','تهران','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4262,8,108012,'Tehran','تهران','تهران','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4263,8,108013,'Abesard','آبسرد','دماوند','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4264,8,108014,'AbAli','آبعلی','دماوند','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4265,8,108015,'Damavand','دماوند','دماوند','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4266,8,108016,'Roodehen','رودهن','دماوند','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4267,8,108017,'Kilan','کیلان','دماوند','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4268,8,108018,'Robatkarim','رباط کریم','رباط کریم','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4269,8,108019,'NasirShahr','نصیرشهر','رباط کریم','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4270,8,108020,'BagherShahr','باقرشهر','ری','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4271,8,108021,'HasanAbad','حسن آباد','ری','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4272,8,108022,'Kahrizak','کهریزک','ری','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4273,8,108023,'Fasham','فشم','شمیرانات','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4274,8,108024,'Lavasan','لواسان','شمیرانات','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4275,8,108025,'Andisheh','اندیشه','شهریار','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4276,8,108026,'Baghestan','باغستان','شهریار','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4277,8,108027,'ShahedShahr','شاهدشهر','شهریار','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4278,8,108028,'Shahryar','شهریار','شهریار','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4279,8,108029,'SabaShahr','صباشهر','شهریار','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4280,8,108030,'Ferdosieh','فردوسیه','شهریار','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4281,8,108031,'Vahidieh','وحیدیه','شهریار','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4282,8,108032,'Arjmand','ارجمند','فیروزکوه','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4283,8,108033,'Firoozkooh','فیروزکوه','فیروزکوه','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4284,8,108034,'Ghods','قدس','قدس','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4285,8,108035,'Safadasht','صفادشت','ملارد','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4286,8,108036,'Malard','ملارد','ملارد','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4287,8,108037,'JavadAbad','جوادآباد','ورامین','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4288,8,108038,'Gharchak','قرچک','ورامین','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4289,8,108039,'Varamin','ورامین','ورامین','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4290,8,108040,'Parand','شهرجدید پرند','رباط کریم','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4291,8,108041,'Rey','ری','ری','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4292,8,108042,'Shemshak','شمشک','شمیرانات','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4293,9,109001,'Ardal','اردل','اردل','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4294,9,109002,'Boroojen','بروجن','بروجن','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4295,9,109003,'Beldaji','بلداجی','بروجن','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4296,9,109004,'Sefiddasht','سفیددشت','بروجن','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4297,9,109005,'Faradonbeh','فرادنبه','بروجن','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4298,9,109006,'Gandoman','گندمان','بروجن','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4299,9,109007,'Naghneh','نقنه','بروجن','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4300,9,109008,'Bon','بن','شهرکرد','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4301,9,109009,'Saman','سامان','شهرکرد','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4302,9,109010,'Soodejan','سودجان','شهرکرد','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4303,9,109011,'Sooreshjan','سورشجان','شهرکرد','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4304,9,109012,'Shahrekord','شهرکرد','شهرکرد','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4305,9,109013,'Taghanak','طاقانک','شهرکرد','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4306,9,109014,'FarrokhShahr','فرخ شهر','شهرکرد','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4307,9,109015,'Kian','کیان','شهرکرد','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4308,9,109016,'Nafch','نافچ','شهرکرد','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4309,9,109017,'Hafshejan','هفشجان','شهرکرد','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4310,9,109018,'Vardanjan','وردنجان','شهرکرد','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4311,9,109019,'Babaheidar','باباحیدر','فارسان','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4312,9,109020,'Pordanjan','پردنجان','فارسان','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4313,9,109021,'Joonghan','جونقان','فارسان','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4314,9,109022,'Farsan','فارسان','فارسان','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4315,9,109023,'Chelgerd','چلگرد','کوهرنگ','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4316,9,109024,'Dastena','دستنا','کیار','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4317,9,109025,'Shalamzar','شلمزار','کیار','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4318,9,109026,'Gahroo','گهرو','کیار','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4319,9,109027,'Naghan','ناغان','کیار','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4320,9,109028,'Aloni','آلونی','لردگان','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4321,9,109029,'Lordegan','لردگان','لردگان','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4322,9,109030,'Malekhalife','مال خلیفه','لردگان','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4323,9,109031,'Monj','منج','لردگان','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4324,9,109032,'Dashtak','دشتک','اردل','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4325,9,109033,'Sarkhoon','سرخون','اردل','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4326,9,109034,'Kaj','کاج','اردل','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4327,9,109035,'Gujan','گوجان','فارسان','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4328,9,109036,'Cholicheh','چلیچه','فارسان','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4329,9,109037,'Samsami','صمصامی','کوهرنگ','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4330,9,109038,'Bazoft','بازفت','کوهرنگ','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4331,10,110001,'Eresk','ارسک','بشرویه','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4332,10,110002,'Boshrooyeh','بشرویه','بشرویه','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4333,10,110003,'Birjand','بیرجند','بیرجند','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4334,10,110004,'Khoosf','خوسف','بیرجند','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4335,10,110005,'MohammadShahr','محمدشهر','بیرجند','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4336,10,110006,'Asadyeh','اسدیه','درمیان','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4337,10,110007,'Tabasmasina','طبس مسینا','درمیان','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4338,10,110008,'Ghohestan','قهستان','درمیان','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4339,10,110009,'Gazik','گزیک','درمیان','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4340,10,110010,'Aysek','آیسک','سرایان','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4341,10,110011,'Sarayan','سرایان','سرایان','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4342,10,110012,'Se ghale','سه قلعه','سرایان','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4343,10,110013,'Sarbisheh','سربیشه','سربیشه','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4344,10,110014,'Moud','مود','سربیشه','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4345,10,110015,'Eslamyeh','اسلامیه','فردوس','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4346,10,110016,'Ferdos','فردوس','فردوس','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4347,10,110017,'Esfaden','اسفدن','قاینات','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4348,10,110018,'ArianShahr','آرین شهر','قاینات','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4349,10,110019,'HajiAbad','حاجی آباد','قاینات','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4350,10,110020,'Khazridashtbayaz','خضری دشت بیاض','قاینات','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4351,10,110021,'Zahan','زهان','قاینات','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4352,10,110022,'Ghaen','قاین','قاینات','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4353,10,110023,'Nimbolook','نیمبلوک','قاینات','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4354,10,110024,'Shoosf','شوسف','نهبندان','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4355,10,110025,'Nehbandan','نهبندان','نهبندان','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4356,10,110026,'Deyhook','دیهوک','طبس','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4357,10,110027,'Tabas','طبس','طبس','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4358,10,110028,'EshghAbad','عشق آباد','طبس','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4359,11,111001,'Bakharz','باخرز','باخرز','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4360,11,111002,'Bejestan','بجستان','بجستان','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4361,11,111003,'Younesi','یونسی','بجستان','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4362,11,111004,'Anabad','انابد','بردسکن','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4363,11,111005,'Bardeskan','بردسکن','بردسکن','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4364,11,111006,'ShahrAbad','شهرآباد','بردسکن','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4365,11,111007,'Shandiz','شاندیز','بینالود','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4366,11,111008,'Torghabeh','طرقبه','بینالود','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4367,11,111009,'Taibad','تایباد','تایباد','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4368,11,111010,'Kariz','کاریز','تایباد','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4369,11,111011,'Mashhadrize','مشهدریزه','تایباد','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4370,11,111012,'Firuzeh','فیروزه','تخت جلگه','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4371,11,111013,'HemmatAbad','همت آباد','تخت جلگه','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4372,11,111014,'Ahmadabadesolat','احمدابادصولت','تربت جام','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4373,11,111015,'Torbatjam','تربت جام','تربت جام','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4374,11,111016,'SalehAbad','صالح آباد','تربت جام','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4375,11,111017,'NasrAbad','نصرآباد','تربت جام','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4376,11,111018,'NilShahr','نیل شهر','تربت جام','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4377,11,111019,'Baek','بایک','تربت حیدریه','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4378,11,111020,'Torbatheydariyeh','تربت حیدریه','تربت حیدریه','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4379,11,111021,'Robatsang','رباط سنگ','تربت حیدریه','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4380,11,111022,'Kodkan','کدکن','تربت حیدریه','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4381,11,111023,'Joghatay','جغتای','جغتای','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4382,11,111024,'Neghab','نقاب','جوین','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4383,11,111025,'Chenaran','چناران','چناران','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4384,11,111026,'Golmakan','گلمکان','چناران','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4385,11,111027,'KhalilAbad','خلیل آباد','خلیل آباد','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4386,11,111028,'Kondor','کندر','خلیل آباد','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4387,11,111029,'Khaf','خواف','خواف','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4388,11,111030,'Salami','سلامی','خواف','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4389,11,111031,'Sangan','سنگان','خواف','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4390,11,111032,'GhasemAbad','قاسم آباد','خواف','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4391,11,111033,'Nashtifan','نشتیفان','خواف','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4392,11,111034,'SoltanAbad','سلطان آباد','خوشاب','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4393,11,111035,'Chapeshloo','چاپشلو','درگز','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4394,11,111036,'Daregaz','درگز','درگز','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4395,11,111037,'LotfAbad','لطف آباد','درگز','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4396,11,111038,'Nokhandan','نوخندان','درگز','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4397,11,111039,'Jangal','جنگل','رشتخوار','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4398,11,111040,'Rashtkhar','رشتخوار','رشتخوار','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4399,11,111041,'DolatAbad','دولت آباد','زاوه','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4400,11,111042,'Davarzan','داورزن','سبزوار','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4401,11,111043,'Ruodab','روداب','سبزوار','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4402,11,111044,'Sabzevar','سبزوار','سبزوار','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4403,11,111045,'Sheshtamad','ششتمد','سبزوار','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4404,11,111046,'Sarakhs','سرخس','سرخس','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4405,11,111047,'Mazdavand','مزدآوند','سرخس','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4406,11,111048,'Sefidsang','سفیدسنگ','فریمان','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4407,11,111049,'Farhadgar','فرهادگرد','فریمان','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4408,11,111050,'Fariman','فریمان','فریمان','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4409,11,111051,'GhalandarAbad','قلندرآباد','فریمان','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4410,11,111052,'Bajgiran','باجگیران','قوچان','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4411,11,111053,'Ghoochan','قوچان','قوچان','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4412,11,111054,'Rivash','ریوش','کاشمر','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4413,11,111055,'Kashmar','کاشمر','کاشمر','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4414,11,111056,'Shahrezoo','شهرزو','کلات','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4415,11,111057,'Kalat','کلات','کلات','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4416,11,111058,'Bidokht','بیدخت','گناباد','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4417,11,111059,'Kakhak','کاخک','گناباد','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4418,11,111060,'Gonabad','گناباد','گناباد','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4419,11,111061,'Razaviyeh','رضویه','مشهد','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4420,11,111062,'Mashhad','مشهد','مشهد','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4421,11,111063,'MashhadSamen','مشهد ثامن','مشهد','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4422,11,111064,'MolkAbad','ملک آباد','مشهد','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4423,11,111065,'Shadmehr','شادمهر','مه ولات','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4424,11,111066,'FeizAbad','فیض آباد','مه ولات','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4425,11,111067,'Baar','بار','نیشابور','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4426,11,111068,'Chakaneh','چکنه','نیشابور','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4427,11,111069,'Kharv','خرو','نیشابور','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4428,11,111070,'Darrood','درود','نیشابور','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4429,11,111071,'EshghAbad','عشق آباد','نیشابور','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4430,11,111072,'Ghadamgah','قدمگاه','نیشابور','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4431,11,111073,'Neyshaboor','نیشابور','نیشابور','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4432,12,112001,'Esfarayen','اسفراین','اسفراین','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4433,12,112002,'SafiAbad','صفی آباد','اسفراین','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4434,12,112003,'Bojnoord','بجنورد','بجنورد','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4435,12,112004,'Hesargarmkhan','حصارگرمخان','بجنورد','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4436,12,112005,'Raz','راز','بجنورد','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4437,12,112006,'Jajarm','جاجرم','جاجرم','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4438,12,112007,'Sankhast','سنخواست','جاجرم','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4439,12,112008,'Shoghan','شوقان','جاجرم','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4440,12,112009,'Shirvan','شیروان','شیروان','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4441,12,112010,'Lojali','لوجلی','شیروان','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4442,12,112011,'Titkanlo','تیتکانلو','فاروج','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4443,12,112012,'Farooj','فاروج','فاروج','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4444,12,112013,'Ivar','ایور','گرمه','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4445,12,112014,'Daragh','درق','گرمه','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4446,12,112015,'Garmeh','گرمه','گرمه','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4447,12,112016,'Ashkhaneh','آشخانه','مانه وسملقان','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4448,12,112017,'Pishghale','پیش قلعه','مانه وسملقان','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4449,12,112018,'Ghazi','قاضی','مانه وسملقان','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4450,13,113001,'Omidieh','امیدیه','امیدیه','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4451,13,113002,'Jayzan','جایزان','امیدیه','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4452,13,113003,'Ghalekhajeh','قلعه خواجه','اندیکا','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4453,13,113004,'Andimeshk','اندیمشک','اندیمشک','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4454,13,113005,'Hoseyniyeh','حسینیه','اندیمشک','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4455,13,113006,'Ahvaz','اهواز','اهواز','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4456,13,113007,'Hamidieh','حمیدیه','اهواز','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4457,13,113008,'Izeh','ایذه','ایذه','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4458,13,113009,'Dehdez','دهدز','ایذه','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4459,13,113010,'Arvandkenar','اروندکنار','آبادان','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4460,13,113011,'Abadan','آبادان','آبادان','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4461,13,113012,'Choebdeh','چویبده','آبادان','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4462,13,113013,'Baghmalek','باغ ملک','باغ ملک','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4463,13,113014,'Seydun','صیدون','باغ ملک','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4464,13,113015,'Ghaletol','قلعه تل','باغ ملک','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4465,13,113016,'Meidavood','میداود','باغ ملک','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4466,13,113017,'Shiban','شیبان','باوی','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4467,13,113018,'Mollasani','ملاثانی','باوی','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4468,13,113019,'Veys','ویس','باوی','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4469,13,113020,'BandarEmamKhomeini','بندرامام خمینی','بندرماهشهر','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4470,13,113021,'BandarMahshahr','بندرماهشهر','بندرماهشهر','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4471,13,113022,'Chamran','چمران','بندرماهشهر','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4472,13,113023,'Aghajari','آغاجاری','بهبهان','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4473,13,113024,'Behbahan','بهبهان','بهبهان','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4474,13,113025,'Sardasht','سردشت','بهبهان','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4475,13,113026,'KhoramShahr','خرمشهر','خرمشهر','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4476,13,113027,'MinooShahr','مینوشهر','خرمشهر','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4477,13,113028,'Choghamish','چغامیش','دزفول','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4478,13,113029,'Hamzeh','حمزه','دزفول','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4479,13,113030,'Dezab','دزآب','دزفول','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4480,13,113031,'Dezfool','دزفول','دزفول','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4481,13,113032,'Saland','سالند','دزفول','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4482,13,113033,'SafiAbad','صفی آباد','دزفول','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4483,13,113034,'Mianrood','میانرود','دزفول','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4484,13,113035,'Bostan','بستان','دشت آزادگان','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4485,13,113036,'Soosangerd','سوسنگرد','دشت آزادگان','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4486,13,113037,'Ramshir','رامشیر','رامشیر','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4487,13,113038,'Meshrageh','مشراگه','رامشیر','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4488,13,113039,'Ramhormoz','رامهرمز','رامهرمز','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4489,13,113040,'Darkhovein','دارخوین','شادگان','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4490,13,113041,'Shadegan','شادگان','شادگان','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4491,13,113042,'Alvan','الوان','شوش','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4492,13,113043,'Hor','حر','شوش','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4493,13,113044,'Shavoor','شاوور','شوش','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4494,13,113045,'Shoosh','شوش','شوش','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4495,13,113046,'Salehmoshatat','صالح مشطت','شوش','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4496,13,113047,'Sherafat','شرافت','شوشتر','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4497,13,113048,'Shooshtar','شوشتر','شوشتر','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4498,13,113049,'Gurieh','گوریه','شوشتر','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4499,13,113050,'Torkalaki','ترکالکی','گتوند','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4500,13,113051,'Jannatmakan','جنت مکان','گتوند','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4501,13,113052,'Somaleh','سماله','گتوند','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4502,13,113053,'SalehShahr','صالح شهر','گتوند','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4503,13,113054,'Gotvand','گتوند','گتوند','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4504,13,113055,'Lali','لالی','لالی','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4505,13,113056,'Masjedsoleiman','مسجدسلیمان','مسجدسلیمان','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4506,13,113057,'Haftgol','هفتگل','هفتگل','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4507,13,113058,'Zohre','زهره','هندیجان','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4508,13,113059,'Hendijan','هندیجان','هندیجان','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4509,13,113060,'Rafi','رفیع','هویزه','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4510,13,113061,'Hoveizeh','هویزه','هویزه','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4511,14,114001,'Abhar','ابهر','ابهر','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4512,14,114002,'Soltanieh','سلطانیه','ابهر','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4513,14,114003,'Saeenghale','صایین قلعه','ابهر','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4514,14,114004,'Hidaj','هیدج','ابهر','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4515,14,114005,'Halab','حلب','ایجرود','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4516,14,114006,'ZarinAbad','زرین آباد','ایجرود','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4517,14,114007,'Zarrinroud','زرین رود','خدابنده','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4518,14,114008,'Sojas','سجاس','خدابنده','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4519,14,114009,'Sohrevard','سهرورد','خدابنده','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4520,14,114010,'Gheidar','قیدار','خدابنده','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4521,14,114011,'Garmab','گرماب','خدابنده','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4522,14,114012,'Khoramdareh','خرمدره','خرمدره','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4523,14,114013,'Armaghankhaneh','ارمغانخانه','زنجان','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4524,14,114014,'Zanjan','زنجان','زنجان','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4525,14,114015,'Abbar','آب بر','طارم','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4526,14,114016,'Chavarzagh','چورزق','طارم','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4527,14,114017,'Dandi','دندی','ماهنشان','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4528,14,114018,'Mahneshan','ماه نشان','ماهنشان','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4529,15,115001,'Amiriyeh','امیریه','دامغان','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4530,15,115002,'Damghan','دامغان','دامغان','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4531,15,115003,'Dibaj','دیباج','دامغان','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4532,15,115004,'Sorkheh','سرخه','سمنان','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4533,15,115005,'Semnan','سمنان','سمنان','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4534,15,115006,'Bastam','بسطام','شاهرود','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4535,15,115007,'Biarjmand','بیارجمند','شاهرود','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4536,15,115008,'Shahrood','شاهرود','شاهرود','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4537,15,115009,'Kalatehkhij','کلاته خیج','شاهرود','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4538,15,115010,'Mojen','مجن','شاهرود','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4539,15,115011,'Miamey','میامی','شاهرود','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4540,15,115012,'Ivanaki','ایوانکی','گرمسار','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4541,15,115013,'Aradan','آرادان','گرمسار','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4542,15,115014,'Garmsar','گرمسار','گرمسار','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4543,15,115015,'Darjazin','درجزین','مهدی شهر','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4544,15,115016,'Shahmirzad','شهمیرزاد','مهدی شهر','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4545,15,115017,'MehdiShahr','مهدی شهر','مهدی شهر','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4546,16,116001,'IranShahr','ایرانشهر','ایرانشهر','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4547,16,116002,'Bazman','بزمان','ایرانشهر','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4548,16,116003,'Bampoor','بمپور','ایرانشهر','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4549,16,116004,'Mohammadan','محمدان','ایرانشهر','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4550,16,116005,'Chabahar','چابهار','چابهار','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4551,16,116006,'Negor','نگور','چابهار','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4552,16,116007,'Khash','خاش','خاش','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4553,16,116008,'NookAbad','نوک آباد','خاش','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4554,16,116009,'Golmorti','گلمورتی','دلگان','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4555,16,116010,'Bonjar','بنجار','زابل','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4556,16,116011,'Zabol','زابل','زابل','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4557,16,116012,'Zahedan','زاهدان','زاهدان','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4558,16,116013,'Mirjaveh','میرجاوه','زاهدان','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4559,16,116014,'NosratAbad','نصرت آباد','زاهدان','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4560,16,116015,'Zahak','زهک','زهک','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4561,16,116016,'Jalgh','جالق','سراوان','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4562,16,116017,'Saravan','سراوان','سراوان','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4563,16,116018,'Sirkan','سیرکان','سراوان','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4564,16,116019,'Gosht','گشت','سراوان','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4565,16,116020,'Mohammadi','محمدی','سراوان','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4566,16,116021,'Pishin','پیشین','سرباز','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4567,16,116022,'Rask','راسک','سرباز','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4568,16,116023,'Sarbaz','سرباز','سرباز','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4569,16,116024,'Sooran','سوران','سیب و سوران','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4570,16,116025,'Hidooch','هیدوچ','سیب و سوران','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4571,16,116026,'ZarAbad','زرآباد','کنارک','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4572,16,116027,'Konarak','کنارک','کنارک','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4573,16,116028,'Zaboli','زابلی','مهرستان-زابلی','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4574,16,116029,'Espakeh','اسپکه','نیک شهر','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4575,16,116030,'Bent','بنت','نیک شهر','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4576,16,116031,'Fanooj','فنوج','نیک شهر','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4577,16,116032,'Ghasreghand','قصرقند','نیک شهر','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4578,16,116033,'NikShahr','نیک شهر','نیک شهر','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4579,16,116034,'Adimi','ادیمی','نیمروز','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4580,16,116035,'Aliakbar','علی اکبر','هامون','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4581,16,116036,'MohammadAbad','محمدآباد','هامون','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4582,16,116037,'Dustmohammad','دوست محمد','هیرمند','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4583,17,117001,'Arsanjan','ارسنجان','ارسنجان','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4584,17,117002,'Estahban','استهبان','استهبان','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4585,17,117003,'Edge','ایج','استهبان','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4586,17,117004,'Roniz','رونیز','استهبان','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4587,17,117005,'Eghlid','اقلید','اقلید','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4588,17,117006,'HasanAbad','حسن آباد','اقلید','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4589,17,117007,'Dojkord','دژکرد','اقلید','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4590,17,117008,'Sedeh','سده','اقلید','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4591,17,117009,'Izadkhast','ایزدخواست','آباده','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4592,17,117010,'Abadeh','آباده','آباده','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4593,17,117011,'Bahman','بهمن','آباده','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4594,17,117012,'Soormagh','سورمق','آباده','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4595,17,117013,'Soghad','صغاد','آباده','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4596,17,117014,'Bovanat','بوانات','بوانات','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4597,17,117015,'Hesami','حسامی','بوانات','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4598,17,117016,'Karehi','کره ای','بوانات','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4599,17,117017,'SaadatShahr','سعادت شهر','پاسارگاد','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4600,17,117018,'Babanar','باب انار','جهرم','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4601,17,117019,'Jahrom','جهرم','جهرم','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4602,17,117020,'Khavaran','خاوران','جهرم','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4603,17,117021,'Dozeh','دوزه','جهرم','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4604,17,117022,'GhotbAbad','قطب آباد','جهرم','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4605,17,117023,'Khorameh','خرامه','خرامه','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4606,17,117024,'SafaShahr','صفاشهر','خرم بید','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4607,17,117025,'GhaderAbad','قادرآباد','خرم بید','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4608,17,117026,'Khonj','خنج','خنج','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4609,17,117027,'JanatShahr','جنت شهر','داراب','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4610,17,117028,'Darab','داراب','داراب','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4611,17,117029,'Doborji','دوبرجی','داراب','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4612,17,117030,'Fadami','فدامی','داراب','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4613,17,117031,'Masiri','مصیری','رستم','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4614,17,117032,'HajiAbad','حاجی آباد','زرین دشت','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4615,17,117033,'Dabiran','دبیران','زرین دشت','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4616,17,117034,'Shahrepir','شهرپیر','زرین دشت','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4617,17,117035,'Ardakan','اردکان','سپیدان','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4618,17,117036,'Beiza','بیضا','سپیدان','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4619,17,117037,'HomaShahr','هماشهر','سپیدان','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4620,17,117038,'Sarvestan','سروستان','سروستان','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4621,17,117039,'Koohenjan','کوهنجان','سروستان','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4622,17,117040,'Khanezenian','خانه زنیان','شیراز','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4623,17,117041,'Darian','داریان','شیراز','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4624,17,117042,'Zarghan','زرقان','شیراز','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4625,17,117043,'Shahrsadra','شهرصدرا','شیراز','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4626,17,117044,'Shiraz','شیراز','شیراز','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4627,17,117045,'Lapooee','لپویی','شیراز','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4628,17,117046,'Dehram','دهرم','فراشبند','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4629,17,117047,'Farashband','فراشبند','فراشبند','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4630,17,117048,'Nojein','نوجین','فراشبند','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4631,17,117049,'ZahedShahr','زاهدشهر','فسا','2026-09-17 05:58:46','2026-09-17 05:58:46'),(4632,17,117050,'Sheshdeh','ششده','فسا','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4633,17,117051,'Fasa','فسا','فسا','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4634,17,117052,'Nobandegan','نوبندگان','فسا','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4635,17,117053,'FiroozAbad','فیروزآباد','فیروزآباد','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4636,17,117054,'Meimand','میمند','فیروزآباد','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4637,17,117055,'Afzar','افزر','قیروکارزین','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4638,17,117056,'EmamShahr','امام شهر','قیروکارزین','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4639,17,117057,'Gheer','قیر','قیروکارزین','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4640,17,117058,'Karzin','کارزین','قیروکارزین','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4641,17,117059,'Mobarakabaddiz','مبارک آباددیز','قیروکارزین','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4642,17,117060,'Baladeh','بالاده','کازرون','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4643,17,117061,'Khesht','خشت','کازرون','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4644,17,117062,'Ghaemieh','قایمیه','کازرون','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4645,17,117063,'Kazeroon','کازرون','کازرون','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4646,17,117064,'Kenartakhteh','کنارتخته','کازرون','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4647,17,117065,'Nodan','نودان','کازرون','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4648,17,117066,'Kovar','کوار','کوار','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4649,17,117067,'Gerash','گراش','گراش','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4650,17,117068,'Evaz','اوز','لارستان','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4651,17,117069,'Banarooyeh','بنارویه','لارستان','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4652,17,117070,'Beiram','بیرم','لارستان','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4653,17,117071,'Jooyam','جویم','لارستان','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4654,17,117072,'Khoor','خور','لارستان','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4655,17,117073,'Emadodeh','عمادده','لارستان','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4656,17,117074,'Lar','لار','لارستان','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4657,17,117075,'Latifi','لطیفی','لارستان','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4658,17,117076,'Ashknan','اشکنان','لامرد','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4659,17,117077,'Ahl','اهل','لامرد','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4660,17,117078,'Alamarvdasht','علامرودشت','لامرد','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4661,17,117079,'Lamerd','لامرد','لامرد','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4662,17,117080,'Ramjerd','رامجرد','مرودشت','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4663,17,117081,'Seyedan','سیدان','مرودشت','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4664,17,117082,'Kaamfirooz','کامفیروز','مرودشت','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4665,17,117083,'Marvdasht','مرودشت','مرودشت','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4666,17,117084,'Khumehzar','خومه زار','ممسنی','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4667,17,117085,'NoorAbad','نورآباد','ممسنی','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4668,17,117086,'Asir','اسیر','مهر','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4669,17,117087,'Galledar','گله دار','مهر','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4670,17,117088,'Mehr','مهر','مهر','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4671,17,117089,'Varavi','وراوی','مهر','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4672,17,117090,'Abadehtashk','آباده طشک','نی ریز','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4673,17,117091,'Ghatruyeh','قطرویه','نی ریز','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4674,17,117092,'Meshkan','مشکان','نی ریز','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4675,17,117093,'Neyriz','نی ریز','نی ریز','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4676,18,118001,'Alvand','الوند','البرز','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4677,18,118002,'Bidestan','بیدستان','البرز','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4678,18,118003,'Sharifieh','شریفیه','البرز','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4679,18,118004,'Mohammadieh','محمدیه','البرز','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4680,18,118005,'Abeyek','آبیک','آبیک','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4681,18,118006,'Khakali','خاکعلی','آبیک','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4682,18,118007,'Ardagh','ارداق','بویین زهرا','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4683,18,118008,'Abgarm','آبگرم','بویین زهرا','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4684,18,118009,'Avaj','آوج','بویین زهرا','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4685,18,118010,'Booeenzahra','بویین زهرا','بویین زهرا','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4686,18,118011,'Danesfehan','دانسفهان','بویین زهرا','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4687,18,118012,'SagzAbad','سگزآباد','بویین زهرا','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4688,18,118013,'Shal','شال','بویین زهرا','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4689,18,118014,'Esfarvarin','اسفرورین','تاکستان','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4690,18,118015,'Takestan','تاکستان','تاکستان','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4691,18,118016,'Khoramdasht','خرمدشت','تاکستان','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4692,18,118017,'ZiaAbad','ضیاءآباد','تاکستان','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4693,18,118018,'Narje','نرجه','تاکستان','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4694,18,118019,'Eghbalyeh','اقبالیه','قزوین','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4695,18,118020,'Razmiyan','رازمیان','قزوین','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4696,18,118021,'Sirdan','سیردان','قزوین','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4697,18,118022,'Qazvin','قزوین','قزوین','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4698,18,118023,'Kouhin','کوهین','قزوین','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4699,18,118024,'MahmudAbadNemuneh','محمودآبادنمونه','قزوین','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4700,18,118025,'Moalemkelayeh','معلم کلایه','قزوین','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4701,19,119001,'Jafarieh','جعفریه','قم','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4702,19,119002,'Dastjerd','دستجرد','قم','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4703,19,119003,'Salafchegan','سلفچگان','قم','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4704,19,119004,'Qom','قم','قم','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4705,19,119005,'Ghanavat','قنوات','قم','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4706,19,119006,'Kahak','کهک','قم','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4707,20,120001,'Armardeh','آرمرده','بانه','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4708,20,120002,'Baneh','بانه','بانه','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4709,20,120003,'Booeensofla','بویین سفلی','بانه','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4710,20,120004,'Kanisor','کانی سور','بانه','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4711,20,120005,'Babarashani','بابارشانی','بیجار','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4712,20,120006,'Bijar','بیجار','بیجار','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4713,20,120007,'Yasokand','یاسوکند','بیجار','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4714,20,120008,'BolbanAbad','بلبان آباد','دهگلان','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4715,20,120009,'Dehgolan','دهگلان','دهگلان','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4716,20,120010,'Divandareh','دیواندره','دیواندره','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4717,20,120011,'Zarineh','زرینه','دیواندره','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4718,20,120012,'SarvAbad','سروآباد','سروآباد','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4719,20,120013,'Saghez','سقز','سقز','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4720,20,120014,'Saheb','صاحب','سقز','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4721,20,120015,'Sanandaj','سنندج','سنندج','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4722,20,120016,'Shoysheh','شویشه','سنندج','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4723,20,120017,'Dazj','دزج','قروه','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4724,20,120018,'Delbaran','دلبران','قروه','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4725,20,120019,'SerishAbad','سریش آباد','قروه','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4726,20,120020,'Ghorveh','قروه','قروه','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4727,20,120021,'Kamyaran','کامیاران','کامیاران','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4728,20,0,'Mochesh','موچش','کامیاران','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4729,20,120023,'Chenareh','چناره','مریوان','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4730,20,120024,'Kanidinar','کانی دینار','مریوان','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4731,20,120025,'Marivan','مریوان','مریوان','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4732,21,121001,'Orzooeeyeh','ارزوییه','ارزوییه','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4733,21,121002,'AminShahr','امین شهر','انار','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4734,21,121003,'Anar','انار','انار','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4735,21,121004,'Baft','بافت','بافت','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4736,21,121005,'Bezanjan','بزنجان','بافت','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4737,21,121006,'Bardsir','بردسیر','بردسیر','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4738,21,121007,'Golzar','گلزار','بردسیر','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4739,21,121008,'Lalezar','لاله زار','بردسیر','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4740,21,121009,'Negar','نگار','بردسیر','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4741,21,121010,'Baravat','بروات','بم','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4742,21,121011,'Bam','بم','بم','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4743,21,121012,'Jebalbarez','جبالبارز','جیرفت','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4744,21,121013,'Jiroft','جیرفت','جیرفت','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4745,21,121014,'Darbbehesht','درب بهشت','جیرفت','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4746,21,121015,'Rabor','رابر','رابر','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4747,21,121016,'Ravar','راور','راور','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4748,21,121017,'Hojedak','هجدک','راور','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4749,21,121018,'Bahreman','بهرمان','رفسنجان','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4750,21,121019,'Rafsanjan','رفسنجان','رفسنجان','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4751,21,121020,'Safaaeeyeh','صفاییه','رفسنجان','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4752,21,121021,'Koshkooeeyeh','کشکوییه','رفسنجان','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4753,21,121022,'Mesesarcheshmeh','مس سرچشمه','رفسنجان','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4754,21,121023,'Roodbar','رودبار','رودبارجنوب','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4755,21,121024,'MohammadAbad','محمدآباد','ریگان','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4756,21,121025,'Khanook','خانوک','زرند','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4757,21,121026,'Reyhan','ریحان','زرند','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4758,21,121027,'Zarand','زرند','زرند','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4759,21,121028,'YazdanShahr','یزدان شهر','زرند','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4760,21,121029,'Pariz','پاریز','سیرجان','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4761,21,121030,'ZeidAbad','زیدآباد','سیرجان','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4762,21,121031,'Sirjan','سیرجان','سیرجان','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4763,21,121032,'NajafShahr','نجف شهر','سیرجان','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4764,21,121033,'HomaShahr','هماشهر','سیرجان','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4765,21,121034,'Javazm','جوزم','شهربابک','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4766,21,121035,'KhatoonAbad','خاتون آباد','شهربابک','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4767,21,121036,'Khorsand','خورسند','شهربابک','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4768,21,121037,'Dahej','دهج','شهربابک','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4769,21,121038,'Shahrebabak','شهربابک','شهربابک','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4770,21,121039,'Dosari','دوساری','عنبرآباد','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4771,21,121040,'AnbarAbad','عنبرآباد','عنبرآباد','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4772,21,121041,'Mardehak','مردهک','عنبرآباد','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4773,21,121042,'Faryab','فاریاب','فاریاب','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4774,21,121043,'Fahraj','فهرج','فهرج','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4775,21,121044,'Ghaleganj','قلعه گنج','قلعه گنج','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4776,21,121045,'EkhtiarAbad','اختیارآباد','کرمان','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4777,21,121046,'Andoohjerd','اندوهجرد','کرمان','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4778,21,121047,'Baghin','باغین','کرمان','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4779,21,121048,'Joopar','جوپار','کرمان','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4780,21,121049,'Chatrood','چترود','کرمان','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4781,21,121050,'Rayen','راین','کرمان','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4782,21,121051,'ZangiAbad','زنگی آباد','کرمان','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4783,21,121052,'Shahdad','شهداد','کرمان','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4784,21,121053,'KazemAbad','کاظم آباد','کرمان','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4785,21,121054,'Kerman','کرمان','کرمان','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4786,21,121055,'Golbaf','گلباف','کرمان','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4787,21,121056,'Mahan','ماهان','کرمان','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4788,21,121057,'MohiAbad','محی آباد','کرمان','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4789,21,121058,'Kahnooj','کهنوج','کهنوج','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4790,21,121059,'Koohbanan','کوهبنان','کوهبنان','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4791,21,121060,'KianShahr','کیانشهر','کوهبنان','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4792,21,121061,'Manoojan','منوجان','منوجان','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4793,21,121062,'Nodezh','نودژ','منوجان','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4794,21,121063,'Narmashir','نرماشیر','نرماشیر','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4795,21,121064,'NezamShahr','نظام شهر','نرماشیر','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4796,22,122001,'Eslamabadgharb','اسلام آبادغرب','اسلام آبادغرب','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4797,22,122002,'Hamil','حمیل','اسلام آبادغرب','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4798,22,122003,'Bayangan','باینگان','پاوه','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4799,22,122004,'Paveh','پاوه','پاوه','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4800,22,122005,'Nowdeshah','نودشه','پاوه','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4801,22,122006,'Nosoud','نوسود','پاوه','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4802,22,122007,'Azgale','ازگله','ثلاث باباجانی','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4803,22,122008,'TazehAbad','تازه آباد','ثلاث باباجانی','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4804,22,122009,'Javanrood','جوانرود','جوانرود','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4805,22,122010,'Kerend','کرند','دالاهو','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4806,22,122011,'Gahvareh','گهواره','دالاهو','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4807,22,122012,'Ravansar','روانسر','روانسر','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4808,22,122013,'Shahoo','شاهو','روانسر','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4809,22,122014,'Sarpolzahab','سرپل ذهاب','سرپل ذهاب','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4810,22,122015,'Satar','سطر','سنقر','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4811,22,122016,'Songhor','سنقر','سنقر','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4812,22,122017,'Sahneh','صحنه','صحنه','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4813,22,122018,'Miyanrahan','میان راهان','صحنه','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4814,22,122019,'Soomar','سومار','قصرشیرین','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4815,22,122020,'Ghasreshirin','قصرشیرین','قصرشیرین','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4816,22,122021,'Robat','رباط','کرمانشاه','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4817,22,122022,'Kermanshah','کرمانشاه','کرمانشاه','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4818,22,122023,'Kozaran','کوزران','کرمانشاه','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4819,22,122024,'Halashi','هلشی','کرمانشاه','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4820,22,122025,'Kangavar','کنگاور','کنگاور','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4821,22,122026,'Sarmast','سرمست','گیلانغرب','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4822,22,122027,'Gilangharb','گیلانغرب','گیلانغرب','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4823,22,122028,'Bistoon','بیستون','هرسین','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4824,22,122029,'Hersin','هرسین','هرسین','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4825,23,123001,'Basht','باشت','باشت','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4826,23,123002,'Likak','لیکک','بهمیی','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4827,23,123003,'Garabsofla','گراب سفلی','بویراحمد','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4828,23,123004,'Madavan','مادوان','بویراحمد','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4829,23,123005,'Margoon','مارگون','بویراحمد','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4830,23,123006,'Yasooj','یاسوج','بویراحمد','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4831,23,123007,'Choram','چرام','چرام','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4832,23,123008,'Pataveh','پاتاوه','دنا','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4833,23,123009,'Chitab','چیتاب','دنا','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4834,23,123010,'Sisakht','سی سخت','دنا','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4835,23,123011,'Dehdasht','دهدشت','کهگیلویه','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4836,23,123012,'Dishmok','دیشموک','کهگیلویه','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4837,23,123013,'Sugh','سوق','کهگیلویه','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4838,23,123014,'Ghaleraeesi','قلعه رییسی','کهگیلویه','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4839,23,123015,'Lendeh','لنده','کهگیلویه','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4840,23,123016,'Dogonbadan','دوگنبدان','گچساران','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4841,24,124001,'AzadShahr','آزادشهر','آزادشهر','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4842,24,124002,'NeginShahr','نگین شهر','آزادشهر','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4843,24,124003,'Nodekhanduz','نوده خاندوز','آزادشهر','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4844,24,124004,'Anbaralom','انبارآلوم','آق قلا','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4845,24,124005,'AghGhola','آق قلا','آق قلا','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4846,24,124006,'BandarGaz','بندرگز','بندرگز','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4847,24,124007,'Nokandeh','نوکنده','بندرگز','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4848,24,124008,'BandarTorkaman','بندرترکمن','ترکمن','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4849,24,124009,'Khanbebin','خان ببین','رامیان','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4850,24,124010,'Deland','دلند','رامیان','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4851,24,124011,'Ramyan','رامیان','رامیان','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4852,24,124012,'AliAbad','علی آباد','علی آباد','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4853,24,124013,'FazelAbad','فاضل آباد','علی آباد','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4854,24,124014,'KordKooy','کردکوی','کردکوی','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4855,24,124015,'Kolaleh','کلاله','کلاله','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4856,24,124016,'Galikesh','گالیکش','گالیکش','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4857,24,124017,'Jelin','جلین','گرگان','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4858,24,124018,'Sorkhankalateh','سرخنکلاته','گرگان','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4859,24,124019,'Gorgan','گرگان','گرگان','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4860,24,124020,'SiminShahr','سیمین شهر','گمیشان','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4861,24,124021,'Gomeyshtapeh','گمیش تپه','گمیشان','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4862,24,124022,'Incheboroon','اینچه برون','گنبدکاووس','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4863,24,124023,'Gonbadkavoos','گنبدکاووس','گنبدکاووس','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4864,24,124024,'Maraveh','مراوه','مراوه تپه','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4865,24,124025,'Minoodasht','مینودشت','مینودشت','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4866,25,125001,'Amlash','املش','املش','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4867,25,125002,'Rankoh','رانکوه','املش','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4868,25,125003,'Astara','آستارا','آستارا','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4869,25,125004,'Lavandevil','لوندویل','آستارا','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4870,25,125005,'AstanehAshrafieh','آستانه اشرفیه','آستانه اشرفیه','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4871,25,125006,'KiaShahr','کیاشهر','آستانه اشرفیه','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4872,25,125007,'BandarAnzali','بندرانزلی','بندرانزلی','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4873,25,125008,'Asalem','اسالم','تالش','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4874,25,125009,'Talesh','تالش','تالش','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4875,25,125010,'Choobar','چوبر','تالش','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4876,25,125011,'Havigh','حویق','تالش','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4877,25,125012,'Lisar','لیسار','تالش','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4878,25,125013,'Khoshkebijar','خشکبیجار','رشت','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4879,25,125014,'Khomam','خمام','رشت','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4880,25,125015,'Rasht','رشت','رشت','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4881,25,125016,'Sangar','سنگر','رشت','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4882,25,125017,'Koochesfehan','کوچصفهان','رشت','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4883,25,125018,'Lashtnesha','لشت نشا','رشت','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4884,25,125019,'Looleman','لولمان','رشت','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4885,25,125020,'Parehsar','پره سر','رضوانشهر','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4886,25,125021,'Rezvanshahr','رضوانشهر','رضوانشهر','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4887,25,125022,'Barehsar','بره سر','رودبار','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4888,25,125023,'Tootekabon','توتکابن','رودبار','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4889,25,125024,'Jirandeh','جیرنده','رودبار','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4890,25,125025,'RostamAbad','رستم آباد','رودبار','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4891,25,125026,'Roodbar','رودبار','رودبار','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4892,25,125027,'Loshan','لوشان','رودبار','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4893,25,125028,'Manjil','منجیل','رودبار','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4894,25,125029,'Chaboksar','چابکسر','رودسر','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4895,25,125030,'RahimAbad','رحیم آباد','رودسر','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4896,25,125031,'Rodsar','رودسر','رودسر','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4897,25,125032,'Kalachay','کلاچای','رودسر','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4898,25,125033,'Vajargah','واجارگاه','رودسر','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4899,25,125034,'Deylaman','دیلمان','سیاهکل','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4900,25,125035,'Siahkal','سیاهکل','سیاهکل','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4901,25,125036,'AhmadSarGoorab','احمدسرگوراب','شفت','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4902,25,125037,'Shaft','شفت','شفت','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4903,25,125038,'Somesara','صومعه سرا','صومعه سرا','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4904,25,125039,'Gorabzarmikh','گوراب زرمیخ','صومعه سرا','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4905,25,125040,'Marjaghal','مرجقل','صومعه سرا','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4906,25,125041,'Fooman','فومن','فومن','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4907,25,125042,'Masooleh','ماسوله','فومن','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4908,25,125043,'Rodboneh','رودبنه','لاهیجان','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4909,25,125044,'Lahijan','لاهیجان','لاهیجان','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4910,25,125045,'Ataghor','اطاقور','لنگرود','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4911,25,125046,'Chaafchamkhale','چاف و چمخاله','لنگرود','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4912,25,125047,'Shelman','شلمان','لنگرود','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4913,25,125048,'Koomleh','کومله','لنگرود','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4914,25,125049,'Langerood','لنگرود','لنگرود','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4915,25,125050,'BazarJome','بازارجمعه','ماسال','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4916,25,125051,'Masal','ماسال','ماسال','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4917,26,126001,'Azna','ازنا','ازنا','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4918,26,126002,'MomenAbad','مومن آباد','ازنا','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4919,26,126003,'Aligoodarz','الیگودرز','الیگودرز','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4920,26,126004,'SholAbad','شول آباد','الیگودرز','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4921,26,126005,'Ashtarinan','اشترینان','بروجرد','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4922,26,126006,'Boroojerd','بروجرد','بروجرد','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4923,26,126007,'Poldokhtar','پلدختر','پلدختر','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4924,26,126008,'Maemolan','معمولان','پلدختر','2026-09-17 05:58:47','2026-09-17 05:58:47'),(4925,26,126009,'Chaghlondi','چغلوندی','خرم آباد','2026-09-17 05:58:48','2026-09-17 05:58:48'),(4926,26,126010,'KhoramAbad','خرم آباد','خرم آباد','2026-09-17 05:58:48','2026-09-17 05:58:48'),(4927,26,126011,'Zagheh','زاغه','خرم آباد','2026-09-17 05:58:48','2026-09-17 05:58:48'),(4928,26,126012,'SepidDasht','سپیددشت','خرم آباد','2026-09-17 05:58:48','2026-09-17 05:58:48'),(4929,26,126013,'NoorAbad','نورآباد','دلفان','2026-09-17 05:58:48','2026-09-17 05:58:48'),(4930,26,126014,'Haftcheshmeh','هفت چشمه','دلفان','2026-09-17 05:58:48','2026-09-17 05:58:48'),(4931,26,126015,'Sarabdoreh','سراب دوره','دوره','2026-09-17 05:58:48','2026-09-17 05:58:48'),(4932,26,126016,'Veysian','ویسیان','دوره','2026-09-17 05:58:48','2026-09-17 05:58:48'),(4933,26,126017,'Chalancholan','چالانچولان','دورود','2026-09-17 05:58:48','2026-09-17 05:58:48'),(4934,26,126018,'Dorood','دورود','دورود','2026-09-17 05:58:48','2026-09-17 05:58:48'),(4935,26,126019,'Alashtar','الشتر','سلسله','2026-09-17 05:58:48','2026-09-17 05:58:48'),(4936,26,126020,'FiroozAbad','فیروزآباد','سلسله','2026-09-17 05:58:48','2026-09-17 05:58:48'),(4937,26,126021,'Choghabel','چقابل','کوهدشت','2026-09-17 05:58:48','2026-09-17 05:58:48'),(4938,26,126022,'Darbgonbad','درب گنبد','کوهدشت','2026-09-17 05:58:48','2026-09-17 05:58:48'),(4939,26,126023,'Konani','کونانی','کوهدشت','2026-09-17 05:58:48','2026-09-17 05:58:48'),(4940,26,126024,'Koohdasht','کوهدشت','کوهدشت','2026-09-17 05:58:48','2026-09-17 05:58:48'),(4941,26,126025,'Garab','گراب','کوهدشت','2026-09-17 05:58:48','2026-09-17 05:58:48'),(4942,27,127001,'Amol','آمل','آمل','2026-09-17 05:58:48','2026-09-17 05:58:48'),(4943,27,127002,'Dabodasht','دابودشت','آمل','2026-09-17 05:58:48','2026-09-17 05:58:48'),(4944,27,127003,'Rineh','رینه','آمل','2026-09-17 05:58:48','2026-09-17 05:58:48'),(4945,27,127004,'Gaznak','گزنک','آمل','2026-09-17 05:58:48','2026-09-17 05:58:48'),(4946,27,127005,'Amirkala','امیرکلا','بابل','2026-09-17 05:58:48','2026-09-17 05:58:48'),(4947,27,127006,'Babol','بابل','بابل','2026-09-17 05:58:48','2026-09-17 05:58:48'),(4948,27,127007,'Khoshrodpey','خوش رودپی','بابل','2026-09-17 05:58:48','2026-09-17 05:58:48'),(4949,27,127008,'Zargarmahaleh','زرگرمحله','بابل','2026-09-17 05:58:48','2026-09-17 05:58:48'),(4950,27,127009,'Gatab','گتاب','بابل','2026-09-17 05:58:48','2026-09-17 05:58:48'),(4951,27,127010,'Galoogah','گلوگاه','بابل','2026-09-17 05:58:48','2026-09-17 05:58:48'),(4952,27,127011,'Marzikela','مرزیکلا','بابل','2026-09-17 05:58:48','2026-09-17 05:58:48'),(4953,27,127012,'Babolsar','بابلسر','بابلسر','2026-09-17 05:58:48','2026-09-17 05:58:48'),(4954,27,127013,'Behnamir','بهنمیر','بابلسر','2026-09-17 05:58:48','2026-09-17 05:58:48'),(4955,27,127014,'Kalebast','کله بست','بابلسر','2026-09-17 05:58:48','2026-09-17 05:58:48'),(4956,27,127015,'Behshahr','بهشهر','بهشهر','2026-09-17 05:58:48','2026-09-17 05:58:48'),(4957,27,127016,'KhalilShahr','خلیل شهر','بهشهر','2026-09-17 05:58:48','2026-09-17 05:58:48'),(4958,27,127017,'Rostamkela','رستمکلا','بهشهر','2026-09-17 05:58:48','2026-09-17 05:58:48'),(4959,27,127018,'Tonekabon','تنکابن','تنکابن','2026-09-17 05:58:48','2026-09-17 05:58:48'),(4960,27,127019,'KhoramAbad','خرم آباد','تنکابن','2026-09-17 05:58:48','2026-09-17 05:58:48'),(4961,27,127020,'Shirood','شیرود','تنکابن','2026-09-17 05:58:48','2026-09-17 05:58:48'),(4962,27,127021,'Nashtarood','نشتارود','تنکابن','2026-09-17 05:58:48','2026-09-17 05:58:48'),(4963,27,127022,'Jooybar','جویبار','جویبار','2026-09-17 05:58:48','2026-09-17 05:58:48'),(4964,27,127023,'Kohikheyl','کوهی خیل','جویبار','2026-09-17 05:58:48','2026-09-17 05:58:48'),(4965,27,127024,'Chaloos','چالوس','چالوس','2026-09-17 05:58:48','2026-09-17 05:58:48'),(4966,27,127025,'Kelardasht','کلاردشت','چالوس','2026-09-17 05:58:48','2026-09-17 05:58:48'),(4967,27,127026,'MarzanAbad','مرزن آباد','چالوس','2026-09-17 05:58:48','2026-09-17 05:58:48'),(4968,27,127027,'Ramsar','رامسر','رامسر','2026-09-17 05:58:48','2026-09-17 05:58:48'),(4969,27,127028,'Katalom','کتالم وسادات شهر','رامسر','2026-09-17 05:58:48','2026-09-17 05:58:48'),(4970,27,127029,'Sari','ساری','ساری','2026-09-17 05:58:48','2026-09-17 05:58:48'),(4971,27,127030,'Farim','فریم','ساری','2026-09-17 05:58:48','2026-09-17 05:58:48'),(4972,27,127031,'Kiyasar','کیاسر','ساری','2026-09-17 05:58:48','2026-09-17 05:58:48'),(4973,27,127032,'Alasht','آلاشت','سوادکوه','2026-09-17 05:58:48','2026-09-17 05:58:48'),(4974,27,127033,'Polsefid','پل سفید','سوادکوه','2026-09-17 05:58:48','2026-09-17 05:58:48'),(4975,27,127034,'Zirab','زیرآب','سوادکوه','2026-09-17 05:58:48','2026-09-17 05:58:48'),(4976,27,127035,'Shirgah','شیرگاه','سوادکوه','2026-09-17 05:58:48','2026-09-17 05:58:48'),(4977,27,127036,'SalmanShahr','سلمان شهر','عباس آباد','2026-09-17 05:58:48','2026-09-17 05:58:48'),(4978,27,127037,'AbbasAbad','عباس آباد','عباس آباد','2026-09-17 05:58:48','2026-09-17 05:58:48'),(4979,27,127038,'KelarAbad','کلارآباد','عباس آباد','2026-09-17 05:58:48','2026-09-17 05:58:48'),(4980,27,127039,'Fereidoonkenar','فریدونکنار','فریدونکنار','2026-09-17 05:58:48','2026-09-17 05:58:48'),(4981,27,127040,'GhaemShahr','قائم شهر','قائم شهر','2026-09-17 05:58:48','2026-09-17 05:58:48'),(4982,27,127041,'Kiakala','کیاکلا','قائم شهر','2026-09-17 05:58:48','2026-09-17 05:58:48'),(4983,27,127042,'Sorkhrood','سرخرود','محمودآباد','2026-09-17 05:58:48','2026-09-17 05:58:48'),(4984,27,127043,'MahmoodAbad','محمودآباد','محمودآباد','2026-09-17 05:58:48','2026-09-17 05:58:48'),(4985,27,127044,'Sorak','سورک','میاندورود','2026-09-17 05:58:48','2026-09-17 05:58:48'),(4986,27,127045,'Neka','نکا','نکا','2026-09-17 05:58:48','2026-09-17 05:58:48'),(4987,27,127046,'IzadShahr','ایزدشهر','نور','2026-09-17 05:58:48','2026-09-17 05:58:48'),(4988,27,127047,'Baladeh','بلده','نور','2026-09-17 05:58:48','2026-09-17 05:58:48'),(4989,27,127048,'Chamestan','چمستان','نور','2026-09-17 05:58:48','2026-09-17 05:58:48'),(4990,27,127049,'Royan','رویان','نور','2026-09-17 05:58:48','2026-09-17 05:58:48'),(4991,27,127050,'Noor','نور','نور','2026-09-17 05:58:48','2026-09-17 05:58:48'),(4992,27,127051,'Pul','پول','نوشهر','2026-09-17 05:58:48','2026-09-17 05:58:48'),(4993,27,127052,'Noshahr','نوشهر','نوشهر','2026-09-17 05:58:48','2026-09-17 05:58:48'),(4994,28,128001,'Arak','اراک','اراک','2026-09-17 05:58:48','2026-09-17 05:58:48'),(4995,28,128002,'DavoodAbad','داودآباد','اراک','2026-09-17 05:58:48','2026-09-17 05:58:48'),(4996,28,128003,'Saroogh','ساروق','اراک','2026-09-17 05:58:48','2026-09-17 05:58:48'),(4997,28,128004,'Senjan','سنجان','اراک','2026-09-17 05:58:48','2026-09-17 05:58:48'),(4998,28,128005,'Karchan','کارچان','اراک','2026-09-17 05:58:48','2026-09-17 05:58:48'),(4999,28,128006,'Karahrood','کرهرود','اراک','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5000,28,128007,'Ashtian','آشتیان','آشتیان','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5001,28,128008,'Tafresh','تفرش','تفرش','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5002,28,128009,'Khomein','خمین','خمین','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5003,28,128010,'Ghoorchebashi','قورچی باشی','خمین','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5004,28,128011,'Javersian','جاورسیان','خنداب','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5005,28,128012,'Khandab','خنداب','خنداب','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5006,28,128013,'Delijan','دلیجان','دلیجان','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5007,28,128014,'Naragh','نراق','دلیجان','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5008,28,128015,'Parandak','پرندک','زرندیه','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5009,28,128016,'Khoshkrood','خشکرود','زرندیه','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5010,28,128017,'Razeghan','رازقان','زرندیه','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5011,28,128018,'Zavieh','زاویه','زرندیه','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5012,28,128019,'Mamoonieh','مامونیه','زرندیه','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5013,28,128020,'Saveh','ساوه','ساوه','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5014,28,128021,'GharghAbad','غرق آباد','ساوه','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5015,28,128022,'Nobaran','نوبران','ساوه','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5016,28,128023,'Astanehsarband','آستانه سربند','شازند','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5017,28,128024,'Toreh','توره','شازند','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5018,28,128025,'Shazand','شازند','شازند','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5019,28,128026,'Shahbaz','شهباز','شازند','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5020,28,128027,'Mohajeran','مهاجران','شازند','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5021,28,128028,'Hendodar','هندودر','شازند','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5022,28,128029,'Khenejin','خنجین','فراهان','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5023,28,128030,'Farmahin','فرمهین','فراهان','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5024,28,128031,'Komijan','کمیجان','کمیجان','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5025,28,128032,'Milajerd','میلاجرد','کمیجان','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5026,28,128033,'Mahallat','محلات','محلات','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5027,28,128034,'Nimoor','نیمور','محلات','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5028,29,129001,'Aboomoosa','ابوموسی','ابوموسی','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5029,29,129002,'Bastak','بستک','بستک','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5030,29,129003,'Jenah','جناح','بستک','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5031,29,129004,'Sardasht','سردشت','بشاگرد','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5032,29,129005,'Goharan','گوهران','بشاگرد','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5033,29,129006,'BandarAbas','بندرعباس','بندرعباس','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5034,29,129007,'Takht','تخت','بندرعباس','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5035,29,129008,'Fin','فین','بندرعباس','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5036,29,129009,'Ghaleghazi','قلعه قاضی','بندرعباس','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5037,29,129010,'BandarLengeh','بندرلنگه','بندرلنگه','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5038,29,129011,'Charak','چارک','بندرلنگه','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5039,29,129012,'Kong','کنگ','بندرلنگه','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5040,29,129013,'Kish','کیش','بندرلنگه','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5041,29,129014,'Parsian','پارسیان','پارسیان','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5042,29,129015,'Kushkenar','کوشکنار','پارسیان','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5043,29,129016,'BandarJask','بندرجاسک','جاسک','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5044,29,129017,'Farghan','فارغان','حاجی اباد','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5045,29,129018,'HajiAbad','حاجی آباد','حاجی آباد','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5046,29,129019,'Sargaz','سرگز','حاجی آباد','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5047,29,129020,'Khomeyr','خمیر','خمیر','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5048,29,129021,'Rooydar','رویدر','خمیر','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5049,29,129022,'Bika','بیکا','رودان','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5050,29,129023,'Dehbarez','دهبارز','رودان','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5051,29,129024,'Ziyaratali','زیارتعلی','رودان','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5052,29,129025,'Sirik','سیریک','سیریک','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5053,29,129026,'Dargahan','درگهان','قشم','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5054,29,129027,'Sooza','سوزا','قشم','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5055,29,129028,'Gheshm','قشم','قشم','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5056,29,129029,'Hormoz','هرمز','قشم','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5057,29,129030,'Senderk','سندرک','میناب','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5058,29,129031,'Minab','میناب','میناب','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5059,29,129032,'Hashtbandi','هشتبندی','میناب','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5060,30,130001,'AsadAbad','اسدآباد','اسدآباد','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5061,30,130002,'Bahar','بهار','بهار','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5062,30,130003,'SalehAbad','صالح آباد','بهار','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5063,30,130004,'Laljin','لالجین','بهار','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5064,30,130005,'Tooyserkan','تویسرکان','تویسرکان','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5065,30,130006,'Serkan','سرکان','تویسرکان','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5066,30,130007,'Faresfaj','فرسفج','تویسرکان','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5067,30,130008,'Damagh','دمق','رزن','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5068,30,130009,'Razan','رزن','رزن','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5069,30,130010,'Gharvedarjazin','قروه درجزین','رزن','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5070,30,130011,'Famenin','فامنین','فامنین','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5071,30,130012,'Shirinso','شیرین سو','کبودرآهنگ','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5072,30,130013,'Kabodarahang','کبودرآهنگ','کبودرآهنگ','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5073,30,130014,'Goltapeh','گل تپه','کبودرآهنگ','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5074,30,130015,'Azandaryan','ازندریان','ملایر','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5075,30,130016,'Jokar','جوکار','ملایر','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5076,30,130017,'Zanganeh','زنگنه','ملایر','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5077,30,130018,'Samen','سامن','ملایر','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5078,30,130019,'Malayer','ملایر','ملایر','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5079,30,130020,'Barzool','برزول','نهاوند','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5080,30,130021,'Firoozan','فیروزان','نهاوند','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5081,30,130022,'Giyan','گیان','نهاوند','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5082,30,130023,'Nahavand','نهاوند','نهاوند','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5083,30,130024,'Jorghan','جورقان','همدان','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5084,30,130025,'Ghahavand','قهاوند','همدان','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5085,30,130026,'Meryanj','مریانج','همدان','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5086,30,130027,'Hamedan','همدان','همدان','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5087,31,131001,'Abarkooh','ابرکوه','ابرکوه','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5088,31,131002,'Mehrdasht','مهردشت','ابرکوه','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5089,31,131003,'AhmadAbad','احمدآباد','اردکان','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5090,31,131004,'Ardakan','اردکان','اردکان','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5091,31,131005,'Aghda','عقدا','اردکان','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5092,31,131006,'Bafgh','بافق','بافق','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5093,31,131007,'BehAbad','بهاباد','بهاباد','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5094,31,131008,'Taft','تفت','تفت','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5095,31,131009,'Nayer','نیر','تفت','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5096,31,131010,'Marvast','مروست','خاتم','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5097,31,131011,'Harat','هرات','خاتم','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5098,31,131012,'Ashkezar','اشکذر','صدوق','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5099,31,131013,'KhezrAbad','خضرآباد','صدوق','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5100,31,131014,'Nadoshan','ندوشن','صدوق','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5101,31,131018,'Mehriz','مهریز','مهریز','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5102,31,131019,'Bafrooeeyeh','بفروییه','میبد','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5103,31,131020,'Meibod','میبد','میبد','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5104,31,131021,'Hamidia','حمیدیا','یزد','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5105,31,131022,'Zarch','زارچ','یزد','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5106,31,131023,'Shahedie','شاهدیه','یزد','2026-09-17 05:58:48','2026-09-17 05:58:48'),(5107,31,131024,'Yazd','یزد','یزد','2026-09-17 05:58:48','2026-09-17 05:58:48');
/*!40000 ALTER TABLE `geo_cities` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `geo_states`
--

DROP TABLE IF EXISTS `geo_states`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `geo_states` (
  `id` bigint(20) unsigned NOT NULL,
  `code` int(10) unsigned DEFAULT NULL,
  `title` varchar(80) NOT NULL,
  `slug` varchar(20) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `geo_states`
--

LOCK TABLES `geo_states` WRITE;
/*!40000 ALTER TABLE `geo_states` DISABLE KEYS */;
INSERT INTO `geo_states` VALUES (1,1,'آذربایجان شرقی','AZE','2026-09-17 05:58:45','2026-09-17 05:58:45'),(2,2,'آذربایجان غربی','AZW','2026-09-17 05:58:45','2026-09-17 05:58:45'),(3,3,'اردبیل','ARD','2026-09-17 05:58:45','2026-09-17 05:58:45'),(4,4,'اصفهان','ESF','2026-09-17 05:58:45','2026-09-17 05:58:45'),(5,5,'البرز','ALB','2026-09-17 05:58:45','2026-09-17 05:58:45'),(6,6,'ایلام','EIL','2026-09-17 05:58:45','2026-09-17 05:58:45'),(7,7,'بوشهر','BSH','2026-09-17 05:58:45','2026-09-17 05:58:45'),(8,8,'تهران','THR','2026-09-17 05:58:45','2026-09-17 05:58:45'),(9,9,'چهارمحال و بختیاری','CHB','2026-09-17 05:58:45','2026-09-17 05:58:45'),(10,10,'خراسان جنوبی','KHS','2026-09-17 05:58:45','2026-09-17 05:58:45'),(11,11,'خراسان رضوی','KHR','2026-09-17 05:58:45','2026-09-17 05:58:45'),(12,12,'خراسان شمالی','KHN','2026-09-17 05:58:45','2026-09-17 05:58:45'),(13,13,'خوزستان','KHO','2026-09-17 05:58:45','2026-09-17 05:58:45'),(14,14,'زنجان','ZNJ','2026-09-17 05:58:45','2026-09-17 05:58:45'),(15,15,'سمنان','SEM','2026-09-17 05:58:45','2026-09-17 05:58:45'),(16,16,'سیستان و بلوچستان','SBA','2026-09-17 05:58:45','2026-09-17 05:58:45'),(17,17,'فارس','FAR','2026-09-17 05:58:45','2026-09-17 05:58:45'),(18,18,'قزوین','QAZ','2026-09-17 05:58:45','2026-09-17 05:58:45'),(19,19,'قم','QOM','2026-09-17 05:58:45','2026-09-17 05:58:45'),(20,20,'کردستان','KRD','2026-09-17 05:58:45','2026-09-17 05:58:45'),(21,21,'کرمان','KRM','2026-09-17 05:58:45','2026-09-17 05:58:45'),(22,22,'کرمانشاه','KSH','2026-09-17 05:58:45','2026-09-17 05:58:45'),(23,23,'کهگیلویه و بویراحمد','KOB','2026-09-17 05:58:45','2026-09-17 05:58:45'),(24,24,'گلستان','GOL','2026-09-17 05:58:45','2026-09-17 05:58:45'),(25,25,'گیلان','GIL','2026-09-17 05:58:45','2026-09-17 05:58:45'),(26,26,'لرستان','LOR','2026-09-17 05:58:45','2026-09-17 05:58:45'),(27,27,'مازندران','MAZ','2026-09-17 05:58:45','2026-09-17 05:58:45'),(28,28,'مرکزی','MAR','2026-09-17 05:58:45','2026-09-17 05:58:45'),(29,29,'هرمزگان','HOR','2026-09-17 05:58:45','2026-09-17 05:58:45'),(30,30,'همدان','HAM','2026-09-17 05:58:45','2026-09-17 05:58:45'),(31,31,'یزد','YAZ','2026-09-17 05:58:45','2026-09-17 05:58:45');
/*!40000 ALTER TABLE `geo_states` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `job_batches`
--

DROP TABLE IF EXISTS `job_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `job_batches` (
  `id` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `total_jobs` int(11) NOT NULL,
  `pending_jobs` int(11) NOT NULL,
  `failed_jobs` int(11) NOT NULL,
  `failed_job_ids` longtext NOT NULL,
  `options` mediumtext DEFAULT NULL,
  `cancelled_at` int(11) DEFAULT NULL,
  `created_at` int(11) NOT NULL,
  `finished_at` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `job_batches`
--

LOCK TABLES `job_batches` WRITE;
/*!40000 ALTER TABLE `job_batches` DISABLE KEYS */;
/*!40000 ALTER TABLE `job_batches` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `jobs`
--

DROP TABLE IF EXISTS `jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `queue` varchar(255) NOT NULL,
  `payload` longtext NOT NULL,
  `attempts` tinyint(3) unsigned NOT NULL,
  `reserved_at` int(10) unsigned DEFAULT NULL,
  `available_at` int(10) unsigned NOT NULL,
  `created_at` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `jobs_queue_index` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `jobs`
--

LOCK TABLES `jobs` WRITE;
/*!40000 ALTER TABLE `jobs` DISABLE KEYS */;
/*!40000 ALTER TABLE `jobs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `manager_feedback`
--

DROP TABLE IF EXISTS `manager_feedback`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `manager_feedback` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `promotion_request_id` bigint(20) unsigned NOT NULL,
  `reviewer_user_id` bigint(20) unsigned NOT NULL,
  `decision` varchar(255) NOT NULL,
  `note` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `manager_feedback_promotion_request_id_foreign` (`promotion_request_id`),
  KEY `manager_feedback_reviewer_user_id_foreign` (`reviewer_user_id`),
  CONSTRAINT `manager_feedback_promotion_request_id_foreign` FOREIGN KEY (`promotion_request_id`) REFERENCES `promotion_requests` (`id`) ON DELETE CASCADE,
  CONSTRAINT `manager_feedback_reviewer_user_id_foreign` FOREIGN KEY (`reviewer_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `manager_feedback`
--

LOCK TABLES `manager_feedback` WRITE;
/*!40000 ALTER TABLE `manager_feedback` DISABLE KEYS */;
/*!40000 ALTER TABLE `manager_feedback` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `message_reads`
--

DROP TABLE IF EXISTS `message_reads`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `message_reads` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `message_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `read_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `message_reads_message_id_user_id_unique` (`message_id`,`user_id`),
  KEY `message_reads_user_id_foreign` (`user_id`),
  CONSTRAINT `message_reads_message_id_foreign` FOREIGN KEY (`message_id`) REFERENCES `messages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `message_reads_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `message_reads`
--

LOCK TABLES `message_reads` WRITE;
/*!40000 ALTER TABLE `message_reads` DISABLE KEYS */;
/*!40000 ALTER TABLE `message_reads` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `messages`
--

DROP TABLE IF EXISTS `messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `messages` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `conversation_id` bigint(20) unsigned NOT NULL,
  `sender_user_id` bigint(20) unsigned NOT NULL,
  `body` text DEFAULT NULL,
  `message_type` varchar(255) NOT NULL DEFAULT 'text',
  `attachment` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`attachment`)),
  `edited_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `messages_sender_user_id_foreign` (`sender_user_id`),
  KEY `messages_conversation_id_created_at_index` (`conversation_id`,`created_at`),
  CONSTRAINT `messages_conversation_id_foreign` FOREIGN KEY (`conversation_id`) REFERENCES `conversations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `messages_sender_user_id_foreign` FOREIGN KEY (`sender_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `messages`
--

LOCK TABLES `messages` WRITE;
/*!40000 ALTER TABLE `messages` DISABLE KEYS */;
/*!40000 ALTER TABLE `messages` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `migrations`
--

DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `migrations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) NOT NULL,
  `batch` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `migrations`
--

LOCK TABLES `migrations` WRITE;
/*!40000 ALTER TABLE `migrations` DISABLE KEYS */;
INSERT INTO `migrations` VALUES (1,'0001_01_01_000000_create_users_table',1),(2,'0001_01_01_000001_create_cache_table',1),(3,'0001_01_01_000002_create_jobs_table',1),(4,'2026_01_01_000100_create_finopal_core_tables',1),(5,'2026_01_01_000200_create_finopal_finance_tables',1),(6,'2026_01_01_000300_create_finopal_ops_tables',1),(7,'2026_09_12_000400_add_user_avatar_and_customer_kyc',1),(8,'2026_09_14_000400_add_course_level_content_and_page_permissions',1),(9,'2026_09_14_000500_create_geo_tables',1),(10,'2026_09_14_000600_add_gateway_sale_reviews',1),(11,'2026_09_14_000700_add_merchant_code_and_finopal_transactions',1);
/*!40000 ALTER TABLE `migrations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `notifications`
--

DROP TABLE IF EXISTS `notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `notifications` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `type` varchar(255) NOT NULL,
  `title` varchar(255) NOT NULL,
  `body` text DEFAULT NULL,
  `data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`data`)),
  `read_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `notifications_user_id_read_at_index` (`user_id`,`read_at`),
  CONSTRAINT `notifications_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=29 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `notifications`
--

LOCK TABLES `notifications` WRITE;
/*!40000 ALTER TABLE `notifications` DISABLE KEYS */;
INSERT INTO `notifications` VALUES (1,10,'shared_link.approval','تایید لینک اشتراکی','نماینده اشتراکی الف شما را به یک لینک اشتراکی دعوت کرده است.','{\"shared_link_id\":1,\"token\":\"DK9IefkeGxJQjCYMu7YaSHOJNLxD1abq87PmsqNU\",\"path\":\"referrals\"}',NULL,'2026-09-17 05:58:30','2026-09-17 05:58:30'),(2,6,'gateway.transaction','تبریک! پورسانت شما واریز شد','از فروش موفق درگاه «درگاه انفرادی نماینده»، مبلغ 150,000 تومان سود سهم شما به کیف پول نقش‌تان واریز شد. دمتون گرم — همین‌طور ادامه بدید!','{\"gateway_sale_id\":1,\"finopal_transaction_id\":1,\"commission_amount\":\"150000.000\",\"path\":\"commissions\"}',NULL,'2026-09-17 05:58:39','2026-09-17 05:58:39'),(3,5,'gateway.transaction','تبریک! پورسانت شما واریز شد','از فروش موفق درگاه «درگاه انفرادی نماینده»، مبلغ 20,000 تومان سود سهم شما به کیف پول نقش‌تان واریز شد. دمتون گرم — همین‌طور ادامه بدید!','{\"gateway_sale_id\":1,\"finopal_transaction_id\":1,\"commission_amount\":\"20000.000\",\"path\":\"commissions\"}',NULL,'2026-09-17 05:58:39','2026-09-17 05:58:39'),(4,2,'gateway.transaction','تبریک! پورسانت شما واریز شد','از فروش موفق درگاه «درگاه انفرادی نماینده»، مبلغ 40,000 تومان سود سهم شما به کیف پول نقش‌تان واریز شد. دمتون گرم — همین‌طور ادامه بدید!','{\"gateway_sale_id\":1,\"finopal_transaction_id\":1,\"commission_amount\":\"40000.000\",\"path\":\"commissions\"}',NULL,'2026-09-17 05:58:39','2026-09-17 05:58:39'),(5,3,'gateway.transaction','تبریک! پورسانت شما واریز شد','از فروش موفق درگاه «درگاه انفرادی نماینده»، مبلغ 45,000 تومان سود سهم شما به کیف پول نقش‌تان واریز شد. دمتون گرم — همین‌طور ادامه بدید!','{\"gateway_sale_id\":1,\"finopal_transaction_id\":1,\"commission_amount\":\"45000.000\",\"path\":\"commissions\"}',NULL,'2026-09-17 05:58:39','2026-09-17 05:58:39'),(6,4,'gateway.transaction','تبریک! پورسانت شما واریز شد','از فروش موفق درگاه «درگاه انفرادی نماینده»، مبلغ 60,000 تومان سود سهم شما به کیف پول نقش‌تان واریز شد. دمتون گرم — همین‌طور ادامه بدید!','{\"gateway_sale_id\":1,\"finopal_transaction_id\":1,\"commission_amount\":\"60000.000\",\"path\":\"commissions\"}',NULL,'2026-09-17 05:58:39','2026-09-17 05:58:39'),(7,9,'gateway.transaction','تبریک! پورسانت شما واریز شد','از فروش موفق درگاه «درگاه اشتراکی ۵۰-۵۰»، مبلغ 150,000 تومان سود سهم شما به کیف پول نقش‌تان واریز شد. دمتون گرم — همین‌طور ادامه بدید!','{\"gateway_sale_id\":2,\"finopal_transaction_id\":2,\"commission_amount\":\"150000.000\",\"path\":\"commissions\"}',NULL,'2026-09-17 05:58:42','2026-09-17 05:58:42'),(8,10,'gateway.transaction','تبریک! پورسانت شما واریز شد','از فروش موفق درگاه «درگاه اشتراکی ۵۰-۵۰»، مبلغ 150,000 تومان سود سهم شما به کیف پول نقش‌تان واریز شد. دمتون گرم — همین‌طور ادامه بدید!','{\"gateway_sale_id\":2,\"finopal_transaction_id\":2,\"commission_amount\":\"150000.000\",\"path\":\"commissions\"}',NULL,'2026-09-17 05:58:42','2026-09-17 05:58:42'),(9,5,'gateway.transaction','تبریک! پورسانت شما واریز شد','از فروش موفق درگاه «درگاه اشتراکی ۵۰-۵۰»، مبلغ 40,000 تومان سود سهم شما به کیف پول نقش‌تان واریز شد. دمتون گرم — همین‌طور ادامه بدید!','{\"gateway_sale_id\":2,\"finopal_transaction_id\":2,\"commission_amount\":\"40000.000\",\"path\":\"commissions\"}',NULL,'2026-09-17 05:58:42','2026-09-17 05:58:42'),(10,2,'gateway.transaction','تبریک! پورسانت شما واریز شد','از فروش موفق درگاه «درگاه اشتراکی ۵۰-۵۰»، مبلغ 80,000 تومان سود سهم شما به کیف پول نقش‌تان واریز شد. دمتون گرم — همین‌طور ادامه بدید!','{\"gateway_sale_id\":2,\"finopal_transaction_id\":2,\"commission_amount\":\"80000.000\",\"path\":\"commissions\"}',NULL,'2026-09-17 05:58:42','2026-09-17 05:58:42'),(11,3,'gateway.transaction','تبریک! پورسانت شما واریز شد','از فروش موفق درگاه «درگاه اشتراکی ۵۰-۵۰»، مبلغ 90,000 تومان سود سهم شما به کیف پول نقش‌تان واریز شد. دمتون گرم — همین‌طور ادامه بدید!','{\"gateway_sale_id\":2,\"finopal_transaction_id\":2,\"commission_amount\":\"90000.000\",\"path\":\"commissions\"}',NULL,'2026-09-17 05:58:42','2026-09-17 05:58:42'),(12,4,'gateway.transaction','تبریک! پورسانت شما واریز شد','از فروش موفق درگاه «درگاه اشتراکی ۵۰-۵۰»، مبلغ 120,000 تومان سود سهم شما به کیف پول نقش‌تان واریز شد. دمتون گرم — همین‌طور ادامه بدید!','{\"gateway_sale_id\":2,\"finopal_transaction_id\":2,\"commission_amount\":\"120000.000\",\"path\":\"commissions\"}',NULL,'2026-09-17 05:58:42','2026-09-17 05:58:42'),(13,8,'gateway.transaction','تبریک! پورسانت شما واریز شد','از فروش موفق درگاه «درگاه کاربر چندنقشی ب»، مبلغ 270,000 تومان سود سهم شما به کیف پول نقش‌تان واریز شد. دمتون گرم — همین‌طور ادامه بدید!','{\"gateway_sale_id\":3,\"finopal_transaction_id\":3,\"commission_amount\":\"270000.000\",\"path\":\"commissions\"}',NULL,'2026-09-17 05:58:42','2026-09-17 05:58:42'),(14,2,'gateway.transaction','تبریک! پورسانت شما واریز شد','از فروش موفق درگاه «درگاه کاربر چندنقشی ب»، مبلغ 261,000 تومان سود سهم شما به کیف پول نقش‌تان واریز شد. دمتون گرم — همین‌طور ادامه بدید!','{\"gateway_sale_id\":3,\"finopal_transaction_id\":3,\"commission_amount\":\"261000.000\",\"path\":\"commissions\"}',NULL,'2026-09-17 05:58:42','2026-09-17 05:58:42'),(15,8,'system.info','موجودی و پورسانت آماده انتقال','برای این حساب پورسانت درگاه، موجودی کیف پول و فعالیت ثبت شده تا انتقال مزایا قابل مشاهده باشد.','{\"demo\":true,\"path\":\"wallet\"}',NULL,'2026-09-17 05:58:44','2026-09-17 05:58:44'),(16,2,'promotion.pending','درخواست ارتقاء جدید','نماینده اشتراکی ب واجد بررسی ارتقاء به مدیر فروش است.','{\"user_id\":10,\"promotion_id\":1,\"path\":\"promotions\"}',NULL,'2026-09-17 05:58:44','2026-09-17 05:58:44'),(17,2,'promotion.pending','درخواست ارتقاء جدید','شاخه جدا واجد بررسی ارتقاء به مدیر فروش است.','{\"user_id\":11,\"promotion_id\":2,\"path\":\"promotions\"}',NULL,'2026-09-17 05:58:44','2026-09-17 05:58:44'),(18,2,'promotion.pending','درخواست ارتقاء جدید','نماینده معرف واجد بررسی ارتقاء به مدیر فروش است.','{\"user_id\":5,\"promotion_id\":3,\"path\":\"promotions\"}',NULL,'2026-09-17 05:58:44','2026-09-17 05:58:44'),(19,2,'promotion.pending','درخواست ارتقاء جدید','نماینده اصلی واجد بررسی ارتقاء به مدیر فروش است.','{\"user_id\":6,\"promotion_id\":4,\"path\":\"promotions\"}',NULL,'2026-09-17 05:58:44','2026-09-17 05:58:44'),(20,2,'promotion.pending','درخواست ارتقاء جدید','نماینده اشتراکی الف واجد بررسی ارتقاء به مدیر فروش است.','{\"user_id\":9,\"promotion_id\":5,\"path\":\"promotions\"}',NULL,'2026-09-17 05:58:44','2026-09-17 05:58:44'),(21,2,'promotion.pending','درخواست ارتقاء جدید','مدیر فروش واجد بررسی ارتقاء به مدیر توسعه است.','{\"user_id\":4,\"promotion_id\":6,\"path\":\"promotions\"}',NULL,'2026-09-17 05:58:44','2026-09-17 05:58:44'),(22,2,'promotion.pending','ارتقاء مدیر فروش','مدیر فروش درخواست ارتقاء به مدیر توسعه ثبت کرده است.','{\"demo\":true,\"path\":\"promotions\"}',NULL,'2026-09-17 05:28:44','2026-09-17 05:28:44'),(23,2,'withdrawal.pending','برداشت در انتظار تایید','یک درخواست برداشت از شبکه شما به مرحله مدیر ارشد رسیده است.','{\"demo\":true,\"path\":\"withdrawals\"}',NULL,'2026-09-17 05:13:44','2026-09-17 05:13:44'),(24,2,'training.progress','پیشرفت آموزش شبکه','چند نفر از زیرمجموعه‌ها دوره آموزش سازمان فروش را تکمیل کرده‌اند.','{\"demo\":true,\"path\":\"training\"}',NULL,'2026-09-17 04:58:44','2026-09-17 04:58:44'),(25,2,'shared_link.approval','لینک اشتراکی جدید','یک لینک فروش سه‌نفره در شبکه ایجاد شده و منتظر تایید اعضاست.','{\"demo\":true,\"path\":\"referrals\"}','2026-09-17 00:58:44','2026-09-17 04:43:44','2026-09-17 04:43:44'),(26,2,'system.info','راهنمای پنل مدیر ارشد','از صفحات ارتقاء، آموزش و برداشت می‌توانید شبکه را بررسی و تصمیم بگیرید.','{\"demo\":true}',NULL,'2026-09-17 04:28:44','2026-09-17 04:28:44'),(27,2,'gateway.submitted','درگاه جدید برای بازرسی','درگاه «درگاه در انتظار بازرسی» ثبت شد و منتظر تایید مدیر ارشد است. تا ثبت کد مرچنت و تراکنش موفق، پورسانتی واریز نمی‌شود.','{\"gateway_sale_id\":6,\"path\":\"gateways\"}',NULL,'2026-09-17 05:58:44','2026-09-17 05:58:44'),(28,2,'gateway.submitted','درگاه جدید برای بازرسی','درگاه «درگاه دوم در انتظار تایید» ثبت شد و منتظر تایید مدیر ارشد است. تا ثبت کد مرچنت و تراکنش موفق، پورسانتی واریز نمی‌شود.','{\"gateway_sale_id\":7,\"path\":\"gateways\"}',NULL,'2026-09-17 05:58:45','2026-09-17 05:58:45');
/*!40000 ALTER TABLE `notifications` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `organization_nodes`
--

DROP TABLE IF EXISTS `organization_nodes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `organization_nodes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `parent_node_id` bigint(20) unsigned DEFAULT NULL,
  `role_id` bigint(20) unsigned NOT NULL,
  `path` varchar(255) DEFAULT NULL,
  `effective_from` date NOT NULL,
  `effective_to` date DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `organization_nodes_role_id_foreign` (`role_id`),
  KEY `organization_nodes_user_id_role_id_is_active_index` (`user_id`,`role_id`,`is_active`),
  KEY `organization_nodes_parent_node_id_is_active_index` (`parent_node_id`,`is_active`),
  KEY `organization_nodes_path_index` (`path`),
  CONSTRAINT `organization_nodes_parent_node_id_foreign` FOREIGN KEY (`parent_node_id`) REFERENCES `organization_nodes` (`id`) ON DELETE SET NULL,
  CONSTRAINT `organization_nodes_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `organization_nodes_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `organization_nodes`
--

LOCK TABLES `organization_nodes` WRITE;
/*!40000 ALTER TABLE `organization_nodes` DISABLE KEYS */;
INSERT INTO `organization_nodes` VALUES (1,2,NULL,1,'/1/','2025-09-17',NULL,1,'2026-09-17 05:58:30','2026-09-17 05:58:30'),(2,3,1,2,'/1/2/','2025-09-17',NULL,1,'2026-09-17 05:58:30','2026-09-17 05:58:30'),(3,4,2,3,'/1/2/3/','2025-09-17',NULL,1,'2026-09-17 05:58:30','2026-09-17 05:58:30'),(4,5,3,5,'/1/2/3/4/','2026-01-17',NULL,1,'2026-09-17 05:58:30','2026-09-17 05:58:30'),(5,6,3,5,'/1/2/3/5/','2026-03-17',NULL,1,'2026-09-17 05:58:30','2026-09-17 05:58:30'),(6,7,2,3,'/1/2/6/','2025-11-17',NULL,1,'2026-09-17 05:58:30','2026-09-17 05:58:30'),(7,8,2,3,'/1/2/7/','2025-12-17',NULL,1,'2026-09-17 05:58:30','2026-09-17 05:58:30'),(8,9,3,5,'/1/2/3/8/','2026-06-17',NULL,1,'2026-09-17 05:58:30','2026-09-17 05:58:30'),(9,10,3,5,'/1/2/3/9/','2026-06-17',NULL,1,'2026-09-17 05:58:30','2026-09-17 05:58:30'),(10,11,1,5,'/1/10/','2026-07-17',NULL,1,'2026-09-17 05:58:30','2026-09-17 05:58:30'),(11,2,1,2,'/1/11/','2026-09-17',NULL,1,'2026-09-17 05:58:30','2026-09-17 05:58:30'),(12,2,11,3,'/1/11/12/','2026-09-17',NULL,1,'2026-09-17 05:58:30','2026-09-17 05:58:30');
/*!40000 ALTER TABLE `organization_nodes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `password_reset_tokens`
--

DROP TABLE IF EXISTS `password_reset_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) NOT NULL,
  `token` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `password_reset_tokens`
--

LOCK TABLES `password_reset_tokens` WRITE;
/*!40000 ALTER TABLE `password_reset_tokens` DISABLE KEYS */;
/*!40000 ALTER TABLE `password_reset_tokens` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `permissions`
--

DROP TABLE IF EXISTS `permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `permissions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `panel` varchar(255) NOT NULL,
  `module` varchar(255) NOT NULL,
  `action` varchar(255) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `permissions_panel_module_action_unique` (`panel`,`module`,`action`),
  UNIQUE KEY `permissions_name_unique` (`name`),
  UNIQUE KEY `permissions_slug_unique` (`slug`)
) ENGINE=InnoDB AUTO_INCREMENT=28 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `permissions`
--

LOCK TABLES `permissions` WRITE;
/*!40000 ALTER TABLE `permissions` DISABLE KEYS */;
INSERT INTO `permissions` VALUES (1,'صفحه داشبورد','page.dashboard','page','dashboard','view',1,'2026-09-17 05:58:23','2026-09-17 05:58:23'),(2,'صفحه شبکه و تیم','page.team','page','team','view',1,'2026-09-17 05:58:23','2026-09-17 05:58:23'),(3,'صفحه درگاه‌ها','page.gateways','page','gateways','view',1,'2026-09-17 05:58:23','2026-09-17 05:58:23'),(4,'صفحه پورسانت','page.commissions','page','commissions','view',1,'2026-09-17 05:58:23','2026-09-17 05:58:23'),(5,'صفحه کیف پول','page.wallet','page','wallet','view',1,'2026-09-17 05:58:23','2026-09-17 05:58:23'),(6,'صفحه گزارش تجمیعی','page.finance','page','finance','view',1,'2026-09-17 05:58:23','2026-09-17 05:58:23'),(7,'صفحه برداشت','page.withdrawals','page','withdrawals','view',1,'2026-09-17 05:58:23','2026-09-17 05:58:23'),(8,'صفحه انتقال مالکیت مزایا','page.transfers','page','transfers','view',1,'2026-09-17 05:58:23','2026-09-17 05:58:23'),(9,'صفحه لینک معرف و اشتراکی','page.referrals','page','referrals','view',1,'2026-09-17 05:58:23','2026-09-17 05:58:23'),(10,'صفحه ارتقاء سمت','page.promotions','page','promotions','view',1,'2026-09-17 05:58:23','2026-09-17 05:58:23'),(11,'صفحه آموزش','page.training','page','training','view',1,'2026-09-17 05:58:23','2026-09-17 05:58:23'),(12,'مدیریت دوره‌ها و سطوح','page.courses_manage','page','courses_manage','view',1,'2026-09-17 05:58:23','2026-09-17 05:58:23'),(13,'صفحه گفتگو','page.chat','page','chat','view',1,'2026-09-17 05:58:23','2026-09-17 05:58:23'),(14,'صفحه اعلان‌ها','page.notifications','page','notifications','view',1,'2026-09-17 05:58:23','2026-09-17 05:58:23'),(15,'مدیر ارشد / تایید درگاه و ثبت کد مرچنت فاینوپال','senior_manager.gateway.inspect','senior_manager','gateway','inspect',1,'2026-09-17 05:58:23','2026-09-17 05:58:23'),(16,'representative.gateway.view','representative.gateway.view','representative','gateway','view',1,'2026-09-17 05:58:24','2026-09-17 05:58:24'),(17,'representative.gateway.create','representative.gateway.create','representative','gateway','create',1,'2026-09-17 05:58:24','2026-09-17 05:58:24'),(18,'representative_referrer.team.view','representative_referrer.team.view','representative_referrer','team','view',1,'2026-09-17 05:58:24','2026-09-17 05:58:24'),(19,'sales_manager.team.view','sales_manager.team.view','sales_manager','team','view',1,'2026-09-17 05:58:24','2026-09-17 05:58:24'),(20,'sales_manager.team.message','sales_manager.team.message','sales_manager','team','message',1,'2026-09-17 05:58:24','2026-09-17 05:58:24'),(21,'development_manager.team.view','development_manager.team.view','development_manager','team','view',1,'2026-09-17 05:58:24','2026-09-17 05:58:24'),(22,'senior_manager.withdrawal.approve','senior_manager.withdrawal.approve','senior_manager','withdrawal','approve',1,'2026-09-17 05:58:24','2026-09-17 05:58:24'),(23,'senior_manager.benefit_transfer.create','senior_manager.benefit_transfer.create','senior_manager','benefit_transfer','create',1,'2026-09-17 05:58:24','2026-09-17 05:58:24'),(24,'senior_manager.promotion.decide','senior_manager.promotion.decide','senior_manager','promotion','decide',1,'2026-09-17 05:58:24','2026-09-17 05:58:24'),(25,'superuser.commission_rules.update','superuser.commission_rules.update','superuser','commission_rules','update',1,'2026-09-17 05:58:24','2026-09-17 05:58:24'),(26,'superuser.gateway.create','superuser.gateway.create','superuser','gateway','create',1,'2026-09-17 05:58:24','2026-09-17 05:58:24'),(27,'chat.cross_branch.message','chat.cross_branch.message','chat','cross_branch','message',1,'2026-09-17 05:58:24','2026-09-17 05:58:24');
/*!40000 ALTER TABLE `permissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `personal_access_tokens`
--

DROP TABLE IF EXISTS `personal_access_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `personal_access_tokens` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `name` varchar(255) NOT NULL DEFAULT 'api',
  `token` varchar(64) NOT NULL,
  `active_role_id` bigint(20) unsigned DEFAULT NULL,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `personal_access_tokens_token_unique` (`token`),
  KEY `personal_access_tokens_user_id_foreign` (`user_id`),
  CONSTRAINT `personal_access_tokens_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `personal_access_tokens`
--

LOCK TABLES `personal_access_tokens` WRITE;
/*!40000 ALTER TABLE `personal_access_tokens` DISABLE KEYS */;
/*!40000 ALTER TABLE `personal_access_tokens` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `promotion_criteria_results`
--

DROP TABLE IF EXISTS `promotion_criteria_results`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `promotion_criteria_results` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `promotion_request_id` bigint(20) unsigned NOT NULL,
  `criterion_code` varchar(255) NOT NULL,
  `required_value` decimal(18,3) DEFAULT NULL,
  `actual_value` decimal(18,3) DEFAULT NULL,
  `passed` tinyint(1) NOT NULL DEFAULT 0,
  `evidence` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`evidence`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `promotion_criteria_results_promotion_request_id_foreign` (`promotion_request_id`),
  CONSTRAINT `promotion_criteria_results_promotion_request_id_foreign` FOREIGN KEY (`promotion_request_id`) REFERENCES `promotion_requests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=33 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `promotion_criteria_results`
--

LOCK TABLES `promotion_criteria_results` WRITE;
/*!40000 ALTER TABLE `promotion_criteria_results` DISABLE KEYS */;
INSERT INTO `promotion_criteria_results` VALUES (1,1,'personal_points',10000.000,50.000,0,'{\"code\":\"personal_points\",\"required\":10000,\"actual\":50,\"passed\":false}','2026-09-17 05:58:44','2026-09-17 05:58:44'),(2,1,'new_representatives',60.000,0.000,0,'{\"code\":\"new_representatives\",\"required\":60,\"actual\":0,\"passed\":false}','2026-09-17 05:58:44','2026-09-17 05:58:44'),(3,1,'strong_representatives',24.000,0.000,0,'{\"code\":\"strong_representatives\",\"required\":24,\"actual\":0,\"passed\":false}','2026-09-17 05:58:44','2026-09-17 05:58:44'),(4,1,'required_training',2.000,0.000,0,'{\"code\":\"required_training\",\"required\":2,\"actual\":0,\"passed\":false}','2026-09-17 05:58:44','2026-09-17 05:58:44'),(5,1,'senior_assessment',1.000,0.000,0,'{\"code\":\"senior_assessment\",\"required\":1,\"actual\":0,\"passed\":false}','2026-09-17 05:58:44','2026-09-17 05:58:44'),(6,2,'personal_points',10000.000,0.000,0,'{\"code\":\"personal_points\",\"required\":10000,\"actual\":0,\"passed\":false}','2026-09-17 05:58:44','2026-09-17 05:58:44'),(7,2,'new_representatives',60.000,0.000,0,'{\"code\":\"new_representatives\",\"required\":60,\"actual\":0,\"passed\":false}','2026-09-17 05:58:44','2026-09-17 05:58:44'),(8,2,'strong_representatives',24.000,0.000,0,'{\"code\":\"strong_representatives\",\"required\":24,\"actual\":0,\"passed\":false}','2026-09-17 05:58:44','2026-09-17 05:58:44'),(9,2,'required_training',2.000,0.000,0,'{\"code\":\"required_training\",\"required\":2,\"actual\":0,\"passed\":false}','2026-09-17 05:58:44','2026-09-17 05:58:44'),(10,2,'senior_assessment',1.000,0.000,0,'{\"code\":\"senior_assessment\",\"required\":1,\"actual\":0,\"passed\":false}','2026-09-17 05:58:44','2026-09-17 05:58:44'),(11,3,'personal_points',10000.000,0.000,0,'{\"code\":\"personal_points\",\"required\":10000,\"actual\":0,\"passed\":false}','2026-09-17 05:58:44','2026-09-17 05:58:44'),(12,3,'new_representatives',60.000,3.000,0,'{\"code\":\"new_representatives\",\"required\":60,\"actual\":3,\"passed\":false}','2026-09-17 05:58:44','2026-09-17 05:58:44'),(13,3,'strong_representatives',24.000,0.000,0,'{\"code\":\"strong_representatives\",\"required\":24,\"actual\":0,\"passed\":false}','2026-09-17 05:58:44','2026-09-17 05:58:44'),(14,3,'required_training',2.000,0.000,0,'{\"code\":\"required_training\",\"required\":2,\"actual\":0,\"passed\":false}','2026-09-17 05:58:44','2026-09-17 05:58:44'),(15,3,'senior_assessment',1.000,0.000,0,'{\"code\":\"senior_assessment\",\"required\":1,\"actual\":0,\"passed\":false}','2026-09-17 05:58:44','2026-09-17 05:58:44'),(16,4,'personal_points',10000.000,100.000,0,'{\"code\":\"personal_points\",\"required\":10000,\"actual\":100,\"passed\":false}','2026-09-17 05:58:44','2026-09-17 05:58:44'),(17,4,'new_representatives',60.000,0.000,0,'{\"code\":\"new_representatives\",\"required\":60,\"actual\":0,\"passed\":false}','2026-09-17 05:58:44','2026-09-17 05:58:44'),(18,4,'strong_representatives',24.000,0.000,0,'{\"code\":\"strong_representatives\",\"required\":24,\"actual\":0,\"passed\":false}','2026-09-17 05:58:44','2026-09-17 05:58:44'),(19,4,'required_training',2.000,0.000,0,'{\"code\":\"required_training\",\"required\":2,\"actual\":0,\"passed\":false}','2026-09-17 05:58:44','2026-09-17 05:58:44'),(20,4,'senior_assessment',1.000,0.000,0,'{\"code\":\"senior_assessment\",\"required\":1,\"actual\":0,\"passed\":false}','2026-09-17 05:58:44','2026-09-17 05:58:44'),(21,5,'personal_points',10000.000,50.000,0,'{\"code\":\"personal_points\",\"required\":10000,\"actual\":50,\"passed\":false}','2026-09-17 05:58:44','2026-09-17 05:58:44'),(22,5,'new_representatives',60.000,0.000,0,'{\"code\":\"new_representatives\",\"required\":60,\"actual\":0,\"passed\":false}','2026-09-17 05:58:44','2026-09-17 05:58:44'),(23,5,'strong_representatives',24.000,0.000,0,'{\"code\":\"strong_representatives\",\"required\":24,\"actual\":0,\"passed\":false}','2026-09-17 05:58:44','2026-09-17 05:58:44'),(24,5,'required_training',2.000,0.000,0,'{\"code\":\"required_training\",\"required\":2,\"actual\":0,\"passed\":false}','2026-09-17 05:58:44','2026-09-17 05:58:44'),(25,5,'senior_assessment',1.000,0.000,0,'{\"code\":\"senior_assessment\",\"required\":1,\"actual\":0,\"passed\":false}','2026-09-17 05:58:44','2026-09-17 05:58:44'),(26,6,'tenure_years',1.000,1.001,1,'{\"code\":\"tenure_years\",\"required\":1,\"actual\":1.001,\"passed\":true}','2026-09-17 05:58:44','2026-09-17 05:58:44'),(27,6,'registered_reps',100.000,0.000,0,'{\"code\":\"registered_reps\",\"required\":100,\"actual\":0,\"passed\":false}','2026-09-17 05:58:44','2026-09-17 05:58:44'),(28,6,'strong_reps',30.000,0.000,0,'{\"code\":\"strong_reps\",\"required\":30,\"actual\":0,\"passed\":false}','2026-09-17 05:58:44','2026-09-17 05:58:44'),(29,6,'team_satisfaction',1.000,0.000,0,'{\"code\":\"team_satisfaction\",\"required\":1,\"actual\":0,\"passed\":false}','2026-09-17 05:58:44','2026-09-17 05:58:44'),(30,6,'eligible_sales_managers',2.000,0.000,0,'{\"code\":\"eligible_sales_managers\",\"required\":2,\"actual\":0,\"passed\":false}','2026-09-17 05:58:44','2026-09-17 05:58:44'),(31,6,'required_training',2.000,0.000,0,'{\"code\":\"required_training\",\"required\":2,\"actual\":0,\"passed\":false}','2026-09-17 05:58:44','2026-09-17 05:58:44'),(32,6,'senior_assessment',1.000,0.000,0,'{\"code\":\"senior_assessment\",\"required\":1,\"actual\":0,\"passed\":false}','2026-09-17 05:58:44','2026-09-17 05:58:44');
/*!40000 ALTER TABLE `promotion_criteria_results` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `promotion_requests`
--

DROP TABLE IF EXISTS `promotion_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `promotion_requests` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `from_role_id` bigint(20) unsigned NOT NULL,
  `target_role_id` bigint(20) unsigned NOT NULL,
  `status` varchar(255) NOT NULL DEFAULT 'pending',
  `submitted_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `decided_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `promotion_requests_user_id_foreign` (`user_id`),
  KEY `promotion_requests_from_role_id_foreign` (`from_role_id`),
  KEY `promotion_requests_target_role_id_foreign` (`target_role_id`),
  CONSTRAINT `promotion_requests_from_role_id_foreign` FOREIGN KEY (`from_role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `promotion_requests_target_role_id_foreign` FOREIGN KEY (`target_role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `promotion_requests_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `promotion_requests`
--

LOCK TABLES `promotion_requests` WRITE;
/*!40000 ALTER TABLE `promotion_requests` DISABLE KEYS */;
INSERT INTO `promotion_requests` VALUES (1,10,5,3,'pending','2026-09-17 05:58:44',NULL,'2026-09-17 05:58:44','2026-09-17 05:58:44'),(2,11,5,3,'pending','2026-09-17 05:58:44',NULL,'2026-09-17 05:58:44','2026-09-17 05:58:44'),(3,5,5,3,'pending','2026-09-17 05:58:44',NULL,'2026-09-17 05:58:44','2026-09-17 05:58:44'),(4,6,5,3,'pending','2026-09-17 05:58:44',NULL,'2026-09-17 05:58:44','2026-09-17 05:58:44'),(5,9,5,3,'pending','2026-09-17 05:58:44',NULL,'2026-09-17 05:58:44','2026-09-17 05:58:44'),(6,4,3,2,'pending','2026-09-17 05:58:44',NULL,'2026-09-17 05:58:44','2026-09-17 05:58:44');
/*!40000 ALTER TABLE `promotion_requests` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `referral_codes`
--

DROP TABLE IF EXISTS `referral_codes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `referral_codes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `code` varchar(255) NOT NULL,
  `source` varchar(255) NOT NULL DEFAULT 'finopal',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `referral_codes_code_unique` (`code`),
  KEY `referral_codes_user_id_foreign` (`user_id`),
  CONSTRAINT `referral_codes_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `referral_codes`
--

LOCK TABLES `referral_codes` WRITE;
/*!40000 ALTER TABLE `referral_codes` DISABLE KEYS */;
INSERT INTO `referral_codes` VALUES (1,1,'SUPERUSERREF','finopal',1,'2026-09-17 05:58:25','2026-09-17 05:58:25'),(2,2,'SENIORREF','finopal',1,'2026-09-17 05:58:26','2026-09-17 05:58:26'),(3,3,'DEVREF','finopal',1,'2026-09-17 05:58:26','2026-09-17 05:58:26'),(4,4,'SALESREF','finopal',1,'2026-09-17 05:58:26','2026-09-17 05:58:26'),(5,5,'REFERRERREF','finopal',1,'2026-09-17 05:58:27','2026-09-17 05:58:27'),(6,6,'REPREF','finopal',1,'2026-09-17 05:58:27','2026-09-17 05:58:27'),(7,7,'MULTIREF','finopal',1,'2026-09-17 05:58:28','2026-09-17 05:58:28'),(8,8,'MULTI_BREF','finopal',1,'2026-09-17 05:58:28','2026-09-17 05:58:28'),(9,9,'SHARE_AREF','finopal',1,'2026-09-17 05:58:29','2026-09-17 05:58:29'),(10,10,'SHARE_BREF','finopal',1,'2026-09-17 05:58:29','2026-09-17 05:58:29'),(11,11,'OUTSIDERREF','finopal',1,'2026-09-17 05:58:30','2026-09-17 05:58:30'),(12,8,'MULTIBREF','finopal',1,'2026-09-17 05:58:44','2026-09-17 05:58:44');
/*!40000 ALTER TABLE `referral_codes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `referral_share_members`
--

DROP TABLE IF EXISTS `referral_share_members`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `referral_share_members` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `referral_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `share_percent` decimal(8,3) NOT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `referral_share_members_referral_id_user_id_unique` (`referral_id`,`user_id`),
  KEY `referral_share_members_user_id_foreign` (`user_id`),
  CONSTRAINT `referral_share_members_referral_id_foreign` FOREIGN KEY (`referral_id`) REFERENCES `representative_referrals` (`id`) ON DELETE CASCADE,
  CONSTRAINT `referral_share_members_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `referral_share_members`
--

LOCK TABLES `referral_share_members` WRITE;
/*!40000 ALTER TABLE `referral_share_members` DISABLE KEYS */;
/*!40000 ALTER TABLE `referral_share_members` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `representative_referrals`
--

DROP TABLE IF EXISTS `representative_referrals`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `representative_referrals` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `referred_user_id` bigint(20) unsigned NOT NULL,
  `referrer_user_id` bigint(20) unsigned NOT NULL,
  `source` varchar(255) NOT NULL DEFAULT 'finopal',
  `referral_code_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `representative_referrals_referred_user_id_unique` (`referred_user_id`),
  KEY `representative_referrals_referrer_user_id_foreign` (`referrer_user_id`),
  KEY `representative_referrals_referral_code_id_foreign` (`referral_code_id`),
  CONSTRAINT `representative_referrals_referral_code_id_foreign` FOREIGN KEY (`referral_code_id`) REFERENCES `referral_codes` (`id`) ON DELETE SET NULL,
  CONSTRAINT `representative_referrals_referred_user_id_foreign` FOREIGN KEY (`referred_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `representative_referrals_referrer_user_id_foreign` FOREIGN KEY (`referrer_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `representative_referrals`
--

LOCK TABLES `representative_referrals` WRITE;
/*!40000 ALTER TABLE `representative_referrals` DISABLE KEYS */;
INSERT INTO `representative_referrals` VALUES (1,2,2,'finopal',2,'2026-09-17 05:58:30','2026-09-17 05:58:30'),(2,6,5,'finopal',5,'2026-09-17 05:58:30','2026-09-17 05:58:30'),(3,9,5,'finopal',5,'2026-09-17 05:58:30','2026-09-17 05:58:30'),(4,10,5,'finopal',5,'2026-09-17 05:58:30','2026-09-17 05:58:30');
/*!40000 ALTER TABLE `representative_referrals` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `role_dashboard_preferences`
--

DROP TABLE IF EXISTS `role_dashboard_preferences`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `role_dashboard_preferences` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `role_id` bigint(20) unsigned NOT NULL,
  `preferences` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`preferences`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `role_dashboard_preferences_user_id_role_id_unique` (`user_id`,`role_id`),
  KEY `role_dashboard_preferences_role_id_foreign` (`role_id`),
  CONSTRAINT `role_dashboard_preferences_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `role_dashboard_preferences_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `role_dashboard_preferences`
--

LOCK TABLES `role_dashboard_preferences` WRITE;
/*!40000 ALTER TABLE `role_dashboard_preferences` DISABLE KEYS */;
/*!40000 ALTER TABLE `role_dashboard_preferences` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `role_permissions`
--

DROP TABLE IF EXISTS `role_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `role_permissions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `role_id` bigint(20) unsigned NOT NULL,
  `permission_id` bigint(20) unsigned NOT NULL,
  `allowed` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `role_permissions_role_id_permission_id_unique` (`role_id`,`permission_id`),
  KEY `role_permissions_permission_id_foreign` (`permission_id`),
  CONSTRAINT `role_permissions_permission_id_foreign` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `role_permissions_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=100 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `role_permissions`
--

LOCK TABLES `role_permissions` WRITE;
/*!40000 ALTER TABLE `role_permissions` DISABLE KEYS */;
INSERT INTO `role_permissions` VALUES (1,5,16,1),(2,6,16,1),(3,5,17,1),(4,6,17,1),(5,4,18,1),(6,6,18,1),(7,3,19,1),(8,6,19,1),(9,3,20,1),(10,6,20,1),(11,2,21,1),(12,6,21,1),(13,1,22,1),(14,6,22,1),(15,1,23,1),(16,6,23,1),(17,1,24,1),(18,6,24,1),(19,6,25,1),(20,6,26,1),(21,6,27,1),(22,1,1,1),(23,2,1,1),(24,3,1,1),(25,4,1,1),(26,5,1,1),(27,6,1,1),(28,1,2,1),(29,2,2,1),(30,3,2,1),(31,4,2,1),(32,5,2,1),(33,6,2,1),(34,1,3,1),(35,2,3,1),(36,3,3,1),(37,4,3,1),(38,5,3,1),(39,6,3,1),(40,1,4,1),(41,2,4,1),(42,3,4,1),(43,4,4,1),(44,5,4,1),(45,6,4,1),(46,1,5,1),(47,2,5,1),(48,3,5,1),(49,4,5,1),(50,5,5,1),(51,6,5,1),(52,1,6,1),(53,2,6,1),(54,3,6,1),(55,4,6,1),(56,5,6,1),(57,6,6,1),(58,1,7,1),(59,2,7,1),(60,3,7,1),(61,4,7,1),(62,5,7,1),(63,6,7,1),(64,1,8,1),(65,6,8,1),(66,1,9,1),(67,2,9,1),(68,3,9,1),(69,4,9,1),(70,5,9,1),(71,6,9,1),(72,1,10,1),(73,2,10,1),(74,3,10,1),(75,4,10,1),(76,5,10,1),(77,6,10,1),(78,1,11,1),(79,2,11,1),(80,3,11,1),(81,4,11,1),(82,5,11,1),(83,6,11,1),(84,1,12,1),(85,6,12,1),(86,1,13,1),(87,2,13,1),(88,3,13,1),(89,4,13,1),(90,5,13,1),(91,6,13,1),(92,1,14,1),(93,2,14,1),(94,3,14,1),(95,4,14,1),(96,5,14,1),(97,6,14,1),(98,1,15,1),(99,6,15,1);
/*!40000 ALTER TABLE `role_permissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `roles`
--

DROP TABLE IF EXISTS `roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `roles` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `hierarchy_level` tinyint(3) unsigned NOT NULL,
  `is_organizational` tinyint(1) NOT NULL DEFAULT 1,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `roles_name_unique` (`name`),
  UNIQUE KEY `roles_slug_unique` (`slug`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `roles`
--

LOCK TABLES `roles` WRITE;
/*!40000 ALTER TABLE `roles` DISABLE KEYS */;
INSERT INTO `roles` VALUES (1,'مدیر ارشد','senior_manager',1,1,1,'2026-09-17 05:58:24','2026-09-17 05:58:24'),(2,'مدیر توسعه','development_manager',2,1,1,'2026-09-17 05:58:24','2026-09-17 05:58:24'),(3,'مدیر فروش','sales_manager',3,1,1,'2026-09-17 05:58:24','2026-09-17 05:58:24'),(4,'نماینده معرف','representative_referrer',4,1,1,'2026-09-17 05:58:24','2026-09-17 05:58:24'),(5,'نماینده','representative',5,1,1,'2026-09-17 05:58:24','2026-09-17 05:58:24'),(6,'مدیر سامانه','superuser',0,0,1,'2026-09-17 05:58:24','2026-09-17 05:58:43');
/*!40000 ALTER TABLE `roles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sessions`
--

DROP TABLE IF EXISTS `sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sessions` (
  `id` varchar(255) NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `payload` longtext NOT NULL,
  `last_activity` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sessions_user_id_index` (`user_id`),
  KEY `sessions_last_activity_index` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sessions`
--

LOCK TABLES `sessions` WRITE;
/*!40000 ALTER TABLE `sessions` DISABLE KEYS */;
/*!40000 ALTER TABLE `sessions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `shared_link_members`
--

DROP TABLE IF EXISTS `shared_link_members`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shared_link_members` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `shared_link_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `share_percent` decimal(8,3) NOT NULL,
  `approved` tinyint(1) NOT NULL DEFAULT 0,
  `approved_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `shared_link_members_shared_link_id_user_id_unique` (`shared_link_id`,`user_id`),
  KEY `shared_link_members_user_id_foreign` (`user_id`),
  CONSTRAINT `shared_link_members_shared_link_id_foreign` FOREIGN KEY (`shared_link_id`) REFERENCES `shared_links` (`id`) ON DELETE CASCADE,
  CONSTRAINT `shared_link_members_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `shared_link_members`
--

LOCK TABLES `shared_link_members` WRITE;
/*!40000 ALTER TABLE `shared_link_members` DISABLE KEYS */;
INSERT INTO `shared_link_members` VALUES (1,1,9,50.000,1,'2026-09-17 05:58:30','2026-09-17 05:58:30','2026-09-17 05:58:30'),(2,1,10,50.000,1,'2026-09-17 05:58:30','2026-09-17 05:58:30','2026-09-17 05:58:30');
/*!40000 ALTER TABLE `shared_link_members` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `shared_links`
--

DROP TABLE IF EXISTS `shared_links`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shared_links` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `creator_user_id` bigint(20) unsigned NOT NULL,
  `token` varchar(255) NOT NULL,
  `type` varchar(255) NOT NULL,
  `status` varchar(255) NOT NULL DEFAULT 'pending',
  `expires_at` timestamp NULL DEFAULT NULL,
  `used_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `shared_links_token_unique` (`token`),
  KEY `shared_links_creator_user_id_foreign` (`creator_user_id`),
  CONSTRAINT `shared_links_creator_user_id_foreign` FOREIGN KEY (`creator_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `shared_links`
--

LOCK TABLES `shared_links` WRITE;
/*!40000 ALTER TABLE `shared_links` DISABLE KEYS */;
INSERT INTO `shared_links` VALUES (1,9,'DK9IefkeGxJQjCYMu7YaSHOJNLxD1abq87PmsqNU','gateway_sale','used','2026-09-24 05:58:30','2026-09-17 05:58:38','2026-09-17 05:58:30','2026-09-17 05:58:38');
/*!40000 ALTER TABLE `shared_links` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `system_settings`
--

DROP TABLE IF EXISTS `system_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `system_settings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(255) NOT NULL,
  `value` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`value`)),
  `value_type` varchar(255) NOT NULL DEFAULT 'json',
  `is_public` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `system_settings_key_unique` (`key`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `system_settings`
--

LOCK TABLES `system_settings` WRITE;
/*!40000 ALTER TABLE `system_settings` DISABLE KEYS */;
INSERT INTO `system_settings` VALUES (1,'qualification_thresholds','{\"representative_points\":1000,\"sales_manager_gateways\":50,\"development_manager_gateways\":200}','json',1,'2026-09-17 05:58:25','2026-09-17 05:58:25'),(2,'promotion_criteria','{\"sm_personal_points\":10000,\"sm_new_reps\":60,\"sm_strong_reps\":24,\"sm_rep_points\":5000,\"dm_years\":1,\"dm_new_reps\":100,\"dm_strong_reps\":30,\"dm_rep_points\":10000,\"dm_eligible_sms\":2}','json',0,'2026-09-17 05:58:25','2026-09-17 05:58:25'),(3,'shared_link_features','{\"referral_enabled\":true,\"gateway_sale_enabled\":true}','json',1,'2026-09-17 05:58:25','2026-09-17 05:58:25');
/*!40000 ALTER TABLE `system_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `user_course_progress`
--

DROP TABLE IF EXISTS `user_course_progress`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `user_course_progress` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `course_id` bigint(20) unsigned NOT NULL,
  `course_level_id` bigint(20) unsigned NOT NULL,
  `status` varchar(255) NOT NULL DEFAULT 'not_started',
  `score` decimal(8,2) NOT NULL DEFAULT 0.00,
  `progress_percent` decimal(8,2) NOT NULL DEFAULT 0.00,
  `completed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_course_progress_user_id_course_level_id_unique` (`user_id`,`course_level_id`),
  KEY `user_course_progress_course_id_foreign` (`course_id`),
  KEY `user_course_progress_course_level_id_foreign` (`course_level_id`),
  CONSTRAINT `user_course_progress_course_id_foreign` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `user_course_progress_course_level_id_foreign` FOREIGN KEY (`course_level_id`) REFERENCES `course_levels` (`id`) ON DELETE CASCADE,
  CONSTRAINT `user_course_progress_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `user_course_progress`
--

LOCK TABLES `user_course_progress` WRITE;
/*!40000 ALTER TABLE `user_course_progress` DISABLE KEYS */;
INSERT INTO `user_course_progress` VALUES (1,6,1,1,'completed',0.00,100.00,'2026-09-16 05:58:44','2026-09-17 05:58:44','2026-09-17 05:58:44'),(2,6,1,2,'completed',0.00,100.00,'2026-09-16 05:58:44','2026-09-17 05:58:44','2026-09-17 05:58:44'),(3,4,1,1,'completed',0.00,100.00,'2026-09-15 05:58:44','2026-09-17 05:58:44','2026-09-17 05:58:44'),(4,4,1,2,'completed',0.00,100.00,'2026-09-15 05:58:44','2026-09-17 05:58:44','2026-09-17 05:58:44'),(5,9,1,1,'completed',0.00,100.00,'2026-09-14 05:58:44','2026-09-17 05:58:44','2026-09-17 05:58:44'),(6,9,1,2,'completed',0.00,100.00,'2026-09-14 05:58:44','2026-09-17 05:58:44','2026-09-17 05:58:44'),(7,5,1,1,'completed',0.00,100.00,'2026-09-13 05:58:44','2026-09-17 05:58:44','2026-09-17 05:58:44'),(8,5,1,2,'completed',0.00,100.00,'2026-09-13 05:58:44','2026-09-17 05:58:44','2026-09-17 05:58:44'),(9,3,1,1,'completed',0.00,100.00,'2026-09-12 05:58:44','2026-09-17 05:58:44','2026-09-17 05:58:44'),(10,3,1,2,'completed',0.00,100.00,'2026-09-12 05:58:44','2026-09-17 05:58:44','2026-09-17 05:58:44'),(11,10,1,1,'completed',0.00,100.00,'2026-09-11 05:58:44','2026-09-17 05:58:44','2026-09-17 05:58:44'),(12,10,1,2,'completed',0.00,100.00,'2026-09-11 05:58:44','2026-09-17 05:58:44','2026-09-17 05:58:44'),(13,8,1,1,'completed',0.00,100.00,'2026-09-10 05:58:44','2026-09-17 05:58:44','2026-09-17 05:58:44'),(14,8,1,2,'completed',0.00,100.00,'2026-09-10 05:58:44','2026-09-17 05:58:44','2026-09-17 05:58:44');
/*!40000 ALTER TABLE `user_course_progress` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `user_permissions`
--

DROP TABLE IF EXISTS `user_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `user_permissions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `permission_id` bigint(20) unsigned NOT NULL,
  `allowed` tinyint(1) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_permissions_user_id_permission_id_unique` (`user_id`,`permission_id`),
  KEY `user_permissions_permission_id_foreign` (`permission_id`),
  CONSTRAINT `user_permissions_permission_id_foreign` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `user_permissions_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `user_permissions`
--

LOCK TABLES `user_permissions` WRITE;
/*!40000 ALTER TABLE `user_permissions` DISABLE KEYS */;
/*!40000 ALTER TABLE `user_permissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `user_roles`
--

DROP TABLE IF EXISTS `user_roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `user_roles` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `role_id` bigint(20) unsigned NOT NULL,
  `effective_from` date NOT NULL,
  `effective_to` date DEFAULT NULL,
  `is_primary` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_roles_user_id_role_id_effective_from_unique` (`user_id`,`role_id`,`effective_from`),
  KEY `user_roles_role_id_foreign` (`role_id`),
  KEY `user_roles_user_id_role_id_is_active_index` (`user_id`,`role_id`,`is_active`),
  CONSTRAINT `user_roles_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `user_roles_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=26 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `user_roles`
--

LOCK TABLES `user_roles` WRITE;
/*!40000 ALTER TABLE `user_roles` DISABLE KEYS */;
INSERT INTO `user_roles` VALUES (1,1,6,'2025-09-17',NULL,1,1,'2026-09-17 05:58:25','2026-09-17 05:58:25'),(2,2,1,'2025-09-17',NULL,1,1,'2026-09-17 05:58:26','2026-09-17 05:58:26'),(3,2,2,'2025-09-17',NULL,0,1,'2026-09-17 05:58:26','2026-09-17 05:58:26'),(4,2,3,'2025-09-17',NULL,0,1,'2026-09-17 05:58:26','2026-09-17 05:58:26'),(5,2,5,'2025-09-17',NULL,0,1,'2026-09-17 05:58:26','2026-09-17 05:58:26'),(6,3,2,'2025-09-17',NULL,1,1,'2026-09-17 05:58:26','2026-09-17 05:58:26'),(7,3,3,'2025-09-17',NULL,0,1,'2026-09-17 05:58:26','2026-09-17 05:58:26'),(8,3,5,'2025-09-17',NULL,0,1,'2026-09-17 05:58:26','2026-09-17 05:58:26'),(9,4,3,'2025-09-17',NULL,1,1,'2026-09-17 05:58:26','2026-09-17 05:58:26'),(10,4,5,'2025-09-17',NULL,0,1,'2026-09-17 05:58:26','2026-09-17 05:58:26'),(11,5,4,'2025-09-17',NULL,1,1,'2026-09-17 05:58:27','2026-09-17 05:58:27'),(12,5,5,'2025-09-17',NULL,0,1,'2026-09-17 05:58:27','2026-09-17 05:58:27'),(13,6,5,'2025-09-17',NULL,1,1,'2026-09-17 05:58:27','2026-09-17 05:58:27'),(14,7,5,'2025-09-17',NULL,1,1,'2026-09-17 05:58:28','2026-09-17 05:58:28'),(15,7,4,'2025-09-17',NULL,0,1,'2026-09-17 05:58:28','2026-09-17 05:58:28'),(16,7,3,'2025-09-17',NULL,0,1,'2026-09-17 05:58:28','2026-09-17 05:58:28'),(17,7,2,'2025-09-17',NULL,0,1,'2026-09-17 05:58:28','2026-09-17 05:58:28'),(18,8,5,'2025-09-17',NULL,1,1,'2026-09-17 05:58:28','2026-09-17 05:58:28'),(19,8,4,'2025-09-17',NULL,0,1,'2026-09-17 05:58:28','2026-09-17 05:58:28'),(20,8,3,'2025-09-17',NULL,0,1,'2026-09-17 05:58:28','2026-09-17 05:58:28'),(21,8,2,'2025-09-17',NULL,0,1,'2026-09-17 05:58:28','2026-09-17 05:58:28'),(22,9,5,'2025-09-17',NULL,1,1,'2026-09-17 05:58:29','2026-09-17 05:58:29'),(23,10,5,'2025-09-17',NULL,1,1,'2026-09-17 05:58:29','2026-09-17 05:58:29'),(24,11,5,'2025-09-17',NULL,1,1,'2026-09-17 05:58:30','2026-09-17 05:58:30'),(25,2,4,'2026-09-17',NULL,0,1,'2026-09-17 05:58:30','2026-09-17 05:58:30');
/*!40000 ALTER TABLE `user_roles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `mobile` varchar(255) NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `avatar` varchar(255) DEFAULT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `remember_token` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_mobile_unique` (`mobile`),
  UNIQUE KEY `users_email_unique` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,'مدیر سامانه','09120000000','superuser@finopal.test',NULL,NULL,'$2y$12$G0eRJ4JOqwrtdjTdPBdTUeGUAczmnmRT4BerTNcyqxf5xBnffiTPC',1,NULL,'2026-09-17 05:58:25','2026-09-17 05:58:43'),(2,'مدیر ارشد فاینوپال','09121111111','senior@finopal.test',NULL,NULL,'$2y$12$cgXeJ1leOa8j.R48Pgz1euDFX4tYGDsIM2ORktBHp6KX0.kIXPNJC',1,NULL,'2026-09-17 05:58:25','2026-09-17 05:58:25'),(3,'مدیر توسعه','09122222222','dev@finopal.test',NULL,NULL,'$2y$12$l4qb5IXaoVGpU0dGEiKm0OzXWNRcGkGGh6ShQma8hhFdM3TMpqBIq',1,NULL,'2026-09-17 05:58:26','2026-09-17 05:58:26'),(4,'مدیر فروش','09123333333','sales@finopal.test',NULL,NULL,'$2y$12$syb1DJj0sz2NB9tSCrppX.RZ6rVLKZYxMwNliM5nFlP/LaCPLW7Lq',1,NULL,'2026-09-17 05:58:26','2026-09-17 05:58:26'),(5,'نماینده معرف','09124444444','referrer@finopal.test',NULL,NULL,'$2y$12$wqS9aZeJu.E3gaHfgegbO.8ZqYJy7NRmAENsGnoIb61Gv4kg9FLDW',1,NULL,'2026-09-17 05:58:27','2026-09-17 05:58:27'),(6,'نماینده اصلی','09125555555','rep@finopal.test',NULL,NULL,'$2y$12$UhQ9gGg0U/sG219j2eUL9uKQ0.ffntUxDgy3Fz4j7Y5r5afD41Cti',1,NULL,'2026-09-17 05:58:27','2026-09-17 05:58:27'),(7,'کاربر چندنقشی','09126666666','multi@finopal.test',NULL,NULL,'$2y$12$nsgtDE6aXPkF/Pv4AqeMAOL30mhFNhvErM88BwSoyaWyVkK6bHUxe',1,NULL,'2026-09-17 05:58:28','2026-09-17 05:58:28'),(8,'کاربر چندنقشی ب','09120202020','multi_b@finopal.test',NULL,NULL,'$2y$12$e7i7YTeEuqmS2vU8tNiFLevQvC3hX7bTb0Z7CumIPiS1hzFFydipO',1,NULL,'2026-09-17 05:58:28','2026-09-17 05:58:28'),(9,'نماینده اشتراکی الف','09127777777','share_a@finopal.test',NULL,NULL,'$2y$12$7cIWAqfNANxHQDz1uRwUMusPqB/Ob0h.5qj6wbInuV1Y0qnjJHeY2',1,NULL,'2026-09-17 05:58:29','2026-09-17 05:58:29'),(10,'نماینده اشتراکی ب','09128888888','share_b@finopal.test',NULL,NULL,'$2y$12$ibwBEBXoAxj9G.YlkZrR8.geJcDZ638M9cj0Adb/bjE8XYTzB1cp2',1,NULL,'2026-09-17 05:58:29','2026-09-17 05:58:29'),(11,'شاخه جدا','09129999999','outsider@finopal.test',NULL,NULL,'$2y$12$zfBqw8mcQqFKElflJChtPuaeFqNbl3zr4Qxr2fequEBM1Pi4XDyVC',1,NULL,'2026-09-17 05:58:29','2026-09-17 05:58:29');
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `wallet_transactions`
--

DROP TABLE IF EXISTS `wallet_transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `wallet_transactions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `wallet_id` bigint(20) unsigned NOT NULL,
  `type` varchar(255) NOT NULL,
  `amount` decimal(18,3) NOT NULL,
  `balance_before` decimal(18,3) NOT NULL,
  `balance_after` decimal(18,3) NOT NULL,
  `reference_type` varchar(255) DEFAULT NULL,
  `reference_id` bigint(20) unsigned DEFAULT NULL,
  `idempotency_key` varchar(255) NOT NULL,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `wallet_transactions_idempotency_key_unique` (`idempotency_key`),
  KEY `wallet_transactions_wallet_id_created_at_index` (`wallet_id`,`created_at`),
  CONSTRAINT `wallet_transactions_wallet_id_foreign` FOREIGN KEY (`wallet_id`) REFERENCES `wallets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=24 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `wallet_transactions`
--

LOCK TABLES `wallet_transactions` WRITE;
/*!40000 ALTER TABLE `wallet_transactions` DISABLE KEYS */;
INSERT INTO `wallet_transactions` VALUES (1,12,'commission_credit',150000.000,0.000,150000.000,'App\\Models\\Commission',1,'wallet-commission:tx:1:6:5','[]','2026-09-17 05:58:38','2026-09-17 05:58:38'),(2,10,'commission_credit',20000.000,0.000,20000.000,'App\\Models\\Commission',2,'wallet-commission:tx:1:5:4','[]','2026-09-17 05:58:38','2026-09-17 05:58:38'),(3,1,'commission_credit',40000.000,0.000,40000.000,'App\\Models\\Commission',3,'wallet-commission:tx:1:2:1','[]','2026-09-17 05:58:38','2026-09-17 05:58:38'),(4,5,'commission_credit',45000.000,0.000,45000.000,'App\\Models\\Commission',4,'wallet-commission:tx:1:3:2','[]','2026-09-17 05:58:39','2026-09-17 05:58:39'),(5,8,'commission_credit',60000.000,0.000,60000.000,'App\\Models\\Commission',5,'wallet-commission:tx:1:4:3','[]','2026-09-17 05:58:39','2026-09-17 05:58:39'),(6,21,'commission_credit',150000.000,0.000,150000.000,'App\\Models\\Commission',6,'wallet-commission:tx:2:9:5','[]','2026-09-17 05:58:42','2026-09-17 05:58:42'),(7,22,'commission_credit',150000.000,0.000,150000.000,'App\\Models\\Commission',7,'wallet-commission:tx:2:10:5','[]','2026-09-17 05:58:42','2026-09-17 05:58:42'),(8,10,'commission_credit',40000.000,20000.000,60000.000,'App\\Models\\Commission',8,'wallet-commission:tx:2:5:4','[]','2026-09-17 05:58:42','2026-09-17 05:58:42'),(9,1,'commission_credit',80000.000,40000.000,120000.000,'App\\Models\\Commission',9,'wallet-commission:tx:2:2:1','[]','2026-09-17 05:58:42','2026-09-17 05:58:42'),(10,5,'commission_credit',90000.000,45000.000,135000.000,'App\\Models\\Commission',10,'wallet-commission:tx:2:3:2','[]','2026-09-17 05:58:42','2026-09-17 05:58:42'),(11,8,'commission_credit',120000.000,60000.000,180000.000,'App\\Models\\Commission',11,'wallet-commission:tx:2:4:3','[]','2026-09-17 05:58:42','2026-09-17 05:58:42'),(12,17,'commission_credit',270000.000,0.000,270000.000,'App\\Models\\Commission',12,'wallet-commission:tx:3:8:5','[]','2026-09-17 05:58:42','2026-09-17 05:58:42'),(13,1,'commission_credit',72000.000,120000.000,192000.000,'App\\Models\\Commission',13,'wallet-commission:tx:3:2:1','[]','2026-09-17 05:58:42','2026-09-17 05:58:42'),(14,2,'commission_credit',81000.000,0.000,81000.000,'App\\Models\\Commission',14,'wallet-commission:tx:3:2:2','[]','2026-09-17 05:58:42','2026-09-17 05:58:42'),(15,3,'commission_credit',108000.000,0.000,108000.000,'App\\Models\\Commission',15,'wallet-commission:tx:3:2:3','[]','2026-09-17 05:58:42','2026-09-17 05:58:42'),(16,20,'adjustment',320000.000,0.000,320000.000,NULL,NULL,'demo-multi-b-wallet-development_manager','[]','2026-09-17 05:58:44','2026-09-17 05:58:44'),(17,19,'adjustment',1250000.000,0.000,1250000.000,NULL,NULL,'demo-multi-b-wallet-sales_manager','[]','2026-09-17 05:58:44','2026-09-17 05:58:44'),(18,17,'adjustment',750000.000,270000.000,1020000.000,NULL,NULL,'demo-multi-b-wallet-representative','[]','2026-09-17 05:58:44','2026-09-17 05:58:44'),(19,1,'adjustment',2000000.000,192000.000,2192000.000,NULL,NULL,'demo-wallet-topup-2-1-v2','[]','2026-09-17 05:58:44','2026-09-17 05:58:44'),(20,2,'adjustment',2000000.000,81000.000,2081000.000,NULL,NULL,'demo-wallet-topup-2-2-v2','[]','2026-09-17 05:58:44','2026-09-17 05:58:44'),(21,3,'adjustment',2000000.000,108000.000,2108000.000,NULL,NULL,'demo-wallet-topup-2-3-v2','[]','2026-09-17 05:58:44','2026-09-17 05:58:44'),(22,24,'adjustment',2000000.000,0.000,2000000.000,NULL,NULL,'demo-wallet-topup-2-4-v2','[]','2026-09-17 05:58:44','2026-09-17 05:58:44'),(23,4,'adjustment',2000000.000,0.000,2000000.000,NULL,NULL,'demo-wallet-topup-2-5-v2','[]','2026-09-17 05:58:44','2026-09-17 05:58:44');
/*!40000 ALTER TABLE `wallet_transactions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `wallets`
--

DROP TABLE IF EXISTS `wallets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `wallets` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `role_id` bigint(20) unsigned NOT NULL,
  `currency` varchar(8) NOT NULL DEFAULT 'IRT',
  `balance` decimal(18,3) NOT NULL DEFAULT 0.000,
  `held_balance` decimal(18,3) NOT NULL DEFAULT 0.000,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `wallets_user_id_role_id_currency_unique` (`user_id`,`role_id`,`currency`),
  KEY `wallets_role_id_foreign` (`role_id`),
  CONSTRAINT `wallets_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `wallets_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=25 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `wallets`
--

LOCK TABLES `wallets` WRITE;
/*!40000 ALTER TABLE `wallets` DISABLE KEYS */;
INSERT INTO `wallets` VALUES (1,2,1,'IRT',2192000.000,0.000,1,'2026-09-17 05:58:26','2026-09-17 05:58:44'),(2,2,2,'IRT',2081000.000,0.000,1,'2026-09-17 05:58:26','2026-09-17 05:58:44'),(3,2,3,'IRT',2108000.000,0.000,1,'2026-09-17 05:58:26','2026-09-17 05:58:44'),(4,2,5,'IRT',2000000.000,0.000,1,'2026-09-17 05:58:26','2026-09-17 05:58:44'),(5,3,2,'IRT',135000.000,0.000,1,'2026-09-17 05:58:26','2026-09-17 05:58:42'),(6,3,3,'IRT',0.000,0.000,1,'2026-09-17 05:58:26','2026-09-17 05:58:26'),(7,3,5,'IRT',0.000,0.000,1,'2026-09-17 05:58:26','2026-09-17 05:58:26'),(8,4,3,'IRT',180000.000,0.000,1,'2026-09-17 05:58:26','2026-09-17 05:58:42'),(9,4,5,'IRT',0.000,0.000,1,'2026-09-17 05:58:26','2026-09-17 05:58:26'),(10,5,4,'IRT',60000.000,0.000,1,'2026-09-17 05:58:27','2026-09-17 05:58:42'),(11,5,5,'IRT',0.000,0.000,1,'2026-09-17 05:58:27','2026-09-17 05:58:27'),(12,6,5,'IRT',150000.000,0.000,1,'2026-09-17 05:58:27','2026-09-17 05:58:38'),(13,7,5,'IRT',0.000,0.000,1,'2026-09-17 05:58:28','2026-09-17 05:58:28'),(14,7,4,'IRT',0.000,0.000,1,'2026-09-17 05:58:28','2026-09-17 05:58:28'),(15,7,3,'IRT',0.000,0.000,1,'2026-09-17 05:58:28','2026-09-17 05:58:28'),(16,7,2,'IRT',0.000,0.000,1,'2026-09-17 05:58:28','2026-09-17 05:58:28'),(17,8,5,'IRT',1020000.000,0.000,1,'2026-09-17 05:58:28','2026-09-17 05:58:44'),(18,8,4,'IRT',0.000,0.000,1,'2026-09-17 05:58:28','2026-09-17 05:58:28'),(19,8,3,'IRT',1250000.000,0.000,1,'2026-09-17 05:58:28','2026-09-17 05:58:44'),(20,8,2,'IRT',320000.000,0.000,1,'2026-09-17 05:58:28','2026-09-17 05:58:44'),(21,9,5,'IRT',150000.000,0.000,1,'2026-09-17 05:58:29','2026-09-17 05:58:42'),(22,10,5,'IRT',150000.000,0.000,1,'2026-09-17 05:58:29','2026-09-17 05:58:42'),(23,11,5,'IRT',0.000,0.000,1,'2026-09-17 05:58:30','2026-09-17 05:58:30'),(24,2,4,'IRT',2000000.000,0.000,1,'2026-09-17 05:58:30','2026-09-17 05:58:44');
/*!40000 ALTER TABLE `wallets` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `withdrawal_approvals`
--

DROP TABLE IF EXISTS `withdrawal_approvals`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `withdrawal_approvals` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `withdrawal_request_id` bigint(20) unsigned NOT NULL,
  `approver_user_id` bigint(20) unsigned NOT NULL,
  `stage` varchar(255) NOT NULL,
  `decision` varchar(255) NOT NULL,
  `note` text DEFAULT NULL,
  `decided_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `withdrawal_approvals_withdrawal_request_id_foreign` (`withdrawal_request_id`),
  KEY `withdrawal_approvals_approver_user_id_foreign` (`approver_user_id`),
  CONSTRAINT `withdrawal_approvals_approver_user_id_foreign` FOREIGN KEY (`approver_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `withdrawal_approvals_withdrawal_request_id_foreign` FOREIGN KEY (`withdrawal_request_id`) REFERENCES `withdrawal_requests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `withdrawal_approvals`
--

LOCK TABLES `withdrawal_approvals` WRITE;
/*!40000 ALTER TABLE `withdrawal_approvals` DISABLE KEYS */;
/*!40000 ALTER TABLE `withdrawal_approvals` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `withdrawal_requests`
--

DROP TABLE IF EXISTS `withdrawal_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `withdrawal_requests` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `wallet_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `amount` decimal(18,3) NOT NULL,
  `status` varchar(255) NOT NULL,
  `idempotency_key` varchar(255) NOT NULL,
  `requested_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `completed_at` timestamp NULL DEFAULT NULL,
  `failure_reason` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `withdrawal_requests_idempotency_key_unique` (`idempotency_key`),
  KEY `withdrawal_requests_wallet_id_foreign` (`wallet_id`),
  KEY `withdrawal_requests_user_id_status_index` (`user_id`,`status`),
  CONSTRAINT `withdrawal_requests_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `withdrawal_requests_wallet_id_foreign` FOREIGN KEY (`wallet_id`) REFERENCES `wallets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `withdrawal_requests`
--

LOCK TABLES `withdrawal_requests` WRITE;
/*!40000 ALTER TABLE `withdrawal_requests` DISABLE KEYS */;
/*!40000 ALTER TABLE `withdrawal_requests` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping routines for database 'finopal_mlm'
--
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-09-17 12:59:57
