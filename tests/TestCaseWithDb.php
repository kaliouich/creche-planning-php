<?php

namespace Tests;

use PHPUnit\Framework\TestCase;
use PDO;

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../models/Model.php';
require_once __DIR__ . '/../models/User.php';

abstract class TestCaseWithDb extends TestCase
{
    protected string $dbPath;
    protected ?PDO $pdo;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/creche_test_' . uniqid() . '.sqlite';
        putenv('TEST_DB_SQLITE=' . $this->dbPath);
        
        // Force recreation of the static PDO instance by clearing it or we just rely on db.php creating a new one if it was null.
        // Since db.php uses a static $pdo, we need a way to reset it.
        $this->resetPdo();
        
        $this->pdo = get_db();
        $this->createSchema();
    }

    protected function tearDown(): void
    {
        $this->pdo = null; // Free connection
        $this->resetPdo(); // Reset static
        if (file_exists($this->dbPath)) {
            unlink($this->dbPath);
        }
        putenv('TEST_DB_SQLITE=');
    }

    private function resetPdo(): void
    {
        get_db(true);
    }

    private function createSchema(): void
    {
        $this->pdo->exec("
            CREATE TABLE users (
                id VARCHAR(36) PRIMARY KEY,
                email VARCHAR(255) UNIQUE,
                role VARCHAR(20),
                first_name VARCHAR(100),
                last_name VARCHAR(100),
                is_active BOOLEAN DEFAULT 1,
                password_hash VARCHAR(255),
                created_at DATETIME,
                updated_at DATETIME,
                second_email VARCHAR(255)
            );
            CREATE TABLE planning_weeks (
                id VARCHAR(36) PRIMARY KEY,
                week_number INT,
                year INT,
                status VARCHAR(20) DEFAULT 'PREPARATION',
                created_at DATETIME,
                updated_at DATETIME
            );
            CREATE TABLE slots (
                id VARCHAR(36) PRIMARY KEY,
                planning_week_id VARCHAR(36),
                day_of_week VARCHAR(20),
                half_day VARCHAR(20),
                slot_type VARCHAR(20) DEFAULT 'OPEN',
                required_parents INT DEFAULT 1
            );
            CREATE TABLE children (
                id VARCHAR(36) PRIMARY KEY,
                first_name VARCHAR(100),
                last_name VARCHAR(100),
                parent_id VARCHAR(36),
                is_active BOOLEAN DEFAULT 1,
                age_group VARCHAR(10),
                parent1_first_name VARCHAR(100) DEFAULT 'Famille',
                parent2_first_name VARCHAR(100),
                parent1_email VARCHAR(255),
                parent2_email VARCHAR(255),
                created_at DATETIME
            );
            CREATE TABLE child_default_presences (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                child_id VARCHAR(36),
                day_of_week VARCHAR(20),
                half_day VARCHAR(20)
            );
            CREATE TABLE child_presences (
                id VARCHAR(36) PRIMARY KEY,
                slot_id VARCHAR(36),
                child_id VARCHAR(36),
                is_present BOOLEAN,
                updated_at DATETIME
            );
            CREATE TABLE availabilities (
                id VARCHAR(36) PRIMARY KEY,
                slot_id VARCHAR(36),
                parent_id VARCHAR(36),
                is_available BOOLEAN,
                updated_at DATETIME
            );
            CREATE TABLE assignments (
                id VARCHAR(36) PRIMARY KEY,
                slot_id VARCHAR(36),
                child_id VARCHAR(36),
                is_manual BOOLEAN DEFAULT 0,
                assigned_at DATETIME
            );
            CREATE TABLE relaches (
                id VARCHAR(36) PRIMARY KEY,
                parent_id VARCHAR(36),
                granted_date DATE,
                comment TEXT,
                created_at DATETIME
            );
            CREATE TABLE child_absences (
                id VARCHAR(36) PRIMARY KEY,
                child_id VARCHAR(36),
                start_date DATE,
                start_half_day VARCHAR(20) DEFAULT 'ALL',
                end_date DATE,
                end_half_day VARCHAR(20) DEFAULT 'ALL',
                is_conge BOOLEAN DEFAULT 0,
                created_at DATETIME
            );
            CREATE TABLE score_histories (
                id VARCHAR(36) PRIMARY KEY,
                child_id VARCHAR(36),
                week_number INT,
                year INT,
                score_before FLOAT,
                permanences_due FLOAT,
                permanences_done FLOAT,
                score_after FLOAT,
                snapshot_at DATETIME
            );
        ");
    }
}
