<?php

namespace FpDbTest;

use Exception;
use mysqli;

class Database implements DatabaseInterface
{
    private mysqli $mysqli;

    private array $availableArgs = [];

    private array $acceptedTypes = [
        '?'  => ['string', 'int', 'float', 'bool', 'null'],
        '?a' => ['array'],
        '?d' => ['integer', 'boolean', 'null', 'string'],
        '?f' => ['float', 'null'],
        '?#' => ['string', 'array'],
    ];

    public function __construct(mysqli $mysqli)
    {
        $this->mysqli = $mysqli;
    }

    /**
     * @throws Exception
     */
    public function buildQuery(string $query, array $args = []): string
    {
        return $this->parseQuery($query, $args);
    }

    public function skip($condition = null, $value = null): string
    {
        if (!$condition && !$value)
            return false;

        $value = $this->getUnknown($value);
        if ($value === 'NULL')
            return false;

        if ($value === "''")
            return false;

        if ($value)
            return $value;

        return false;
    }

    /**
     * @throws Exception
     */
    private function parseQuery(string $query, array $args = []): string
    {
        $this->availableArgs = $args;
        $condition = $this->getConditions($query);

        if (!$condition) {
            $query = $this->parsePrimitives($query);
        } else {
            $query = str_replace('{' . $condition . '}', '', $query);
            $query = $this->parsePrimitives($query) . $this->parseConditions($condition);
        }

        return trim($query);
    }

    /**
     * @throws Exception
     */
    private function parsePrimitives(string $query): string
    {
        if (count($this->availableArgs) === 0) {
            return $query;
        }

        $matches = $this->getPrimitives($query);
        if (!$matches)
            return $query;

        foreach ($matches as $index => $match) {
            $value = $this->availableArgs[$index];
            $match = trim($match);
            if (in_array(gettype($value), $this->acceptedTypes[$match])) {
                $value = $this->validateArg($value, $match);
            } else {
                throw new Exception('Invalid argument type');
            }

            $pos = strpos($query, $match);
            if ($pos !== false) {
                $query = substr_replace($query, $value, $pos, strlen($match));
            }

            unset($this->availableArgs[$index]);
        }

        return $query;
    }

    /**
     * @throws Exception
     */
    private function parseConditions(string $condition): ?string
    {
        $this->availableArgs = array_values($this->availableArgs);
        return $this->parseConditionPrimitives($condition);
    }

    /**
     * @throws Exception
     */
    private function parseConditionPrimitives(string $query): ?string
    {
        if (count($this->availableArgs) === 0)
            return null;

        $matches = $this->getPrimitives($query);
        if (!$matches)
            return $query;

        foreach ($matches as $index => $match) {
            $value = $this->availableArgs[$index];
            $match = trim($match);

            if (in_array(gettype($value), $this->acceptedTypes[$match])) {
                $value = $this->validateArg($value, $match);
            } else {
                throw new Exception('Invalid argument type');
            }

            if (!$value)
                return $this->skip($query, $value);

            $pos = strpos($query, $match);
            if ($pos !== false) {
                $query = substr_replace($query, $value, $pos, strlen($match));
            }

            unset($this->availableArgs[$index]);
        }

        return $query;
    }

    private function validateArg($value, $type): float|array|bool|int|string|null
    {
        return match ($type) {
            '?' => $this->getUnknown($value),
            '?d' => $this->getDigit($value),
            '?f' => $this->getFloat($value),
            '?a' => $this->getArray($value),
            '?#' => $this->getID($value),
            'default' => 'skiped',
        };
    }

    private function getConditions(string $query): string|bool
    {
        preg_match('/\{([^}]*)}/', $query, $matches);

        if (isset($matches[1]))
            return $matches[1];

        return false;
    }

    private function getPrimitives($query): array|bool
    {
        preg_match_all('/\?([dfa#\s])/', $query, $matches);

        if (isset($matches[0]))
            return $matches[0];

        return false;
    }

    private function getArray($value): string
    {
        $is_assoc = !array_is_list($value);

        if ($is_assoc) {
            $extraction = [];
            foreach ($value as $k => $v)
                $extraction[] = "`$k`" . " = " . $this->getUnknown($v);

            $value = implode(', ', $extraction);
        } else {
            $value = array_map(fn($v) => $this->getUnknown($v), $value);
            $value = implode(', ', $value);
        }

        return trim($value);
    }

    private function getUnknown($value): null|string|bool|array|float|int
    {
        if ($value === null)
            return 'NULL';

        if (is_bool($value))
            return $this->getBool($value);

        if (is_numeric($value))
            return $this->getDigit($value);

        if (is_array($value))
            return $this->getArray($value);

        if (is_string($value))
            return $this->getString($value);

        return 'skip';
    }

    private function getID($value): string
    {
        if (is_string($value))
            return "`$value`";

        if (is_array($value))
            return implode(', ', array_map(fn($v) => "`$v`", $value));

        return 'skip';
    }

    private function getString($value): string
    {
        return "'" . trim($this->mysqli->real_escape_string($value)) . "'";
    }

    private function getDigit($value): int
    {
        return (int)$value;
    }

    private function getFloat($value): float
    {
        return floatval($value);
    }

    private function getBool($value): bool
    {
        return (bool)$value;
    }
}
