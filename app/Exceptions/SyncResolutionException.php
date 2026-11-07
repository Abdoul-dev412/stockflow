<?php

namespace App\Exceptions;

/** Une résolution de conflit ou d'ajustement n'a pas pu aboutir ; hérite de l'exception de stock pour être traitée de même. */
class SyncResolutionException extends StockMovementException
{
}