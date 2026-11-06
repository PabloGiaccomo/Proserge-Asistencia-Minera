<?php

namespace App\Modules\RQMina\Support;

use RuntimeException;

class RQMinaDeletionConflictException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('No se puede eliminar este RQ Mina: hay registros vinculados a otra parada. Corrige esos vinculos antes de eliminar.');
    }
}
