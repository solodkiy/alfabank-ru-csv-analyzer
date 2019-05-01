<?php
declare(strict_types = 1);

namespace Solodkiy\AlfaBankRu\CsvAnalyzer;

use Brick\Money\Currency;
use Brick\Money\Exception\MoneyMismatchException;
use Brick\Money\Money;

class Utils
{
    public static function getCurrencyByCode(string $code): Currency
    {
        $remap = [
            'RUR' => 'RUB',
        ];
        return Currency::of($remap[$code] ?? $code);
    }

    public static function isMoneyEquals(Money $a, Money $b): bool
    {
        try {
            return $a->isEqualTo($b);
        } catch (MoneyMismatchException $e) {
            return false;
        }
    }

    public static function isMoneyNearlyEquals(Money $a, Money $b) : bool
    {
        if (!$a->getCurrency()->is($b->getCurrency())) {
            return false;
        }
        $a = $a->getAmount()->toFloat();
        $b = $b->getAmount()->toFloat();
        $diff = abs($a - $b);
        $biggest = max($a, $b);
        $diffPercent = ($diff / $biggest) * 100;

        return (bool)$diffPercent < 1;
    }

    /**
     * @param $filePath
     * @return resource
     */
    public static function smartFileHandleCreate($filePath)
    {
        $fileHead = file_get_contents($filePath, false, null, 0, 100);

        if (!self::isUtf($fileHead)) {
            return self::convertFileStreamToUTF8($filePath, 'windows-1251');
        }
        return fopen($filePath, 'r');
    }

    public static function isUtf($string)
    {
        return preg_match('%(?:
                    [\xC2-\xDF][\x80-\xBF] # non-overlong 2-byte
                    |\xE0[\xA0-\xBF][\x80-\xBF] # excluding overlongs
                    |[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2} # straight 3-byte
                    |\xED[\x80-\x9F][\x80-\xBF] # excluding surrogates
                    |\xF0[\x90-\xBF][\x80-\xBF]{2} # planes 1-3
                    |[\xF1-\xF3][\x80-\xBF]{3} # planes 4-15
                    |\xF4[\x80-\x8F][\x80-\xBF]{2} # plane 16
                    )+%xs', $string);
    }

    /**
     * @param $filePath
     * @param $sourceFileEncoding
     * @return resource
     */
    public static function convertFileStreamToUTF8($filePath, $sourceFileEncoding)
    {
        $fc = file_get_contents($filePath);
        $outStream = fopen("php://temp", 'r+');
        $fc = iconv($sourceFileEncoding, 'UTF-8', $fc);
        fputs($outStream, $fc);
        rewind($outStream);
        return $outStream;
    }

    /**
     * Copyright (C) 2011-2017 by Lars Strojny <lstrojny@php.net>
     * @param $collection
     * @param callable $callback
     * @param null $initial
     * @return mixed|null
     */
    public static function reduceLeft($collection, callable $callback, $initial = null)
    {
        foreach ($collection as $index => $value) {
            $initial = $callback($value, $index, $collection, $initial);
        }

        return $initial;
    }

    /**
     * Copyright (C) 2011-2017 by Lars Strojny <lstrojny@php.net>
     * @param $collection
     * @param callable|null $callback
     * @return mixed|null
     */
    public static function first($collection, callable $callback = null)
    {
        foreach ($collection as $index => $element) {
            if ($callback === null) {
                return $element;
            }

            if ($callback($element, $index, $collection)) {
                return $element;
            }
        }

        return null;
    }

    /**
     * Copyright (C) 2011-2017 by Lars Strojny <lstrojny@php.net>
     * @param $collection
     * @param callable $callback
     * @return array
     */
    public static function filter($collection, callable $callback)
    {
        $aggregation = [];

        foreach ($collection as $index => $element) {
            if ($callback($element, $index, $collection)) {
                $aggregation[$index] = $element;
            }
        }

        return $aggregation;
    }

    /**
     * Copyright (C) 2011-2017 by Lars Strojny <lstrojny@php.net>
     * @param $collection
     * @param callable $callback
     * @return array
     */
    public static function map($collection, callable $callback)
    {
        $aggregation = [];

        foreach ($collection as $index => $element) {
            $aggregation[$index] = $callback($element, $index, $collection);
        }

        return $aggregation;
    }
}
