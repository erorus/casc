<?php

namespace Erorus\CASC;

abstract class AbstractNameLookup
{
    abstract public function GetContentHash($db2OrId, $locale);
}