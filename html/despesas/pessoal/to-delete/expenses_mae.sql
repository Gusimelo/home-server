/*M!999999\- enable the sandbox mode */ 
-- MariaDB dump 10.19  Distrib 10.5.29-MariaDB, for debian-linux-gnu (x86_64)
--
-- Host: localhost    Database: expenses
-- ------------------------------------------------------
-- Server version	10.5.29-MariaDB-0+deb11u1

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
-- Table structure for table `transactions`
--

DROP TABLE IF EXISTS `transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `transactions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `description` varchar(255) NOT NULL,
  `cost_center` varchar(100) DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL,
  `type` enum('Income','Expense','Acerto') NOT NULL,
  `person` varchar(50) NOT NULL,
  `person_to` varchar(50) DEFAULT NULL,
  `status` enum('paid','pending') NOT NULL DEFAULT 'paid',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `transaction_date` date NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=45 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `transactions`
--

LOCK TABLES `transactions` WRITE;
/*!40000 ALTER TABLE `transactions` DISABLE KEYS */;
INSERT INTO `transactions` VALUES (3,'Água',NULL,16.56,'Expense','Gustavo',NULL,'paid','2025-08-31 17:52:30','2025-01-02'),(4,'Energia',NULL,91.77,'Expense','Gustavo',NULL,'paid','2025-08-31 17:52:30','2025-01-28'),(5,'NOS',NULL,61.00,'Expense','Diogo',NULL,'paid','2025-08-31 17:52:30','2024-12-02'),(6,'NOS',NULL,64.69,'Expense','Diogo',NULL,'paid','2025-08-31 17:52:30','2025-01-02'),(8,'Energia',NULL,90.97,'Expense','Gustavo',NULL,'paid','2025-08-31 17:52:30','2025-02-28'),(9,'Energia',NULL,86.31,'Expense','Mariana',NULL,'paid','2025-08-31 17:52:30','2025-03-28'),(10,'Energia',NULL,87.98,'Expense','Gustavo',NULL,'paid','2025-08-31 17:52:30','2025-04-28'),(11,'NOS',NULL,61.00,'Expense','Diogo',NULL,'paid','2025-08-31 17:52:30','2025-03-03'),(12,'Energia',NULL,69.42,'Expense','Gustavo',NULL,'paid','2025-08-31 17:52:30','2025-05-30'),(13,'Energia',NULL,67.92,'Expense','Mariana',NULL,'paid','2025-08-31 17:52:30','2025-05-30'),(14,'NOS',NULL,61.45,'Expense','Diogo',NULL,'paid','2025-08-31 17:52:30','2025-04-01'),(15,'NOS',NULL,67.06,'Expense','Diogo',NULL,'paid','2025-08-31 17:52:30','2025-05-02'),(16,'NOS',NULL,62.46,'Expense','Diogo',NULL,'paid','2025-08-31 17:52:30','2025-06-02'),(17,'Água',NULL,94.73,'Expense','Mariana',NULL,'paid','2025-08-31 17:52:30','2025-06-23'),(18,'Energia',NULL,52.70,'Expense','Mariana',NULL,'paid','2025-08-31 17:52:30','2025-07-16'),(20,'Energia',NULL,57.17,'Expense','Caixa da Garagem',NULL,'paid','2025-08-31 17:55:16','2025-08-31'),(21,'Garagem',NULL,75.00,'Income','Caixa da Garagem',NULL,'paid','2025-08-31 17:57:01','2025-08-31'),(22,'NOS',NULL,61.00,'Expense','Diogo',NULL,'paid','2025-09-01 09:21:26','2025-07-01'),(23,'NOS',NULL,61.00,'Expense','Diogo',NULL,'paid','2025-09-01 09:21:51','2025-08-01'),(25,'NOS',NULL,61.00,'Expense','Diogo',NULL,'paid','2025-09-01 10:02:55','2025-02-03'),(36,'NOS',NULL,61.00,'Expense','Diogo',NULL,'pending','2025-09-03 11:30:51','2025-09-03'),(38,'Água',NULL,25.51,'Expense','Mãe',NULL,'paid','2025-09-03 11:58:53','2025-01-27'),(39,'Água',NULL,20.80,'Expense','Mãe',NULL,'paid','2025-09-03 11:59:53','2025-02-24'),(40,'Água',NULL,21.95,'Expense','Mãe',NULL,'paid','2025-09-03 12:00:09','2025-03-27'),(41,'Água',NULL,24.54,'Expense','Mãe',NULL,'paid','2025-09-03 12:00:55','2025-04-22'),(42,'Água',NULL,25.09,'Expense','Mãe',NULL,'paid','2025-09-03 12:01:11','2025-05-26'),(43,'Água',NULL,25.09,'Expense','Mãe',NULL,'paid','2025-09-03 12:01:30','2025-07-28'),(44,'Água',NULL,24.86,'Expense','Mãe',NULL,'paid','2025-09-03 12:01:45','2025-08-25');
/*!40000 ALTER TABLE `transactions` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2025-09-03 13:21:12
