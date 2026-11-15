<?php

namespace App\Models;

use PDO;

/**
 * Brings an older database up to date with init.sql
 *
 * PHP version 7.0
 */
class Schema extends \Core\Model
{
    /**
     * Columns added since the first release, with the statements that add
     * each, kept in step with init.sql. One change per statement, since TiDB
     * won't add a foreign key alongside other changes. to_email goes last: if
     * its foreign key fails, the columns the pages need are already there
     */
    const ADDED_COLUMNS = [
        ['userinfo', 'country', ['ALTER TABLE userinfo ADD COLUMN country CHAR(2) AFTER city']],
        ['userinfo', 'timezone', ['ALTER TABLE userinfo ADD COLUMN timezone VARCHAR(64) AFTER country']],
        ['chats', 'to_email', [
            'ALTER TABLE chats ADD COLUMN to_email VARCHAR(255) NULL DEFAULT NULL AFTER email',
            'ALTER TABLE chats ADD KEY fk_m_to_email (to_email)',
            'ALTER TABLE chats ADD CONSTRAINT fk_m_to_email FOREIGN KEY (to_email) REFERENCES users (email)
               ON DELETE CASCADE ON UPDATE CASCADE',
        ]],
    ];

    /**
     * Add any of ADDED_COLUMNS the database is missing. Safe to run every start
     *
     * @return array The columns added, as table.column
     */
    public static function upgrade()
    {
        $db = static::getDB();
        $added = [];

        foreach (static::ADDED_COLUMNS as [$table, $column, $statements]) {
            if (static::hasColumn($db, $table, $column)) {
                continue;
            }
            $added[] = "$table.$column";
            foreach ($statements as $sql) {
                $db->exec($sql);
            }
        }

        return $added;
    }

    /**
     * @param PDO $db
     * @param string $table
     * @param string $column
     *
     * @return boolean
     */
    protected static function hasColumn($db, $table, $column)
    {
        $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS
                              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column');
        $stmt->bindValue(':table', $table, PDO::PARAM_STR);
        $stmt->bindValue(':column', $column, PDO::PARAM_STR);
        $stmt->execute();

        return $stmt->fetchColumn() > 0;
    }
}
