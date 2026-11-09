<?php
/**
 * Keystone Advisors - Database Helper Singleton
 * Conexión centralizada a leads_keystone.sqlite
 */

class Database {
    private static ?PDO $instance = null;

    public static function getInstance(): PDO {
        if (self::$instance === null) {
            $dbPath = __DIR__ . '/../data/leads_keystone.sqlite';
            $dir = dirname($dbPath);
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }

            self::$instance = new PDO('sqlite:' . $dbPath);
            self::$instance->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            self::$instance->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            self::$instance->exec("PRAGMA journal_mode = WAL;");
            self::$instance->exec("PRAGMA foreign_keys = ON;");
        }
        return self::$instance;
    }
}
