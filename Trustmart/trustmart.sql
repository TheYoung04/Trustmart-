-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: trustmart
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
-- Table structure for table `disputes`
--

DROP TABLE IF EXISTS `disputes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `disputes` (
  `dispute_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `transaction_id` int(10) unsigned NOT NULL,
  `filed_by` int(10) unsigned NOT NULL,
  `title` varchar(200) NOT NULL,
  `description` text NOT NULL,
  `severity` enum('Rendah','Sederhana','Mendesak') NOT NULL DEFAULT 'Sederhana',
  `status` enum('Terbuka','Dalam Semakan','Selesai') NOT NULL DEFAULT 'Terbuka',
  `resolution` enum('Memihak Pembeli','Memihak Penjual','Neutral') DEFAULT NULL,
  `resolved_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `resolved_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`dispute_id`),
  UNIQUE KEY `uq_dispute_per_txn` (`transaction_id`),
  KEY `fk_dispute_txn` (`transaction_id`),
  KEY `fk_dispute_filer` (`filed_by`),
  KEY `fk_dispute_resolver` (`resolved_by`),
  KEY `idx_status` (`status`),
  KEY `idx_severity` (`severity`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `fk_dispute_filer` FOREIGN KEY (`filed_by`) REFERENCES `users` (`user_id`),
  CONSTRAINT `fk_dispute_resolver` FOREIGN KEY (`resolved_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_dispute_txn` FOREIGN KEY (`transaction_id`) REFERENCES `transactions` (`transaction_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `disputes`
--

LOCK TABLES `disputes` WRITE;
/*!40000 ALTER TABLE `disputes` DISABLE KEYS */;
INSERT INTO `disputes` VALUES (3,19,1,'Barang Rosak Semasa Dihantar','Keyboard keys are not working. Looks like physical damage.','Mendesak','Selesai','Memihak Pembeli',3,'2026-06-18 15:19:30','2026-06-18 15:20:51'),(4,21,17,'Barang rosak','Barang tak boleh pakai','Sederhana','Selesai','Memihak Pembeli',3,'2026-06-18 16:57:58','2026-06-18 17:00:33'),(5,23,17,'Barang rosak','ROSAK TAK BOLEH PAKAI','Sederhana','Selesai','Memihak Pembeli',3,'2026-06-18 17:09:18','2026-06-18 11:15:26'),(6,24,17,'Barang rosak','BARANG ROSAK','Sederhana','Selesai','Memihak Penjual',3,'2026-06-18 17:16:55','2026-06-18 11:17:23'),(7,20,1,'Barang rosak','tak cantik','Sederhana','Selesai','Memihak Pembeli',3,'2026-06-18 17:21:06','2026-06-18 11:22:27'),(8,25,21,'Barang rosak','Barang dah lama','Sederhana','Selesai','Memihak Pembeli',3,'2026-06-19 00:46:33','2026-06-18 18:47:40');
/*!40000 ALTER TABLE `disputes` ENABLE KEYS */;
UNLOCK TABLES;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = cp850 */ ;
/*!50003 SET character_set_results = cp850 */ ;
/*!50003 SET collation_connection  = cp850_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_AUTO_VALUE_ON_ZERO' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER after_dispute_update
AFTER UPDATE ON disputes
FOR EACH ROW
BEGIN
  DECLARE v_buyer_id INT UNSIGNED DEFAULT NULL;
  DECLARE v_seller_id INT UNSIGNED DEFAULT NULL;
  IF NOT (OLD.resolution<=>NEW.resolution) OR OLD.status<>NEW.status THEN
    SELECT buyer_id,seller_id INTO v_buyer_id,v_seller_id
      FROM transactions WHERE transaction_id=NEW.transaction_id LIMIT 1;
    IF v_buyer_id IS NOT NULL AND v_seller_id IS NOT NULL THEN
      CALL recalculate_trust_score(v_seller_id);
      CALL recalculate_buyer_trust_score(v_buyer_id);
    END IF;
  END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = cp850 */ ;
/*!50003 SET character_set_results = cp850 */ ;
/*!50003 SET collation_connection  = cp850_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_AUTO_VALUE_ON_ZERO' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER after_dispute_delete
AFTER DELETE ON disputes
FOR EACH ROW
BEGIN
  DECLARE v_buyer_id INT UNSIGNED DEFAULT NULL;
  DECLARE v_seller_id INT UNSIGNED DEFAULT NULL;
  SELECT buyer_id,seller_id INTO v_buyer_id,v_seller_id
    FROM transactions WHERE transaction_id=OLD.transaction_id LIMIT 1;
  IF v_buyer_id IS NOT NULL AND v_seller_id IS NOT NULL THEN
    CALL recalculate_trust_score(v_seller_id);
    CALL recalculate_buyer_trust_score(v_buyer_id);
  END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;

--
-- Table structure for table `messages`
--

DROP TABLE IF EXISTS `messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `messages` (
  `message_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `sender_id` int(10) unsigned NOT NULL,
  `receiver_id` int(10) unsigned NOT NULL,
  `product_id` int(10) unsigned DEFAULT NULL,
  `message_text` text NOT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`message_id`),
  KEY `product_id` (`product_id`),
  KEY `idx_sender` (`sender_id`),
  KEY `idx_receiver` (`receiver_id`),
  KEY `idx_created` (`created_at`),
  CONSTRAINT `messages_ibfk_1` FOREIGN KEY (`sender_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  CONSTRAINT `messages_ibfk_2` FOREIGN KEY (`receiver_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  CONSTRAINT `messages_ibfk_3` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `messages`
--

LOCK TABLES `messages` WRITE;
/*!40000 ALTER TABLE `messages` DISABLE KEYS */;
INSERT INTO `messages` VALUES (3,1,2,1,'Hai, saya berminat dengan produk \"Laptop Dell Inspiron 15 (2022)\" anda. Adakah masih ada stok?',1,'2026-06-18 01:23:59'),(4,2,1,NULL,'Maaf, stok untuk Laptop Dell Inspiron 15 (2022) telah habis buat masa ini.',1,'2026-06-18 01:30:22'),(5,1,2,NULL,'okay baik',1,'2026-06-18 03:54:15'),(6,2,1,NULL,'okay',1,'2026-06-18 03:54:35'),(7,17,16,12,'Hai, saya berminat dengan produk \"JAM TANGAN\" anda. Adakah masih ada stok?',1,'2026-06-18 16:53:05'),(8,16,17,NULL,'ye masih ada',1,'2026-06-18 16:55:47'),(9,21,20,13,'Hai, saya berminat dengan produk \"Buku\" anda. Adakah masih ada stok?',1,'2026-06-19 00:43:23'),(10,20,21,NULL,'Ya masih ada',0,'2026-06-19 00:44:36');
/*!40000 ALTER TABLE `messages` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `products`
--

DROP TABLE IF EXISTS `products`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `products` (
  `product_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `seller_id` int(10) unsigned NOT NULL,
  `name` varchar(200) NOT NULL,
  `category` enum('Elektronik','Buku','Pakaian','Makanan','Perkhidmatan','Lain-lain') NOT NULL,
  `description` text DEFAULT NULL,
  `price` decimal(10,2) NOT NULL CHECK (`price` >= 0),
  `stock` int(10) unsigned NOT NULL DEFAULT 0,
  `sold_count` int(10) unsigned NOT NULL DEFAULT 0,
  `image_path` varchar(255) DEFAULT NULL,
  `status` enum('Aktif','Tidak Aktif','Dipadam') NOT NULL DEFAULT 'Aktif',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`product_id`),
  KEY `idx_seller_id` (`seller_id`),
  KEY `idx_category` (`category`),
  KEY `idx_status` (`status`),
  KEY `idx_price` (`price`),
  FULLTEXT KEY `idx_search` (`name`,`description`),
  CONSTRAINT `fk_product_seller` FOREIGN KEY (`seller_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `products`
--

LOCK TABLES `products` WRITE;
/*!40000 ALTER TABLE `products` DISABLE KEYS */;
INSERT INTO `products` VALUES (1,2,'Laptop Dell Inspiron 15 (2022)','Elektronik','Intel Core i5, 8GB RAM, 512GB SSD. Kondisi 9/10.',1850.00,5,1,'products/dell_laptop.png','Aktif','2026-06-15 14:45:50','2026-09-30 04:42:44'),(2,2,'Powerbank Anker 20,000mAh','Elektronik','USB-C dan USB-A, fast charge 65W.',95.00,5,8,'products/powerbank.png','Aktif','2026-06-15 14:45:50','2026-09-30 04:42:44'),(3,4,'Buku Kalkulus Stewart Ed.8','Buku','Edisi 8, kondisi baik, ada nota pensil.',35.00,5,10,'products/calculus_book.png','Aktif','2026-06-15 14:45:50','2026-09-30 04:42:44'),(4,5,'Baju Kolej Mawar (L)','Pakaian','Baju rasmi kolej mawar saiz L.',28.00,2,15,'products/college_shirt.png','Aktif','2026-06-15 14:45:50','2026-06-18 17:03:44'),(5,2,'Casing Phone','Elektronik',NULL,10.00,5,2,NULL,'Aktif','2026-06-15 15:16:07','2026-09-30 04:42:44'),(11,15,'Mechanical Keyboard','Elektronik','RGB keyboard with blue switches.',150.00,14,1,NULL,'Aktif','2026-06-18 15:16:24','2026-06-18 15:17:13'),(12,16,'JAM TANGAN','Elektronik',NULL,123.00,5,3,'products/e663a37461ce643b580a08ea.webp','Aktif','2026-06-18 16:52:38','2026-09-30 04:42:44'),(13,20,'Buku','Buku','Baru Dibeli',10.00,3,2,'products/3b8c6fc0cff93a1653aa1dda.jpg','Aktif','2026-06-19 00:41:26','2026-09-30 19:46:11');
/*!40000 ALTER TABLE `products` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `reviews`
--

DROP TABLE IF EXISTS `reviews`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `reviews` (
  `review_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `transaction_id` int(10) unsigned NOT NULL,
  `buyer_id` int(10) unsigned NOT NULL,
  `seller_id` int(10) unsigned NOT NULL,
  `rating` tinyint(3) unsigned NOT NULL CHECK (`rating` between 1 and 5),
  `comment` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`review_id`),
  UNIQUE KEY `uq_review_per_txn` (`transaction_id`),
  KEY `fk_review_buyer` (`buyer_id`),
  KEY `idx_seller_id` (`seller_id`),
  KEY `idx_rating` (`rating`),
  CONSTRAINT `fk_review_buyer` FOREIGN KEY (`buyer_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_review_seller` FOREIGN KEY (`seller_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_review_txn` FOREIGN KEY (`transaction_id`) REFERENCES `transactions` (`transaction_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `reviews`
--

LOCK TABLES `reviews` WRITE;
/*!40000 ALTER TABLE `reviews` DISABLE KEYS */;
/*!40000 ALTER TABLE `reviews` ENABLE KEYS */;
UNLOCK TABLES;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = cp850 */ ;
/*!50003 SET character_set_results = cp850 */ ;
/*!50003 SET collation_connection  = cp850_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_AUTO_VALUE_ON_ZERO' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER after_review_insert
AFTER INSERT ON reviews
FOR EACH ROW BEGIN
  CALL recalculate_trust_score(NEW.seller_id);
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = cp850 */ ;
/*!50003 SET character_set_results = cp850 */ ;
/*!50003 SET collation_connection  = cp850_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_AUTO_VALUE_ON_ZERO' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER after_review_update
AFTER UPDATE ON reviews
FOR EACH ROW BEGIN
  CALL recalculate_trust_score(NEW.seller_id);
  IF OLD.seller_id<>NEW.seller_id THEN
    CALL recalculate_trust_score(OLD.seller_id);
  END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = cp850 */ ;
/*!50003 SET character_set_results = cp850 */ ;
/*!50003 SET collation_connection  = cp850_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_AUTO_VALUE_ON_ZERO' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER after_review_delete
AFTER DELETE ON reviews
FOR EACH ROW BEGIN
  CALL recalculate_trust_score(OLD.seller_id);
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;

--
-- Table structure for table `transactions`
--

DROP TABLE IF EXISTS `transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `transactions` (
  `transaction_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `buyer_id` int(10) unsigned NOT NULL,
  `seller_id` int(10) unsigned NOT NULL,
  `product_id` int(10) unsigned NOT NULL,
  `quantity` int(10) unsigned NOT NULL DEFAULT 1 CHECK (`quantity` > 0),
  `total_amount` decimal(10,2) NOT NULL CHECK (`total_amount` >= 0),
  `payment_method` enum('Tunai','Pindahan Bank','E-Wallet') NOT NULL DEFAULT 'Tunai',
  `status` enum('Menunggu','Disahkan','Selesai','Dibatalkan') NOT NULL DEFAULT 'Menunggu',
  `confirmed_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `completed_by` enum('buyer','seller','system','legacy') DEFAULT NULL,
  `cancelled_at` timestamp NULL DEFAULT NULL,
  `cancelled_by` enum('buyer','seller','admin','system','legacy') DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`transaction_id`),
  KEY `idx_buyer_id` (`buyer_id`),
  KEY `idx_seller_id` (`seller_id`),
  KEY `idx_product_id` (`product_id`),
  KEY `idx_status` (`status`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_txn_seller_trust` (`seller_id`,`status`,`cancelled_by`),
  KEY `idx_txn_buyer_trust` (`buyer_id`,`status`,`cancelled_by`),
  CONSTRAINT `fk_txn_buyer` FOREIGN KEY (`buyer_id`) REFERENCES `users` (`user_id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_txn_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_txn_seller` FOREIGN KEY (`seller_id`) REFERENCES `users` (`user_id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=28 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `transactions`
--

LOCK TABLES `transactions` WRITE;
/*!40000 ALTER TABLE `transactions` DISABLE KEYS */;
INSERT INTO `transactions` VALUES (19,1,15,11,1,150.00,'Pindahan Bank','Selesai',NULL,'2026-06-18 15:18:54','legacy',NULL,NULL,'2026-06-18 15:17:13','2026-09-30 20:16:29'),(20,1,5,4,1,28.00,'Tunai','Selesai',NULL,'2026-06-18 17:20:49','legacy',NULL,NULL,'2026-06-18 15:22:46','2026-09-30 20:16:29'),(21,17,16,12,1,123.00,'Tunai','Selesai',NULL,'2026-06-18 16:57:15','legacy',NULL,NULL,'2026-06-18 16:56:41','2026-09-30 20:16:29'),(22,18,5,4,1,28.00,'Tunai','Selesai',NULL,'2026-06-18 17:20:43','legacy',NULL,NULL,'2026-06-18 17:03:43','2026-09-30 20:16:29'),(23,17,16,12,1,123.00,'Tunai','Selesai',NULL,'2026-06-18 17:08:59','legacy',NULL,NULL,'2026-06-18 17:08:47','2026-09-30 20:16:29'),(24,17,16,12,1,123.00,'Tunai','Selesai',NULL,'2026-06-18 17:16:35','legacy',NULL,NULL,'2026-06-18 17:16:24','2026-09-30 20:16:29'),(25,21,20,13,1,10.00,'Tunai','Selesai',NULL,'2026-06-19 00:45:11','legacy',NULL,NULL,'2026-06-19 00:43:38','2026-09-30 20:16:29'),(26,16,20,13,1,10.00,'Tunai','Menunggu',NULL,NULL,NULL,NULL,NULL,'2026-09-30 19:46:11','2026-09-30 19:46:11');
/*!40000 ALTER TABLE `transactions` ENABLE KEYS */;
UNLOCK TABLES;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = cp850 */ ;
/*!50003 SET character_set_results = cp850 */ ;
/*!50003 SET collation_connection  = cp850_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_AUTO_VALUE_ON_ZERO' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER after_transaction_insert
AFTER INSERT ON transactions
FOR EACH ROW
BEGIN
  CALL recalculate_trust_score(NEW.seller_id);
  CALL recalculate_buyer_trust_score(NEW.buyer_id);
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = cp850 */ ;
/*!50003 SET character_set_results = cp850 */ ;
/*!50003 SET collation_connection  = cp850_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_AUTO_VALUE_ON_ZERO' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER after_transaction_update
AFTER UPDATE ON transactions
FOR EACH ROW
BEGIN
  IF OLD.status<>NEW.status
     OR OLD.quantity<>NEW.quantity
     OR NOT (OLD.confirmed_at<=>NEW.confirmed_at)
     OR NOT (OLD.completed_at<=>NEW.completed_at)
     OR NOT (OLD.completed_by<=>NEW.completed_by)
     OR NOT (OLD.cancelled_at<=>NEW.cancelled_at)
     OR NOT (OLD.cancelled_by<=>NEW.cancelled_by) THEN
    CALL recalculate_trust_score(NEW.seller_id);
    CALL recalculate_buyer_trust_score(NEW.buyer_id);
  END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = cp850 */ ;
/*!50003 SET character_set_results = cp850 */ ;
/*!50003 SET collation_connection  = cp850_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_AUTO_VALUE_ON_ZERO' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER after_transaction_delete
AFTER DELETE ON transactions
FOR EACH ROW
BEGIN
  CALL recalculate_trust_score(OLD.seller_id);
  CALL recalculate_buyer_trust_score(OLD.buyer_id);
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;

--
-- Table structure for table `trust_profiles`
--

DROP TABLE IF EXISTS `trust_profiles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `trust_profiles` (
  `user_id` int(10) unsigned NOT NULL,
  `profile_role` enum('pembeli','penjual') NOT NULL,
  `score` decimal(5,2) NOT NULL DEFAULT 0.00,
  `raw_score` decimal(5,2) NOT NULL DEFAULT 0.00,
  `confidence` decimal(5,2) NOT NULL DEFAULT 0.00,
  `confidence_label` enum('Tiada','Rendah','Sederhana','Tinggi') NOT NULL DEFAULT 'Tiada',
  `trust_level` enum('Baharu','Awal','Membina','Sederhana','Dipercayai','Sangat Dipercayai') NOT NULL DEFAULT 'Baharu',
  `evidence_units` decimal(8,2) NOT NULL DEFAULT 0.00,
  `distinct_counterparties` int(10) unsigned NOT NULL DEFAULT 0,
  `completed_transactions` int(10) unsigned NOT NULL DEFAULT 0,
  `formula_version` varchar(24) NOT NULL DEFAULT 'TM-TRUST-2.0',
  `calculated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`user_id`,`profile_role`),
  KEY `idx_trust_profile_rank` (`profile_role`,`score`,`confidence`),
  CONSTRAINT `fk_trust_profile_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `trust_profiles`
--

LOCK TABLES `trust_profiles` WRITE;
/*!40000 ALTER TABLE `trust_profiles` DISABLE KEYS */;
INSERT INTO `trust_profiles` VALUES (1,'pembeli',38.94,85.00,45.82,'Sederhana','Membina',2.00,2,2,'TM-TRUST-2.0','2026-09-30 20:16:30'),(2,'pembeli',0.00,82.00,0.00,'Tiada','Baharu',0.00,0,0,'TM-TRUST-2.0','2026-09-30 20:16:30'),(2,'penjual',0.00,79.25,0.00,'Tiada','Baharu',0.00,0,0,'TM-TRUST-2.0','2026-09-30 20:16:30'),(4,'pembeli',0.00,82.00,0.00,'Tiada','Baharu',0.00,0,0,'TM-TRUST-2.0','2026-09-30 20:16:30'),(4,'penjual',0.00,79.25,0.00,'Tiada','Baharu',0.00,0,0,'TM-TRUST-2.0','2026-09-30 20:16:30'),(5,'pembeli',0.00,82.00,0.00,'Tiada','Baharu',0.00,0,0,'TM-TRUST-2.0','2026-09-30 20:16:30'),(5,'penjual',34.02,74.25,45.82,'Sederhana','Membina',2.00,2,2,'TM-TRUST-2.0','2026-09-30 20:16:30'),(15,'pembeli',0.00,82.00,0.00,'Tiada','Baharu',0.00,0,0,'TM-TRUST-2.0','2026-09-30 20:16:30'),(15,'penjual',21.07,72.89,28.91,'Rendah','Awal',1.00,1,1,'TM-TRUST-2.0','2026-09-30 20:16:30'),(16,'pembeli',0.00,82.00,0.00,'Tiada','Baharu',0.00,0,0,'TM-TRUST-2.0','2026-09-30 20:16:30'),(16,'penjual',40.04,69.25,57.81,'Sederhana','Membina',3.00,1,3,'TM-TRUST-2.0','2026-09-30 20:16:30'),(17,'pembeli',45.36,78.46,57.81,'Sederhana','Membina',3.00,1,3,'TM-TRUST-2.0','2026-09-30 20:16:30'),(18,'pembeli',24.18,83.64,28.91,'Rendah','Awal',1.00,1,1,'TM-TRUST-2.0','2026-09-30 20:16:30'),(19,'pembeli',0.00,82.00,0.00,'Tiada','Baharu',0.00,0,0,'TM-TRUST-2.0','2026-09-30 20:16:30'),(19,'penjual',0.00,79.25,0.00,'Tiada','Baharu',0.00,0,0,'TM-TRUST-2.0','2026-09-30 20:16:30'),(20,'pembeli',0.00,82.00,0.00,'Tiada','Baharu',0.00,0,0,'TM-TRUST-2.0','2026-09-30 20:16:30'),(20,'penjual',21.07,72.89,28.91,'Rendah','Awal',1.00,1,1,'TM-TRUST-2.0','2026-09-30 20:16:30'),(21,'pembeli',24.18,83.64,28.91,'Rendah','Awal',1.00,1,1,'TM-TRUST-2.0','2026-09-30 20:16:30');
/*!40000 ALTER TABLE `trust_profiles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `trust_score_components`
--

DROP TABLE IF EXISTS `trust_score_components`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `trust_score_components` (
  `user_id` int(10) unsigned NOT NULL,
  `profile_role` enum('pembeli','penjual') NOT NULL,
  `component_code` varchar(32) NOT NULL,
  `component_label` varchar(100) NOT NULL,
  `component_order` tinyint(3) unsigned NOT NULL,
  `max_points` decimal(5,2) NOT NULL,
  `metric_value` decimal(6,2) NOT NULL,
  `points` decimal(5,2) NOT NULL,
  `evidence_count` int(10) unsigned NOT NULL DEFAULT 0,
  `details` varchar(255) NOT NULL,
  PRIMARY KEY (`user_id`,`profile_role`,`component_code`),
  CONSTRAINT `fk_trust_component_profile` FOREIGN KEY (`user_id`, `profile_role`) REFERENCES `trust_profiles` (`user_id`, `profile_role`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `trust_score_components`
--

LOCK TABLES `trust_score_components` WRITE;
/*!40000 ALTER TABLE `trust_score_components` DISABLE KEYS */;
INSERT INTO `trust_score_components` VALUES (1,'pembeli','completion','Penyelesaian pesanan',1,65.00,83.33,54.17,2,'2 selesai; 0 dibatalkan pembeli'),(1,'pembeli','disputes','Keselamatan pertikaian',2,25.00,83.33,20.83,0,'0 keputusan aduan memihak penjual'),(1,'pembeli','identity','Identiti UTHM aktif',3,10.00,100.00,10.00,1,'Akaun UTHM aktif'),(2,'pembeli','completion','Penyelesaian pesanan',1,65.00,80.00,52.00,0,'0 selesai; 0 dibatalkan pembeli'),(2,'pembeli','disputes','Keselamatan pertikaian',2,25.00,80.00,20.00,0,'0 keputusan aduan memihak penjual'),(2,'pembeli','identity','Identiti UTHM aktif',3,10.00,100.00,10.00,1,'Akaun UTHM aktif'),(2,'penjual','disputes','Keselamatan pertikaian',3,20.00,80.00,16.00,0,'0 keputusan aduan memihak pembeli'),(2,'penjual','fulfilment','Kebolehpercayaan pesanan',2,30.00,80.00,24.00,0,'0 selesai; 0 dibatalkan penjual'),(2,'penjual','identity','Pengesahan identiti PTU',5,5.00,100.00,5.00,1,'Penjual disahkan PTU'),(2,'penjual','rating','Ulasan pembelian disahkan',1,35.00,75.00,26.25,0,'0 pembeli unik; purata Bayesian 4.00/5'),(2,'penjual','response','Respons keputusan pesanan',4,10.00,80.00,8.00,0,'0/0 keputusan dalam 12 jam'),(4,'pembeli','completion','Penyelesaian pesanan',1,65.00,80.00,52.00,0,'0 selesai; 0 dibatalkan pembeli'),(4,'pembeli','disputes','Keselamatan pertikaian',2,25.00,80.00,20.00,0,'0 keputusan aduan memihak penjual'),(4,'pembeli','identity','Identiti UTHM aktif',3,10.00,100.00,10.00,1,'Akaun UTHM aktif'),(4,'penjual','disputes','Keselamatan pertikaian',3,20.00,80.00,16.00,0,'0 keputusan aduan memihak pembeli'),(4,'penjual','fulfilment','Kebolehpercayaan pesanan',2,30.00,80.00,24.00,0,'0 selesai; 0 dibatalkan penjual'),(4,'penjual','identity','Pengesahan identiti PTU',5,5.00,100.00,5.00,1,'Penjual disahkan PTU'),(4,'penjual','rating','Ulasan pembelian disahkan',1,35.00,75.00,26.25,0,'0 pembeli unik; purata Bayesian 4.00/5'),(4,'penjual','response','Respons keputusan pesanan',4,10.00,80.00,8.00,0,'0/0 keputusan dalam 12 jam'),(5,'pembeli','completion','Penyelesaian pesanan',1,65.00,80.00,52.00,0,'0 selesai; 0 dibatalkan pembeli'),(5,'pembeli','disputes','Keselamatan pertikaian',2,25.00,80.00,20.00,0,'0 keputusan aduan memihak penjual'),(5,'pembeli','identity','Identiti UTHM aktif',3,10.00,100.00,10.00,1,'Akaun UTHM aktif'),(5,'penjual','disputes','Keselamatan pertikaian',3,20.00,50.00,10.00,1,'1 keputusan aduan memihak pembeli'),(5,'penjual','fulfilment','Kebolehpercayaan pesanan',2,30.00,83.33,25.00,2,'2 selesai; 0 dibatalkan penjual'),(5,'penjual','identity','Pengesahan identiti PTU',5,5.00,100.00,5.00,1,'Penjual disahkan PTU'),(5,'penjual','rating','Ulasan pembelian disahkan',1,35.00,75.00,26.25,0,'0 pembeli unik; purata Bayesian 4.00/5'),(5,'penjual','response','Respons keputusan pesanan',4,10.00,80.00,8.00,0,'0/0 keputusan dalam 12 jam'),(15,'pembeli','completion','Penyelesaian pesanan',1,65.00,80.00,52.00,0,'0 selesai; 0 dibatalkan pembeli'),(15,'pembeli','disputes','Keselamatan pertikaian',2,25.00,80.00,20.00,0,'0 keputusan aduan memihak penjual'),(15,'pembeli','identity','Identiti UTHM aktif',3,10.00,100.00,10.00,1,'Akaun UTHM aktif'),(15,'penjual','disputes','Keselamatan pertikaian',3,20.00,45.45,9.09,1,'1 keputusan aduan memihak pembeli'),(15,'penjual','fulfilment','Kebolehpercayaan pesanan',2,30.00,81.82,24.55,1,'1 selesai; 0 dibatalkan penjual'),(15,'penjual','identity','Pengesahan identiti PTU',5,5.00,100.00,5.00,1,'Penjual disahkan PTU'),(15,'penjual','rating','Ulasan pembelian disahkan',1,35.00,75.00,26.25,0,'0 pembeli unik; purata Bayesian 4.00/5'),(15,'penjual','response','Respons keputusan pesanan',4,10.00,80.00,8.00,0,'0/0 keputusan dalam 12 jam'),(16,'pembeli','completion','Penyelesaian pesanan',1,65.00,80.00,52.00,0,'0 selesai; 0 dibatalkan pembeli'),(16,'pembeli','disputes','Keselamatan pertikaian',2,25.00,80.00,20.00,0,'0 keputusan aduan memihak penjual'),(16,'pembeli','identity','Identiti UTHM aktif',3,10.00,100.00,10.00,1,'Akaun UTHM aktif'),(16,'penjual','disputes','Keselamatan pertikaian',3,20.00,23.08,4.62,2,'2 keputusan aduan memihak pembeli'),(16,'penjual','fulfilment','Kebolehpercayaan pesanan',2,30.00,84.62,25.38,3,'3 selesai; 0 dibatalkan penjual'),(16,'penjual','identity','Pengesahan identiti PTU',5,5.00,100.00,5.00,1,'Penjual disahkan PTU'),(16,'penjual','rating','Ulasan pembelian disahkan',1,35.00,75.00,26.25,0,'0 pembeli unik; purata Bayesian 4.00/5'),(16,'penjual','response','Respons keputusan pesanan',4,10.00,80.00,8.00,0,'0/0 keputusan dalam 12 jam'),(17,'pembeli','completion','Penyelesaian pesanan',1,65.00,84.62,55.00,3,'3 selesai; 0 dibatalkan pembeli'),(17,'pembeli','disputes','Keselamatan pertikaian',2,25.00,53.85,13.46,1,'1 keputusan aduan memihak penjual'),(17,'pembeli','identity','Identiti UTHM aktif',3,10.00,100.00,10.00,1,'Akaun UTHM aktif'),(18,'pembeli','completion','Penyelesaian pesanan',1,65.00,81.82,53.18,1,'1 selesai; 0 dibatalkan pembeli'),(18,'pembeli','disputes','Keselamatan pertikaian',2,25.00,81.82,20.45,0,'0 keputusan aduan memihak penjual'),(18,'pembeli','identity','Identiti UTHM aktif',3,10.00,100.00,10.00,1,'Akaun UTHM aktif'),(19,'pembeli','completion','Penyelesaian pesanan',1,65.00,80.00,52.00,0,'0 selesai; 0 dibatalkan pembeli'),(19,'pembeli','disputes','Keselamatan pertikaian',2,25.00,80.00,20.00,0,'0 keputusan aduan memihak penjual'),(19,'pembeli','identity','Identiti UTHM aktif',3,10.00,100.00,10.00,1,'Akaun UTHM aktif'),(19,'penjual','disputes','Keselamatan pertikaian',3,20.00,80.00,16.00,0,'0 keputusan aduan memihak pembeli'),(19,'penjual','fulfilment','Kebolehpercayaan pesanan',2,30.00,80.00,24.00,0,'0 selesai; 0 dibatalkan penjual'),(19,'penjual','identity','Pengesahan identiti PTU',5,5.00,100.00,5.00,1,'Penjual disahkan PTU'),(19,'penjual','rating','Ulasan pembelian disahkan',1,35.00,75.00,26.25,0,'0 pembeli unik; purata Bayesian 4.00/5'),(19,'penjual','response','Respons keputusan pesanan',4,10.00,80.00,8.00,0,'0/0 keputusan dalam 12 jam'),(20,'pembeli','completion','Penyelesaian pesanan',1,65.00,80.00,52.00,0,'0 selesai; 0 dibatalkan pembeli'),(20,'pembeli','disputes','Keselamatan pertikaian',2,25.00,80.00,20.00,0,'0 keputusan aduan memihak penjual'),(20,'pembeli','identity','Identiti UTHM aktif',3,10.00,100.00,10.00,1,'Akaun UTHM aktif'),(20,'penjual','disputes','Keselamatan pertikaian',3,20.00,45.45,9.09,1,'1 keputusan aduan memihak pembeli'),(20,'penjual','fulfilment','Kebolehpercayaan pesanan',2,30.00,81.82,24.55,1,'1 selesai; 0 dibatalkan penjual'),(20,'penjual','identity','Pengesahan identiti PTU',5,5.00,100.00,5.00,1,'Penjual disahkan PTU'),(20,'penjual','rating','Ulasan pembelian disahkan',1,35.00,75.00,26.25,0,'0 pembeli unik; purata Bayesian 4.00/5'),(20,'penjual','response','Respons keputusan pesanan',4,10.00,80.00,8.00,0,'0/0 keputusan dalam 12 jam'),(21,'pembeli','completion','Penyelesaian pesanan',1,65.00,81.82,53.18,1,'1 selesai; 0 dibatalkan pembeli'),(21,'pembeli','disputes','Keselamatan pertikaian',2,25.00,81.82,20.45,0,'0 keputusan aduan memihak penjual'),(21,'pembeli','identity','Identiti UTHM aktif',3,10.00,100.00,10.00,1,'Akaun UTHM aktif');
/*!40000 ALTER TABLE `trust_score_components` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `user_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `full_name` varchar(100) NOT NULL,
  `matric_no` varchar(20) NOT NULL,
  `email` varchar(150) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('pembeli','penjual','pentadbir') NOT NULL DEFAULT 'pembeli',
  `faculty` varchar(50) DEFAULT NULL,
  `is_verified` tinyint(1) NOT NULL DEFAULT 0,
  `verification_status` enum('Menunggu','Disahkan','Ditolak') NOT NULL DEFAULT 'Menunggu',
  `is_active` tinyint(1) NOT NULL DEFAULT 0,
  `activation_token` varchar(64) DEFAULT NULL,
  `reset_token` varchar(64) DEFAULT NULL,
  `reset_token_expiry` datetime DEFAULT NULL,
  `trust_score` decimal(5,2) NOT NULL DEFAULT 0.00,
  `buyer_trust_score` decimal(5,2) NOT NULL DEFAULT 100.00,
  `response_time_avg` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `matric_no` (`matric_no`),
  UNIQUE KEY `email` (`email`),
  KEY `idx_role` (`role`),
  KEY `idx_is_verified` (`is_verified`),
  KEY `idx_trust_score` (`trust_score`)
) ENGINE=InnoDB AUTO_INCREMENT=22 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,'Aishah Nabilah','DI240088','aishah@student.uthm.edu.my','$2y$12$5KwbH0IEz1LnAmFbn2eLYelaTWOFR81sjlwrvxworl4zUkGouFIfq','pembeli','FKAAB',0,'Disahkan',1,NULL,NULL,NULL,38.94,38.94,NULL,'2026-06-15 14:45:50','2026-09-30 20:16:30'),(2,'Muhammad Haziq','DE220045','haziq@student.uthm.edu.my','$2y$12$5KwbH0IEz1LnAmFbn2eLYelaTWOFR81sjlwrvxworl4zUkGouFIfq','penjual','FKEE',1,'Disahkan',1,NULL,NULL,NULL,0.00,0.00,NULL,'2026-06-15 14:45:50','2026-09-30 20:16:30'),(3,'PTU Admin','PTU-001','admin@uthm.edu.my','$2y$12$GKWlzJFNtX3wJ4TYIMHMu.iXzuBCZzycs2o1ROHo44jp63PnOK0ue','pentadbir','PTU',1,'Disahkan',1,NULL,NULL,NULL,0.00,100.00,NULL,'2026-06-15 14:45:50','2026-09-28 16:33:51'),(4,'Nurul Ain','DA230055','nurulain@student.uthm.edu.my','$2y$12$5KwbH0IEz1LnAmFbn2eLYelaTWOFR81sjlwrvxworl4zUkGouFIfq','penjual','FKMP',1,'Disahkan',1,NULL,NULL,NULL,0.00,0.00,NULL,'2026-06-15 14:45:50','2026-09-30 20:16:30'),(5,'Siti Hajar','DI220041','sitihajar@student.uthm.edu.my','$2y$12$5KwbH0IEz1LnAmFbn2eLYelaTWOFR81sjlwrvxworl4zUkGouFIfq','penjual','FKAAB',1,'Disahkan',1,NULL,NULL,NULL,34.02,0.00,NULL,'2026-06-15 14:45:50','2026-09-30 20:16:30'),(15,'Azwan Ali','DA240099','azwan@student.uthm.edu.my','$2y$12$5KwbH0IEz1LnAmFbn2eLYelaTWOFR81sjlwrvxworl4zUkGouFIfq','penjual','FSKTM',1,'Disahkan',1,NULL,NULL,NULL,21.07,0.00,NULL,'2026-06-18 15:13:59','2026-09-30 20:16:30'),(16,'Mirza','DI240069','mirza@student.uthm.edu.my','$2y$12$5KwbH0IEz1LnAmFbn2eLYelaTWOFR81sjlwrvxworl4zUkGouFIfq','penjual','FSKTM',1,'Disahkan',1,NULL,NULL,NULL,40.04,0.00,NULL,'2026-06-18 16:48:59','2026-09-30 20:16:30'),(17,'Razi','DE240090','de240090@student.uthm.edu.my','$2y$12$5KwbH0IEz1LnAmFbn2eLYelaTWOFR81sjlwrvxworl4zUkGouFIfq','pembeli','FPTP',0,'Menunggu',1,NULL,NULL,NULL,45.36,45.36,NULL,'2026-06-18 16:50:50','2026-09-30 20:16:30'),(18,'Test Buyer','DE240099','testbuyer123@student.uthm.edu.my','$2y$12$5KwbH0IEz1LnAmFbn2eLYelaTWOFR81sjlwrvxworl4zUkGouFIfq','pembeli',NULL,0,'Menunggu',1,NULL,NULL,NULL,24.18,24.18,NULL,'2026-06-18 17:02:51','2026-09-30 20:16:30'),(19,'Test Seller','DA240011','testseller123@student.uthm.edu.my','$2y$12$5KwbH0IEz1LnAmFbn2eLYelaTWOFR81sjlwrvxworl4zUkGouFIfq','penjual',NULL,1,'Disahkan',1,NULL,NULL,NULL,0.00,0.00,NULL,'2026-06-18 17:04:42','2026-09-30 20:16:30'),(20,'Faiq','DI240012','faiq@student.uthm.edu.my','$2y$12$5KwbH0IEz1LnAmFbn2eLYelaTWOFR81sjlwrvxworl4zUkGouFIfq','penjual','FSKTM',1,'Disahkan',1,NULL,NULL,NULL,21.07,0.00,NULL,'2026-06-19 00:38:03','2026-09-30 20:16:30'),(21,'Ahmad','DI240011','ahmad@student.uthm.edu.my','$2y$12$5KwbH0IEz1LnAmFbn2eLYelaTWOFR81sjlwrvxworl4zUkGouFIfq','pembeli','FKAAB',0,'Menunggu',1,NULL,NULL,NULL,24.18,24.18,NULL,'2026-06-19 00:42:24','2026-09-30 20:16:30');
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping routines for database 'trustmart'
--
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_AUTO_VALUE_ON_ZERO' */ ;
/*!50003 DROP PROCEDURE IF EXISTS `recalculate_buyer_trust_score` */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = cp850 */ ;
/*!50003 SET character_set_results = cp850 */ ;
/*!50003 SET collation_connection  = cp850_general_ci */ ;
DELIMITER ;;
CREATE DEFINER=`root`@`localhost` PROCEDURE `recalculate_buyer_trust_score`(IN p_buyer_id INT UNSIGNED)
proc: BEGIN
  DECLARE v_role VARCHAR(20) DEFAULT NULL;
  DECLARE v_is_active TINYINT DEFAULT 0;
  DECLARE v_completed INT UNSIGNED DEFAULT 0;
  DECLARE v_buyer_cancelled INT UNSIGNED DEFAULT 0;
  DECLARE v_completion_trials INT UNSIGNED DEFAULT 0;
  DECLARE v_completion_metric DECIMAL(8,4) DEFAULT 80.0000;
  DECLARE v_completion_points DECIMAL(8,4) DEFAULT 0;
  DECLARE v_adverse_disputes INT UNSIGNED DEFAULT 0;
  DECLARE v_defect_rate DECIMAL(10,6) DEFAULT 0.050000;
  DECLARE v_dispute_metric DECIMAL(8,4) DEFAULT 80.0000;
  DECLARE v_dispute_points DECIMAL(8,4) DEFAULT 0;
  DECLARE v_identity_points DECIMAL(8,4) DEFAULT 0;
  DECLARE v_counterparties INT UNSIGNED DEFAULT 0;
  DECLARE v_evidence_units DECIMAL(8,2) DEFAULT 0;
  DECLARE v_confidence DECIMAL(8,4) DEFAULT 0;
  DECLARE v_confidence_label VARCHAR(20) DEFAULT 'Tiada';
  DECLARE v_raw_score DECIMAL(8,4) DEFAULT 0;
  DECLARE v_score DECIMAL(8,4) DEFAULT 0;
  DECLARE v_level VARCHAR(32) DEFAULT 'Baharu';

  SELECT role, is_active INTO v_role, v_is_active
    FROM users WHERE user_id=p_buyer_id LIMIT 1;
  IF v_role IS NULL OR v_role NOT IN ('pembeli','penjual') THEN
    LEAVE proc;
  END IF;

  SELECT
    COALESCE(SUM(status='Selesai'),0),
    COALESCE(SUM(status='Dibatalkan' AND cancelled_by='buyer'),0)
    INTO v_completed, v_buyer_cancelled
    FROM transactions
   WHERE buyer_id=p_buyer_id;

  SET v_completion_trials=v_completed+v_buyer_cancelled;
  SET v_completion_metric=
    ((v_completed+8.0000)/(v_completion_trials+10.0000))*100;
  SET v_completion_points=65.0000*v_completion_metric/100.0000;

  SELECT COUNT(DISTINCT d.transaction_id)
    INTO v_adverse_disputes
    FROM disputes d
    JOIN transactions t ON t.transaction_id=d.transaction_id
   WHERE t.buyer_id=p_buyer_id
     AND d.status='Selesai'
     AND d.resolution='Memihak Penjual';

  SET v_defect_rate=(v_adverse_disputes+0.5000)
                   /(v_completion_trials+10.0000);
  SET v_dispute_metric=100.0000
    *(1.0000-LEAST(1.0000,v_defect_rate/0.2500));
  SET v_dispute_points=25.0000*v_dispute_metric/100.0000;
  SET v_identity_points=IF(v_is_active=1,10.0000,0.0000);

  SELECT COUNT(*), COALESCE(SUM(LEAST(e.event_count,3)),0)
    INTO v_counterparties, v_evidence_units
    FROM (
      SELECT seller_id, COUNT(*) AS event_count
        FROM transactions
       WHERE buyer_id=p_buyer_id
         AND (status='Selesai'
           OR (status='Dibatalkan' AND cancelled_by='buyer'))
       GROUP BY seller_id
    ) AS e;

  IF v_evidence_units>0 THEN
    SET v_confidence=LEAST(100.0000,
      100.0000*LN(1.0000+v_evidence_units)/LN(11.0000));
  ELSE
    SET v_confidence=0.0000;
  END IF;

  SET v_raw_score=v_completion_points+v_dispute_points+v_identity_points;
  SET v_score=IF(v_evidence_units=0,0.0000,
                 v_raw_score*v_confidence/100.0000);
  SET v_score=GREATEST(0,LEAST(100,v_score));
  SET v_confidence_label=CASE
    WHEN v_confidence=0 THEN 'Tiada'
    WHEN v_confidence<40 THEN 'Rendah'
    WHEN v_confidence<80 THEN 'Sederhana'
    ELSE 'Tinggi'
  END;
  SET v_level=CASE
    WHEN v_evidence_units=0 THEN 'Baharu'
    WHEN v_confidence<40 THEN 'Awal'
    WHEN v_score<50 THEN 'Membina'
    WHEN v_score<70 THEN 'Sederhana'
    WHEN v_score<85 THEN 'Dipercayai'
    ELSE 'Sangat Dipercayai'
  END;

  INSERT INTO trust_profiles (
    user_id,profile_role,score,raw_score,confidence,confidence_label,
    trust_level,evidence_units,distinct_counterparties,
    completed_transactions,formula_version
  ) VALUES (
    p_buyer_id,'pembeli',ROUND(v_score,2),ROUND(v_raw_score,2),
    ROUND(v_confidence,2),v_confidence_label,v_level,v_evidence_units,
    v_counterparties,v_completed,'TM-TRUST-2.0'
  )
  ON DUPLICATE KEY UPDATE
    score=VALUES(score),raw_score=VALUES(raw_score),
    confidence=VALUES(confidence),confidence_label=VALUES(confidence_label),
    trust_level=VALUES(trust_level),evidence_units=VALUES(evidence_units),
    distinct_counterparties=VALUES(distinct_counterparties),
    completed_transactions=VALUES(completed_transactions),
    formula_version=VALUES(formula_version),calculated_at=CURRENT_TIMESTAMP;

  DELETE FROM trust_score_components
   WHERE user_id=p_buyer_id AND profile_role='pembeli';
  INSERT INTO trust_score_components
    (user_id,profile_role,component_code,component_label,component_order,
     max_points,metric_value,points,evidence_count,details)
  VALUES
    (p_buyer_id,'pembeli','completion','Penyelesaian pesanan',1,65,
     ROUND(v_completion_metric,2),ROUND(v_completion_points,2),v_completion_trials,
     CONCAT(v_completed,' selesai; ',v_buyer_cancelled,' dibatalkan pembeli')),
    (p_buyer_id,'pembeli','disputes','Keselamatan pertikaian',2,25,
     ROUND(v_dispute_metric,2),ROUND(v_dispute_points,2),v_adverse_disputes,
     CONCAT(v_adverse_disputes,' keputusan aduan memihak penjual')),
    (p_buyer_id,'pembeli','identity','Identiti UTHM aktif',3,10,
     IF(v_is_active=1,100,0),ROUND(v_identity_points,2),v_is_active,
     IF(v_is_active=1,'Akaun UTHM aktif','Akaun tidak aktif'));

  
  
  UPDATE users
     SET buyer_trust_score=ROUND(v_score,2),
         trust_score=IF(role='pembeli',ROUND(v_score,2),trust_score)
   WHERE user_id=p_buyer_id;
END ;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_AUTO_VALUE_ON_ZERO' */ ;
/*!50003 DROP PROCEDURE IF EXISTS `recalculate_trust_score` */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = cp850 */ ;
/*!50003 SET character_set_results = cp850 */ ;
/*!50003 SET collation_connection  = cp850_general_ci */ ;
DELIMITER ;;
CREATE DEFINER=`root`@`localhost` PROCEDURE `recalculate_trust_score`(IN p_seller_id INT UNSIGNED)
proc: BEGIN
  DECLARE v_role VARCHAR(20) DEFAULT NULL;
  DECLARE v_is_verified TINYINT DEFAULT 0;

  DECLARE v_reviewers INT UNSIGNED DEFAULT 0;
  DECLARE v_review_sum DECIMAL(12,4) DEFAULT 0;
  DECLARE v_bayesian_rating DECIMAL(8,4) DEFAULT 4.0000;
  DECLARE v_rating_metric DECIMAL(8,4) DEFAULT 75.0000;
  DECLARE v_rating_points DECIMAL(8,4) DEFAULT 0;

  DECLARE v_completed INT UNSIGNED DEFAULT 0;
  DECLARE v_seller_cancelled INT UNSIGNED DEFAULT 0;
  DECLARE v_fulfilment_trials INT UNSIGNED DEFAULT 0;
  DECLARE v_fulfilment_metric DECIMAL(8,4) DEFAULT 80.0000;
  DECLARE v_fulfilment_points DECIMAL(8,4) DEFAULT 0;

  DECLARE v_adverse_disputes INT UNSIGNED DEFAULT 0;
  DECLARE v_defect_rate DECIMAL(10,6) DEFAULT 0.050000;
  DECLARE v_dispute_metric DECIMAL(8,4) DEFAULT 80.0000;
  DECLARE v_dispute_points DECIMAL(8,4) DEFAULT 0;

  DECLARE v_decisions INT UNSIGNED DEFAULT 0;
  DECLARE v_timely_decisions INT UNSIGNED DEFAULT 0;
  DECLARE v_response_metric DECIMAL(8,4) DEFAULT 80.0000;
  DECLARE v_response_points DECIMAL(8,4) DEFAULT 0;
  DECLARE v_response_seconds INT DEFAULT NULL;
  DECLARE v_identity_points DECIMAL(8,4) DEFAULT 0;

  DECLARE v_counterparties INT UNSIGNED DEFAULT 0;
  DECLARE v_evidence_units DECIMAL(8,2) DEFAULT 0;
  DECLARE v_confidence DECIMAL(8,4) DEFAULT 0;
  DECLARE v_confidence_label VARCHAR(20) DEFAULT 'Tiada';
  DECLARE v_raw_score DECIMAL(8,4) DEFAULT 0;
  DECLARE v_score DECIMAL(8,4) DEFAULT 0;
  DECLARE v_level VARCHAR(32) DEFAULT 'Baharu';

  SELECT role, is_verified INTO v_role, v_is_verified
    FROM users WHERE user_id = p_seller_id LIMIT 1;
  IF v_role IS NULL OR v_role <> 'penjual' THEN
    LEAVE proc;
  END IF;

  
  
  SELECT COUNT(*), COALESCE(SUM(r.buyer_average), 0)
    INTO v_reviewers, v_review_sum
    FROM (
      SELECT rv.buyer_id, AVG(rv.rating) AS buyer_average
        FROM reviews rv
        JOIN transactions tx ON tx.transaction_id = rv.transaction_id
       WHERE rv.seller_id = p_seller_id
         AND tx.status = 'Selesai'
       GROUP BY rv.buyer_id
    ) AS r;

  
  
  SET v_bayesian_rating = (20.0000 + v_review_sum) / (5 + v_reviewers);
  SET v_rating_metric = GREATEST(0, LEAST(100,
    ((v_bayesian_rating - 1.0000) / 4.0000) * 100));
  SET v_rating_points = 35.0000 * v_rating_metric / 100.0000;

  SELECT
    COALESCE(SUM(status = 'Selesai'), 0),
    COALESCE(SUM(status = 'Dibatalkan' AND cancelled_by = 'seller'), 0)
    INTO v_completed, v_seller_cancelled
    FROM transactions
   WHERE seller_id = p_seller_id;

  SET v_fulfilment_trials = v_completed + v_seller_cancelled;
  
  
  SET v_fulfilment_metric =
    ((v_completed + 8.0000) / (v_fulfilment_trials + 10.0000)) * 100;
  SET v_fulfilment_points = 30.0000 * v_fulfilment_metric / 100.0000;

  SELECT COUNT(DISTINCT d.transaction_id)
    INTO v_adverse_disputes
    FROM disputes d
    JOIN transactions t ON t.transaction_id = d.transaction_id
   WHERE t.seller_id = p_seller_id
     AND d.status = 'Selesai'
     AND d.resolution = 'Memihak Pembeli';

  
  
  SET v_defect_rate = (v_adverse_disputes + 0.5000)
                    / (v_fulfilment_trials + 10.0000);
  SET v_dispute_metric = 100.0000
    * (1.0000 - LEAST(1.0000, v_defect_rate / 0.2500));
  SET v_dispute_points = 20.0000 * v_dispute_metric / 100.0000;

  SELECT
    COUNT(*),
    COALESCE(SUM(TIMESTAMPDIFF(SECOND, created_at,
      CASE WHEN confirmed_at IS NOT NULL THEN confirmed_at ELSE cancelled_at END
    ) BETWEEN 0 AND 43200), 0),
    AVG(TIMESTAMPDIFF(SECOND, created_at,
      CASE WHEN confirmed_at IS NOT NULL THEN confirmed_at ELSE cancelled_at END
    ))
    INTO v_decisions, v_timely_decisions, v_response_seconds
    FROM transactions
   WHERE seller_id = p_seller_id
     AND (confirmed_at IS NOT NULL
       OR (status = 'Dibatalkan' AND cancelled_by = 'seller' AND cancelled_at IS NOT NULL));

  
  
  SET v_response_metric =
    ((v_timely_decisions + 4.0000) / (v_decisions + 5.0000)) * 100;
  SET v_response_points = 10.0000 * v_response_metric / 100.0000;
  SET v_identity_points = IF(v_is_verified = 1, 5.0000, 0.0000);

  
  
  SELECT COUNT(*), COALESCE(SUM(LEAST(e.event_count, 3)), 0)
    INTO v_counterparties, v_evidence_units
    FROM (
      SELECT buyer_id, COUNT(*) AS event_count
        FROM transactions
       WHERE seller_id = p_seller_id
         AND (status = 'Selesai'
           OR (status = 'Dibatalkan' AND cancelled_by = 'seller'))
       GROUP BY buyer_id
    ) AS e;

  IF v_evidence_units > 0 THEN
    SET v_confidence = LEAST(100.0000,
      100.0000 * LN(1.0000 + v_evidence_units) / LN(11.0000));
  ELSE
    SET v_confidence = 0.0000;
  END IF;

  SET v_raw_score = v_rating_points + v_fulfilment_points
                  + v_dispute_points + v_response_points
                  + v_identity_points;
  SET v_score = IF(v_evidence_units = 0, 0.0000,
                   v_raw_score * v_confidence / 100.0000);
  SET v_score = GREATEST(0, LEAST(100, v_score));

  SET v_confidence_label = CASE
    WHEN v_confidence = 0 THEN 'Tiada'
    WHEN v_confidence < 40 THEN 'Rendah'
    WHEN v_confidence < 80 THEN 'Sederhana'
    ELSE 'Tinggi'
  END;
  SET v_level = CASE
    WHEN v_evidence_units = 0 THEN 'Baharu'
    WHEN v_confidence < 40 THEN 'Awal'
    WHEN v_score < 50 THEN 'Membina'
    WHEN v_score < 70 THEN 'Sederhana'
    WHEN v_score < 85 THEN 'Dipercayai'
    ELSE 'Sangat Dipercayai'
  END;

  INSERT INTO trust_profiles (
    user_id, profile_role, score, raw_score, confidence,
    confidence_label, trust_level, evidence_units,
    distinct_counterparties, completed_transactions, formula_version
  ) VALUES (
    p_seller_id, 'penjual', ROUND(v_score,2), ROUND(v_raw_score,2),
    ROUND(v_confidence,2), v_confidence_label, v_level,
    v_evidence_units, v_counterparties, v_completed, 'TM-TRUST-2.0'
  )
  ON DUPLICATE KEY UPDATE
    score=VALUES(score), raw_score=VALUES(raw_score),
    confidence=VALUES(confidence), confidence_label=VALUES(confidence_label),
    trust_level=VALUES(trust_level), evidence_units=VALUES(evidence_units),
    distinct_counterparties=VALUES(distinct_counterparties),
    completed_transactions=VALUES(completed_transactions),
    formula_version=VALUES(formula_version), calculated_at=CURRENT_TIMESTAMP;

  DELETE FROM trust_score_components
   WHERE user_id=p_seller_id AND profile_role='penjual';
  INSERT INTO trust_score_components
    (user_id, profile_role, component_code, component_label,
     component_order, max_points, metric_value, points, evidence_count, details)
  VALUES
    (p_seller_id,'penjual','rating','Ulasan pembelian disahkan',1,35,
     ROUND(v_rating_metric,2),ROUND(v_rating_points,2),v_reviewers,
     CONCAT(v_reviewers,' pembeli unik; purata Bayesian ',ROUND(v_bayesian_rating,2),'/5')),
    (p_seller_id,'penjual','fulfilment','Kebolehpercayaan pesanan',2,30,
     ROUND(v_fulfilment_metric,2),ROUND(v_fulfilment_points,2),v_fulfilment_trials,
     CONCAT(v_completed,' selesai; ',v_seller_cancelled,' dibatalkan penjual')),
    (p_seller_id,'penjual','disputes','Keselamatan pertikaian',3,20,
     ROUND(v_dispute_metric,2),ROUND(v_dispute_points,2),v_adverse_disputes,
     CONCAT(v_adverse_disputes,' keputusan aduan memihak pembeli')),
    (p_seller_id,'penjual','response','Respons keputusan pesanan',4,10,
     ROUND(v_response_metric,2),ROUND(v_response_points,2),v_decisions,
     CONCAT(v_timely_decisions,'/',v_decisions,' keputusan dalam 12 jam')),
    (p_seller_id,'penjual','identity','Pengesahan identiti PTU',5,5,
     IF(v_is_verified=1,100,0),ROUND(v_identity_points,2),v_is_verified,
     IF(v_is_verified=1,'Penjual disahkan PTU','Penjual belum disahkan PTU'));

  
  
  UPDATE users
     SET trust_score=ROUND(v_score,2), response_time_avg=v_response_seconds
   WHERE user_id=p_seller_id;
END ;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-10-01  4:19:21
