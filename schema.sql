-- MySQL schema pre mt-ispadmin (alternativa k auto-init SQLite).
-- Vytvor DB a pouzivatela, potom importuj tento subor.
-- CREATE DATABASE ispadmin CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(64) UNIQUE NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role VARCHAR(16) NOT NULL DEFAULT 'user'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE routers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(128) NOT NULL,
  host VARCHAR(128) NOT NULL,
  api_port INT NOT NULL DEFAULT 8728,
  use_ssl TINYINT NOT NULL DEFAULT 0,
  api_user VARCHAR(64) NOT NULL,
  api_pass VARCHAR(255) NOT NULL,
  dhcp_server VARCHAR(64) NOT NULL DEFAULT '',
  parent_queue VARCHAR(64) NOT NULL DEFAULT '',
  siet VARCHAR(64) NOT NULL DEFAULT '',
  ulica VARCHAR(128) NOT NULL DEFAULT '',
  cislo_domu VARCHAR(32) NOT NULL DEFAULT '',
  mesto VARCHAR(64) NOT NULL DEFAULT '',
  vchod VARCHAR(32) NOT NULL DEFAULT '',
  poschodie VARCHAR(32) NOT NULL DEFAULT '',
  active TINYINT NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE programs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(128) NOT NULL,
  aggregation INT NOT NULL DEFAULT 1,
  dl_group INT NOT NULL DEFAULT 0,
  ul_group INT NOT NULL DEFAULT 0,
  dl_user INT NOT NULL DEFAULT 0,
  ul_user INT NOT NULL DEFAULT 0,
  active TINYINT NOT NULL DEFAULT 1,
  updated_at DATETIME NULL,
  updated_by VARCHAR(64) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE customers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  contract_no VARCHAR(64) NOT NULL DEFAULT '',
  status VARCHAR(16) NOT NULL DEFAULT 'pripojeny',
  meno VARCHAR(128) NOT NULL DEFAULT '',
  priezvisko VARCHAR(128) NOT NULL DEFAULT '',
  firma VARCHAR(128) NOT NULL DEFAULT '',
  ulica VARCHAR(128) NOT NULL DEFAULT '',
  cislo_domu VARCHAR(32) NOT NULL DEFAULT '',
  vchod VARCHAR(32) NOT NULL DEFAULT '',
  poschodie VARCHAR(32) NOT NULL DEFAULT '',
  mesto VARCHAR(64) NOT NULL DEFAULT '',
  telefon VARCHAR(64) NOT NULL DEFAULT '',
  mail VARCHAR(128) NOT NULL DEFAULT '',
  router_id INT NULL,
  siet VARCHAR(32) NOT NULL DEFAULT '',
  ip VARCHAR(45) NOT NULL DEFAULT '',
  mac VARCHAR(17) NOT NULL DEFAULT '',
  ont_mac VARCHAR(17) NOT NULL DEFAULT '',
  program_id INT NULL,
  zariadenie VARCHAR(32) NOT NULL DEFAULT 'Router',
  ont_sn VARCHAR(64) NOT NULL DEFAULT '',
  poznamka VARCHAR(255) NOT NULL DEFAULT '',
  updated_at DATETIME NULL,
  updated_by VARCHAR(64) NULL,
  deleted_at DATETIME NULL,
  deleted_by VARCHAR(64) NULL,
  prev_status VARCHAR(32) NULL,
  KEY (router_id), KEY (program_id), KEY (contract_no), KEY (ip), KEY (mac)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE change_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  customer_id INT NULL,
  contract_no VARCHAR(64) NULL,
  who VARCHAR(64) NULL,
  action VARCHAR(64) NULL,
  created_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Defaultny ucet (heslo: changeme) — zmen po prvom prihlaseni!
INSERT INTO users (username, password_hash, role)
VALUES ('admin', '$2y$10$9OCZ0T9M3xhhu3Su5wX35..5GTF2SwT4F9TfFHQvUu/SJy.91d/2m', 'administrator');

-- Ukazkove programy (rychlost v kbit = Mbit*1024) — uprav podla vlastnej ponuky
INSERT INTO programs (name, aggregation, dl_group, ul_group, dl_user, ul_user) VALUES
('Home Mini',1,0,0,2048,512),
('Home Lite',1,0,0,25600,5120),
('Home Štandard',1,0,0,28672,5120),
('Home Klasik',1,0,0,35840,5120),
('Home Maxi',1,0,0,40960,5120),
('Home SENIOR',1,0,0,4096,512),
('Office Lite',1,0,0,15360,15360),
('Office Klasik',1,0,0,20480,20480),
('Office Maxi',1,0,0,30720,30720),
('TV + Optika Mini',1,0,0,40960,10240),
('TV + Optika Štandard',1,0,0,51200,15360),
('TV + Optika Maxi',1,0,0,81920,20480),
('Optika Mini',1,0,0,40960,10240),
('Optika Štandard',1,0,0,71680,20480),
('Optika Maxi',1,0,0,102400,25600);
