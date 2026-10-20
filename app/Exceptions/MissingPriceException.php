<?php

namespace App\Exceptions;

/**
 * Harga reseller (dari HPP resep + margin) belum terbentuk untuk varian pada tanggal tersebut.
 * Dipisah agar import pesanan bisa mengelompokkan alasan gagalnya.
 */
class MissingPriceException extends BusinessRuleException
{
    //
}
