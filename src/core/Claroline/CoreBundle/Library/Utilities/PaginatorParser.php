<?php

namespace Claroline\CoreBundle\Library\Utilities;

use Doctrine\ORM\Tools\Pagination\Paginator;

class PaginatorParser
{
    /**
     * Parse a paginator (from Doctrine) and returns an array.
     *
     * @param Paginator $paginator
     *
     * @return array
     */
    public function paginatorToArray (Paginator $paginator)
    {
        $items = array();

        foreach ($paginator as $item) {
            $items[] = $item;
        }

        return $items;
    }
}
