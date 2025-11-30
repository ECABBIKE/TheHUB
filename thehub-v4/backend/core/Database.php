<?php

class Database
{
    public static function pdo(): PDO
    {
        global $pdo;
        return $pdo;
    }
}
