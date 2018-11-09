<?php

namespace Erorus\CASC;

/**
 * The intent is to have a list of items (hosts) that is often iterated through via foreach. When a valid value is
 * found, we will break out of the foreach, and that value will be the first one seen the next time this is iterated.
 *
 * Class HostList
 * @package Erorus\CASC
 */
class HostList implements \Iterator, \Countable {
    private $data = [];
    private $position = 0;

    public function __construct($items) {
        $this->data = array_values(array_filter($items));
    }

    public function current() {
        return $this->data[0];
    }

    public function key() {
        return $this->position;
    }

    public function next() {
        $this->position++;
        $this->data[] = array_shift($this->data);
    }

    public function rewind() {
        $this->position = 0;
    }

    public function valid() {
        return $this->position < count($this->data);
    }

    public function count() {
        return count($this->data);
    }
}
