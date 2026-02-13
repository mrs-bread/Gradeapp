CREATE DATABASE IF NOT EXISTS gradeapp CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE gradeapp;

CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(100) UNIQUE NOT NULL,
  full_name VARCHAR(255) NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('student','teacher','admin') NOT NULL DEFAULT 'student',
  must_change_password TINYINT(1) DEFAULT 0,
  is_locked TINYINT(1) DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS groups (
  id INT AUTO_INCREMENT PRIMARY KEY,
  short_name VARCHAR(100) NOT NULL,
  title VARCHAR(255) NULL
);

CREATE TABLE IF NOT EXISTS user_groups (
  user_id INT NOT NULL,
  group_id INT NOT NULL,
  PRIMARY KEY (user_id, group_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (group_id) REFERENCES groups(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS workbooks (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(255) NOT NULL,
  owner_id INT NOT NULL,
  group_id INT NOT NULL,
  locked_by INT NULL,
  locked_at TIMESTAMP NULL,
  show_formulas_for_students TINYINT(1) DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS sheets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  workbook_id INT NOT NULL,
  name VARCHAR(100) DEFAULT 'Sheet1',
  order_index INT DEFAULT 0,
  content JSON NULL,
  row_visibility_mode ENUM('all','own_row') NOT NULL DEFAULT 'all',
  student_id_column VARCHAR(10) NULL,
  FOREIGN KEY (workbook_id) REFERENCES workbooks(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS row_assignments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  workbook_id INT NOT NULL,
  sheet_id INT NOT NULL,
  row_index INT NOT NULL,
  user_id INT NOT NULL,
  UNIQUE(workbook_id, sheet_id, row_index),
  UNIQUE(workbook_id, sheet_id, user_id),
  FOREIGN KEY (workbook_id) REFERENCES workbooks(id) ON DELETE CASCADE,
  FOREIGN KEY (sheet_id) REFERENCES sheets(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS workbook_shares (
  id INT AUTO_INCREMENT PRIMARY KEY,
  workbook_id INT NOT NULL,
  token VARCHAR(128) NOT NULL UNIQUE,
  created_by INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  expires_at TIMESTAMP NULL,
  revoked TINYINT(1) DEFAULT 0,
  revocation_reason VARCHAR(255) NULL,
  revoked_by INT NULL,
  revoked_at TIMESTAMP NULL,
  FOREIGN KEY (workbook_id) REFERENCES workbooks(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS audit_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,
  action VARCHAR(255) NOT NULL,
  details JSON NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
