<?php

namespace Erorus\CASC;

abstract class NameLookup
{
    abstract public function GetContentHash($db2OrId, $locale);
}
