<?php

declare(strict_types=1);

namespace Meulah\Database;

interface Migration
{
    public function up(Connection $connection): void;

    public function down(Connection $connection): void;
}

