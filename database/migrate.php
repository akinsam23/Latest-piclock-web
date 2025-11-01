
### 27. Let's create a database migration script:

```php
// database/migrate.php
<?php
require_once __DIR__ . '/../config/database.php';

function runMigrations() {
    $db = getDBConnection();
    
    // Check if migrations table exists
    $migrationsTableExists = $db->query("SHOW TABLES LIKE 'migrations'")->rowCount() > 0;
    
    if (!$migrationsTableExists) {
        // Create migrations table
        $db->exec("
            CREATE TABLE migrations (
                id INT AUTO_INCREMENT PRIMARY KEY,
                migration VARCHAR(255) NOT NULL,
                batch INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
    }
    
    // Get all migration files
    $migrationFiles = glob(__DIR__ . '/migrations/*.sql');
    $ranMigrations = $db->query("SELECT migration FROM migrations")->fetchAll(PDO::FETCH_COLUMN);
    
    $newMigrations = [];
    $batch = $db->query("SELECT COALESCE(MAX(batch), 0) + 1 as next_batch FROM migrations")->fetch()['next_batch'];
    
    foreach ($migrationFiles as $file) {
        $migration = basename($file);
        
        if (!in_array($migration, $ranMigrations)) {
            $sql = file_get_contents($file);
            $db->exec($sql);
            
            $stmt = $db->prepare("
                INSERT INTO migrations (migration, batch) 
                VALUES (?, ?)
            ");
            $stmt->execute([$migration, $batch]);
            
            $newMigrations[] = $migration;
            echo "Ran migration: $migration\n";
        }
    }
    
    if (empty($newMigrations)) {
        echo "No new migrations to run.\n";
    } else {
        echo "Successfully ran " . count($newMigrations) . " migration(s).\n";
    }
}

// Run migrations
try {
    runMigrations();
    echo "Migrations completed successfully.\n";
    exit(0);
} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}