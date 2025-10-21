/*M!999999\- enable the sandbox mode */ 
-- MariaDB dump 10.19  Distrib 10.5.27-MariaDB, for Linux (x86_64)
--
-- Host: localhost    Database: energia
-- ------------------------------------------------------
-- Server version	10.5.27-MariaDB

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
-- Table structure for table `estado_anterior`
--

DROP TABLE IF EXISTS `estado_anterior`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `estado_anterior` (
  `id` int(11) NOT NULL DEFAULT 1,
  `ultimo_vazio_acumulado` decimal(15,5) NOT NULL,
  `ultimo_cheia_acumulado` decimal(15,5) NOT NULL,
  `ultimo_ponta_acumulado` decimal(15,5) NOT NULL,
  `data_atualizacao` datetime NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `leituras_energia`
--

DROP TABLE IF EXISTS `leituras_energia`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `leituras_energia` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `data_hora` datetime NOT NULL DEFAULT current_timestamp(),
  `consumo_vazio` decimal(10,5) NOT NULL,
  `consumo_cheia` decimal(10,5) NOT NULL,
  `consumo_ponta` decimal(10,5) NOT NULL,
  `custo_vazio` decimal(10,5) NOT NULL,
  `custo_cheia` decimal(10,5) NOT NULL,
  `custo_ponta` decimal(10,5) NOT NULL,
  `custo_simples` decimal(10,5) NOT NULL,
  `tarifa_id` int(11) DEFAULT NULL,
  `preco_aplicado` decimal(10,5) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `tarifa_id` (`tarifa_id`),
  CONSTRAINT `leituras_energia_ibfk_1` FOREIGN KEY (`tarifa_id`) REFERENCES `tarifas` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=887 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `tarifas`
--

DROP TABLE IF EXISTS `tarifas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `tarifas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nome_tarifa` varchar(100) NOT NULL,
  `preco_vazio` decimal(10,5) NOT NULL,
  `preco_cheia` decimal(10,5) NOT NULL,
  `preco_ponta` decimal(10,5) NOT NULL,
  `custo_potencia_diario_bihorario` decimal(10,5) NOT NULL,
  `preco_simples` decimal(10,5) NOT NULL,
  `custo_potencia_diario_simples` decimal(10,5) NOT NULL,
  `data_inicio` date NOT NULL,
  `data_fim` date DEFAULT NULL,
  `custo_potencia_diario` decimal(10,5) NOT NULL DEFAULT 0.00000,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2025-10-18 16:05:52
