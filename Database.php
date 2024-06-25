<?php

namespace FpDbTest;

use Exception;
use mysqli;

class Database implements DatabaseInterface
{
    private mysqli $mysqli;
    private array $acceptable_value_types = ["boolean", "integer", "double", "string", "NULL"];
    private array $acceptable_identifier_types = ["string", "array"];
    private array $acceptable_mods = ["d", "f", "a", "#"];

    protected string $skipValue = "#SK!P";

    public function __construct(mysqli $mysqli)
    {
        $this->mysqli = $mysqli;
    }

    /**
     * Prepare null argument
     * 
     * @return string
     */
    private function prepareNull(): string {
        return "NULL";
    }

    /**
     * Prepare string argument
     * 
     * @param string $value
     * @return string
     */
    private function prepareString(string $value): string {
        $screened = $this->mysqli->real_escape_string($value);
        return "'$screened'";
    }

    /**
     * Prepare boolean
     * 
     * @param bool $value
     * @return string
     */
    private function prepareBoolean(bool $value): string {
        return $value ? 1 : 0;
    }

    /**
     * Prepare int
     * 
     * @param int $value
     * @return string
     */
    private function prepareInt(int $value): string {
        return (int) $value;
    }

    /**
     * Prepare int
     * 
     * @param float $value
     * @return string
     */
    private function prepareFloat(float $value): string {
        return (float) $value;
    }

    /**
     * Prepare value
     * 
     * @param mixed $value
     * @return string
     */
    private function prepareValue(mixed $value): string {
        $type = gettype($value);
        if (in_array($type, $this->acceptable_value_types)) {
            switch ($type) {
                case "boolean":
                    return $this->prepareBoolean($value);
                case "integer":
                    return $this->prepareInt($value);
                case "double":
                    return $this->prepareFloat($value);
                case "NULL":
                    return $this->prepareNull();
                default:
                    return $this->prepareString($value);
            }
        }
        else {
            throw new Exception("Type $type is not supported");
        }
    }

    /**
     * Prepare identifiers
     * 
     * @param string|array $identifier
     * @return string
     */
    private function prepareIdentifiers(string|array $identifier): string {
        $type = gettype($identifier);
        if (in_array($type, $this->acceptable_identifier_types)) {
            $list   = is_array($identifier) ? $identifier : [$identifier];
            $result = array_map(fn ($el): string => "`$el`", $list);
            return implode(', ', $result);
        }
        else {
            throw new Exception("Type $type is not supported");
        }
    }

    /**
     * Prepare array
     * 
     * @param array $array
     * @return string
     */
    private function prepareArray(array $array): string {
        if (is_array($array)) {
            $result = $array;
            if (!array_is_list($array)) {
                $result = array_map(function ($el, $key) {
                    $identifier = $this->prepareIdentifiers($key);
                    $value      = $this->prepareValue($el);
                    return "$identifier = $value";
                }, $array, array_keys($array));
            }
            return implode(', ', $result);
        }
        else {
            throw new Exception();
        }
    }

    /**
     * Execute modifier
     * 
     * @param string $mod - d: int, f: float, a: array, #: identifier or identifier array
     * @return callable|null
     */
    private function execMod(string $mod, mixed $arg): string|null {
        $mods = [
            'd' => fn ($arg): string => $this->prepareInt($arg), 
            'f' => fn ($arg): string => $this->prepareFloat($arg), 
            'a' => fn ($arg): string => $this->prepareArray($arg), 
            '#' => fn ($arg): string => $this->prepareIdentifiers($arg), 
        ];
        return isset($mods[$mod]) ? $mods[$mod]($arg) : null;
    }

    /**
     * Build query
     * 
     * @param string $query
     * @param array $args
     * @param bool $handleConditionBlocks
     * @return string
     */
    public function buildQuery(string $query, array $args = [], bool $handleConditionBlocks = true): string
    {
        if (count($args) === 0) {
            return $query;
        }

        try {
            $arg_index = 0;
            $summary   = "";
            $last_pos  = 0;

            for ($i = 0; $i <= strlen($query); $i++) {
                // Pointer found    
                if (isset($query[$i]) && $query[$i] === '?') {
                    // Check modifier
                    $next = $i + 1;
                    $mod  = isset($query[$next]) && in_array($query[$next], $this->acceptable_mods) 
                        ? $query[$next] 
                        : null;
                    $part = substr($query, $last_pos, $i - $last_pos);

                    // Prepare suffux
                    $suffix = $mod ? $this->execMod($mod, $args[$arg_index]) : $this->prepareValue($args[$arg_index]);
                    $summary .= $part . $suffix;

                    // Store state
                    $arg_index++;
                    $last_pos = $mod ? $next+1 : $i+1;
                    $i = $last_pos;
                }

                // Condition block found    
                if (isset($query[$i]) && $query[$i] === '{') {
                    $prev_part = substr($query, $last_pos, $i - $last_pos);
                    $summary .= $prev_part;

                    // Find close symbol position and get block
                    $close = strrpos($query, '}', $i);

                    // Prevent nesting block handle
                    if ($handleConditionBlocks) {
                        $part  = substr($query, $i+1, $close - $i-1);
                        $arg   = array_key_exists($arg_index, $args) ? $args[$arg_index] : $this->skipValue;
    
                        // Handle block query
                        if ($arg && $arg !== $this->skipValue) {
                            $sub_part = $this->buildQuery($part, [$arg], false);
                            $summary .= $sub_part;
                        }
                        $arg_index++;
                    }
        
                    // Store state
                    $last_pos = $close + 1;
                    $i = $last_pos;
                }
            }

            // Last part concat
            if ($last_pos < strlen($query)) {
                $part= substr($query, $last_pos);
                $summary .= $part;
            }
            return rtrim($summary);
        }
        catch (Exception $e) {
            throw new Exception();
        }
    }

    /**
     * Skip
     * @return string
     */
    public function skip(): string
    {
        return $this->skipValue;
    }
}
