<?php

namespace MyApp\Catalog\Service;

/**
 * Побитовые операции над raw binary bitmap (1 бит = товар на позиции).
 */
final class BitmapOperations
{
    public static function emptyMask(int $bitLength): string
    {
        $bitLength = max(0, $bitLength);
        $bytes = (int)ceil($bitLength / 8);

        return $bytes > 0 ? str_repeat("\0", $bytes) : '';
    }

    public static function ensureLength(string $mask, int $bitLength): string
    {
        $bytes = (int)ceil(max(0, $bitLength) / 8);
        $len = strlen($mask);
        if ($len === $bytes) {
            return $mask;
        }
        if ($len > $bytes) {
            return substr($mask, 0, $bytes);
        }

        return $mask . str_repeat("\0", $bytes - $len);
    }

    public static function setBit(string $mask, int $position, bool $value = true): string
    {
        if ($position < 0) {
            return $mask;
        }
        $byteIndex = intdiv($position, 8);
        $bit = 7 - ($position % 8);
        if (strlen($mask) <= $byteIndex) {
            $mask = self::ensureLength($mask, $position + 1);
        }
        $ord = ord($mask[$byteIndex]);
        if ($value) {
            $ord |= (1 << $bit);
        } else {
            $ord &= ~(1 << $bit);
        }
        $mask[$byteIndex] = chr($ord);

        return $mask;
    }

    public static function getBit(string $mask, int $position): bool
    {
        if ($position < 0) {
            return false;
        }
        $byteIndex = intdiv($position, 8);
        if ($byteIndex >= strlen($mask)) {
            return false;
        }
        $bit = 7 - ($position % 8);

        return (ord($mask[$byteIndex]) & (1 << $bit)) !== 0;
    }

    public static function orMasks(string $a, string $b): string
    {
        $len = max(strlen($a), strlen($b));
        $a = self::ensureLength($a, $len * 8);
        $b = self::ensureLength($b, $len * 8);
        $out = '';
        for ($i = 0; $i < $len; $i++) {
            $out .= chr(ord($a[$i]) | ord($b[$i]));
        }

        return $out;
    }

    public static function andMasks(string $a, string $b): string
    {
        $len = max(strlen($a), strlen($b));
        $a = self::ensureLength($a, $len * 8);
        $b = self::ensureLength($b, $len * 8);
        $out = '';
        for ($i = 0; $i < $len; $i++) {
            $out .= chr(ord($a[$i]) & ord($b[$i]));
        }

        return $out;
    }

    public static function popcount(string $mask): int
    {
        $count = 0;
        $len = strlen($mask);
        for ($i = 0; $i < $len; $i++) {
            $count += self::bitCountByte(ord($mask[$i]));
        }

        return $count;
    }

    /**
     * @return list<int>
     */
    public static function iterateSetBits(string $mask, ?int $maxPosition = null): array
    {
        $positions = [];
        $len = strlen($mask);
        $limit = $maxPosition !== null ? $maxPosition : ($len * 8 - 1);
        for ($byteIndex = 0; $byteIndex < $len; $byteIndex++) {
            $byte = ord($mask[$byteIndex]);
            if ($byte === 0) {
                continue;
            }
            for ($bit = 0; $bit < 8; $bit++) {
                if (($byte & (1 << (7 - $bit))) === 0) {
                    continue;
                }
                $pos = $byteIndex * 8 + $bit;
                if ($maxPosition !== null && $pos > $limit) {
                    break 2;
                }
                $positions[] = $pos;
            }
        }

        return $positions;
    }

    public static function isEmpty(string $mask): bool
    {
        $len = strlen($mask);
        for ($i = 0; $i < $len; $i++) {
            if ($mask[$i] !== "\0") {
                return false;
            }
        }

        return true;
    }

    private static function bitCountByte(int $byte): int
    {
        $byte = $byte & 0xFF;
        $byte = $byte - (($byte >> 1) & 0x55);
        $byte = ($byte & 0x33) + (($byte >> 2) & 0x33);

        return (($byte + ($byte >> 4)) & 0x0F);
    }
}
