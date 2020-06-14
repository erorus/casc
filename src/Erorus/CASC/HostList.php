<?php

namespace Erorus\CASC;

/**
 * The intent is to have a list of items (hosts) that is often iterated through via foreach. When a valid value is
 * found, we will break out of the foreach, and that value will be the first one seen the next time this is iterated.
 */
class HostList implements \Iterator, \Countable {
    /** @var string[] The hostnames in our list. */
    private $data = [];

    /** @var int How many hosts we've iterated through in this loop. */
    private $position = 0;

    /**
     * HostList constructor.
     *
     * @param string[] $items
     */
    public function __construct(array $items) {
        $this->data = array_values(array_filter($items));
    }

    /**
     * Self-explanatory.
     *
     * @return int
     */
    public function count() {
        return count($this->data);
    }

    /**
     * We always keep our current/best host first.
     *
     * @return string
     */
    public function current() {
        return $this->data[0];
    }

    /**
     * Returns the numeric index of the "position" during this iterator loop. This will count up as normal.
     *
     * @return int
     */
    public function key() {
        return $this->position;
    }

    /**
     * We didn't like that host and are looking for another. Increment our position counter and move the first host
     * to the end of the list.
     */
    public function next() {
        $this->position++;
        $this->data[] = array_shift($this->data);
    }

    /**
     * Called at the start of a foreach loop, reset how many we've skipped to 0.
     */
    public function rewind() {
        $this->position = 0;
    }

    /**
     * Our iterator can keep looping as long as our "position" for this loop is fewer than how many elements we have.
     *
     * @return bool
     */
    public function valid() {
        return $this->position < count($this->data);
    }
}
