<?php

declare(strict_types=1);

use Meulah\Database\Connection;
use Meulah\Database\Migration;

return new class implements Migration {
    public function up(Connection $connection): void
    {
        $connection->execute('CREATE TABLE migration_beta (id INTEGER PRIMARY KEY)');
    }

    public function down(Connection $connection): void
    {
        $connection->execute('DROP TABLE migration_beta');
    }
};

