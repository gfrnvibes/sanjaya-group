<?php

namespace App\Support;

use DateInterval;
use DateTimeInterface;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;

class SpreadsheetCells
{
    public static function cell(bool|DateInterval|DateTimeInterface|float|int|string|null $value, ?Style $style = null, bool $csv = false): Cell
    {
        if (is_string($value)) {
            // CSV readers also interpret formulas; keep untrusted text inert in every format.
            if ($csv && preg_match('/^[\s\x00-\x1f]*[=+@\-????]/u', $value)) {
                $value = "'".$value;
            }

            return new StringCell($value, $style);
        }

        return Cell::fromValue($value, $style);
    }

    /** @param list<mixed> $values */
    public static function row(array $values, ?Style $style = null, bool $csv = false): Row
    {
        return new Row(array_map(fn ($value): Cell => self::cell($value, $style, $csv), $values));
    }
}
