<?php
//database.php
declare(strict_types=1);

/**
 * Database — PDO singleton with full query helpers.
 *
 *  Methods
 *  -------
 *  executeQuery()   raw query, returns PDOStatement
 *  getRow()         single row → array|null
 *  getRows()        multiple rows → array
 *  insert()         INSERT → last insert id
 *  update()         UPDATE → affected row count
 *  delete()         DELETE → affected row count
 *  rowCount()       rows from last statement
 *  paginate()       paginated result with meta
 *  beginTransaction / commit / rollback
 */
class Database
{
    private static ?Database $instance = null;
    private PDO $pdo;
    private ?PDOStatement $lastStatement = null;
    private Logger $logger;

    // ── Singleton ────────────────────────────────────────────────────────────
    private function __construct()
    {
        $this->logger = Logger::getInstance();
        $this->connect();
    }

    public static function getInstance(): static
    {
        if (self::$instance === null) {
            self::$instance = new static();
        }
        return self::$instance;
    }

    // ── Connection ───────────────────────────────────────────────────────────
    private function connect(): void
    {
        $host    = Config::get('db.host');
        $port    = Config::get('db.port', 3306);
        $name    = Config::get('db.name');
        $user    = Config::get('db.user');
        $pass    = Config::get('db.pass');
        $charset = Config::get('db.charset', 'utf8mb4');

        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset={$charset}";

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$charset} COLLATE utf8mb4_unicode_ci",
        ];

        try {
            $this->pdo = new PDO($dsn, $user, $pass, $options);
        } catch (PDOException $e) {
            $this->logger->error('DB Connection Failed', ['error' => $e->getMessage()]);
            throw new RuntimeException('Database connection failed: ' . $e->getMessage());
        }
    }

    // ── Core execute ─────────────────────────────────────────────────────────
    /**
     * Execute any SQL with optional bound params.
     * Params may be positional [val1, val2] or named ['name' => val].
     */
    public function executeQuery(string $sql, array $params = []): PDOStatement
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            $this->bindParams($stmt, $params);
            $stmt->execute();
            $this->lastStatement = $stmt;
            return $stmt;
        } catch (PDOException $e) {
            $this->logger->error('DB Query Error', [
                'sql'    => $sql,
                'params' => $params,
                'error'  => $e->getMessage(),
            ]);
            throw new RuntimeException('Query execution failed: ' . $e->getMessage());
        }
    }

    // ── Param binding ────────────────────────────────────────────────────────
    private function bindParams(PDOStatement $stmt, array $params): void
    {
        foreach ($params as $key => $value) {
            $type = match (true) {
                is_int($value)  => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_BOOL,
                is_null($value) => PDO::PARAM_NULL,
                default         => PDO::PARAM_STR,
            };
            // Named (:name) or positional (1-indexed)
            $placeholder = is_string($key) ? $key : $key + 1;
            $stmt->bindValue($placeholder, $value, $type);
        }
    }

    // ── Fetch helpers ────────────────────────────────────────────────────────
    /** Returns a single row as array or null if not found. */
    public function getRow(string $sql, array $params = []): ?array
    {
        $stmt = $this->executeQuery($sql, $params);
        $row  = $stmt->fetch();
        return $row === false ? null : $row;

        //get from PrescribeControlle
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC);

    }

    /** Returns all rows as array of arrays. */
    public function getRows(string $sql, array $params = []): array
    {
        $stmt = $this->executeQuery($sql, $params);
        return $stmt->fetchAll();

        //get from PrescribeController.php
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);

    }

    // ── DML helpers ──────────────────────────────────────────────────────────
    /**
     * INSERT a row.
     * @param  array $data ['column' => value, ...]
     * @return int   Last inserted ID
     */
    public function insert(string $table, array $data): int
    {
        $columns     = implode(', ', array_map(fn($col) => "`{$col}`", array_keys($data)));
        $placeholders = implode(', ', array_map(fn($k) => ':' . $k, array_keys($data)));
        $sql = "INSERT INTO `{$table}` ({$columns}) VALUES ({$placeholders})";

        $named = [];
        foreach ($data as $col => $val) {
            $named[':' . $col] = $val;
        }
        $this->executeQuery($sql, $named);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * UPDATE rows.
     * @param  array  $data        ['column' => value, ...]
     * @param  string $where       "id = :id"
     * @param  array  $whereParams [':id' => 1]
     * @return int    Affected rows
     */
    public function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $setParts = [];
        $namedData = [];
        foreach ($data as $col => $val) {
            $placeholder = ':set_' . $col;
            $setParts[]  = "`{$col}` = {$placeholder}";
            $namedData[$placeholder] = $val;
        }
        $setClause = implode(', ', $setParts);
        $sql = "UPDATE `{$table}` SET {$setClause} WHERE {$where}";

        $this->executeQuery($sql, array_merge($namedData, $whereParams));
        return $this->rowCount();
    }

    /**
     * DELETE rows.
     * @param  string $where       "id = :id"
     * @param  array  $whereParams [':id' => 1]
     * @return int    Affected rows
     */
    public function softDelete(string $table, string $where, array $params = []): int
    {
        $sql = "UPDATE `{$table}` SET deleted_at = NOW() WHERE {$where}";
        $this->executeQuery($sql, $params);
        return $this->rowCount();
    }

    // Exists check
    public function exists(string $table, string $where, array $params = []): bool
    {
        $row = $this->getRow("SELECT 1 FROM `{$table}` WHERE {$where} LIMIT 1", $params);
        return $row !== null;
    }

    // ── Row count ────────────────────────────────────────────────────────────
    /** Returns affected / fetched row count from last statement. */
    public function rowCount(): int
    {
        return $this->lastStatement ? $this->lastStatement->rowCount() : 0;
    }

    // ── Pagination ───────────────────────────────────────────────────────────
    /**
     * Paginate any SELECT query.
     *
     * @param  string $sql    Base query WITHOUT LIMIT/OFFSET
     * @param  array  $params Bound params for $sql
     * @param  int    $page   Current page (1-indexed)
     * @param  int    $limit  Rows per page
     * @return array  ['data' => [...], 'pagination' => ['total', 'page', 'limit', 'total_pages', 'has_next', 'has_prev']]
     */
    public function paginate(string $sql, array $params = [], int $page = 1, int $limit = 10): array
    {
        $page  = max(1, $page);
        $limit = max(1, min(100, $limit));   // cap at 100

        // Total count — wrap the query
        $countSql  = "SELECT COUNT(*) as cnt FROM ({$sql}) AS _pag_sub";
        $countRow  = $this->getRow($countSql, $params);
        $total     = (int) ($countRow['cnt'] ?? 0);

        $totalPages = (int) ceil($total / $limit);
        $offset     = ($page - 1) * $limit;

        $paginatedSql = "{$sql} LIMIT :_limit OFFSET :_offset";
        $paginatedParams = array_merge($params, [':_limit' => $limit, ':_offset' => $offset]);

        $data = $this->getRows($paginatedSql, $paginatedParams);

        return [
            'data'       => $data,
            'pagination' => [
                'total'       => $total,
                'page'        => $page,
                'limit'       => $limit,
                'total_pages' => $totalPages,
                'has_next'    => $page < $totalPages,
                'has_prev'    => $page > 1,
            ],
        ];
    }


    // ── Transactions ─────────────────────────────────────────────────────────
    public function beginTransaction(): void
    {
        $this->pdo->beginTransaction();
    }

    public function commit(): void
    {
        $this->pdo->commit();
    }

    public function rollback(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    // ── Utility ──────────────────────────────────────────────────────────────
    public function lastInsertId(): int
    {
        return (int) $this->pdo->lastInsertId();
    }

    /** Check if a table exists */
    public function tableExists(string $table): bool
    {
        $db  = Config::get('db.name');
        $row = $this->getRow(
            "SELECT COUNT(*) AS cnt FROM information_schema.tables
             WHERE table_schema = :db AND table_name = :tbl",
            [':db' => $db, ':tbl' => $table]
        );
        return (int) ($row['cnt'] ?? 0) > 0;
    }

    /** Prevent cloning of singleton */
    private function __clone() {}
}
