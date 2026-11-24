<?php

declare(strict_types=1);

namespace App\Database;

use App\Domain\Exceptions\ConflictException;
use App\Domain\Exceptions\ValidationException;
use PDO;
use PDOException;
use Throwable;

class StoredProcedureCaller
{
    public function __construct(
        private ?PDO $pdo = null
    ) {}

    protected function getPdo(): PDO
    {
        return $this->pdo ?? Database::getConnection();
    }

    /**
     * Calls a stored procedure with positional parameters and returns associative rows.
     *
     * @param string $spName
     * @param list<mixed> $params
     * @return list<array<string, mixed>>
     * @throws Throwable
     */
    public function call(string $spName, array $params = []): array
    {
        $pdo = $this->getPdo();
        $placeholders = count($params) > 0 ? implode(', ', array_fill(0, count($params), '?')) : '';
        $query = "CALL {$spName}({$placeholders})";

        try {
            $stmt = $pdo->prepare($query);
            $stmt->execute($params);

            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $stmt->closeCursor();

            return $rows ?: [];
        } catch (PDOException $e) {
            $this->handleSqlException($e);
        }
    }

    /**
     * Calls a stored procedure that has an OUT parameter for generated ID (e.g. OUT p_id_generado).
     *
     * @param string $spName
     * @param list<mixed> $inParams
     * @param string $outVar
     * @return int|string|null
     * @throws Throwable
     */
    public function callWithOut(string $spName, array $inParams = [], string $outVar = '@p_id_generado'): int|string|null
    {
        $pdo = $this->getPdo();
        $inPlaceholders = count($inParams) > 0 ? implode(', ', array_fill(0, count($inParams), '?')) . ", {$outVar}" : $outVar;
        $callQuery = "CALL {$spName}({$inPlaceholders})";

        try {
            $stmt = $pdo->prepare($callQuery);
            $stmt->execute($inParams);
            $stmt->closeCursor();

            $selectStmt = $pdo->query("SELECT {$outVar} AS id");
            $result = $selectStmt->fetch(PDO::FETCH_ASSOC);

            return $result['id'] ?? null;
        } catch (PDOException $e) {
            $this->handleSqlException($e);
        }
    }

    /**
     * Calls a stored procedure and returns the first row or null if empty.
     *
     * @param string $spName
     * @param list<mixed> $params
     * @return array<string, mixed>|null
     */
    public function callSingle(string $spName, array $params = []): ?array
    {
        $rows = $this->call($spName, $params);
        return $rows[0] ?? null;
    }

    /**
     * Translates database constraint violations into clean Domain Exceptions.
     */
    private function handleSqlException(PDOException $e): never
    {
        $code = (int) ($e->errorInfo[1] ?? $e->getCode());
        $message = $e->getMessage();

        error_log('[SQL Error] ' . $message);

        // 1062 = Duplicate entry (Unique key violation)
        if ($code === 1062) {
            throw new ConflictException(
                'El registro o código ya existe y genera duplicidad en el sistema.',
                'REGISTRO_DUPLICADO',
                null,
                $e
            );
        }

        // 1452 = Cannot add or update a child row (Foreign key fails)
        if ($code === 1452) {
            throw new ValidationException(
                'La entidad referenciada no existe o es inválida.',
                null,
                'CLAVE_FORANEA_INVALIDA',
                $e
            );
        }

        throw $e;
    }
}
