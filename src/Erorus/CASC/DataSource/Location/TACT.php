<?php

namespace Erorus\CASC\DataSource\Location;

use Erorus\CASC\DataSource\Location;

/**
 * Where a piece of content is located in a remote TACT archive.
 */
class TACT implements Location {
    /** @var string Which archive file has the content. */
    public $archive = '';

    /** @var int|null The total length of the content */
    public $length = null;

    /** @var int|null The byte offset in the archive. */
    public $offset = null;

    /**
     * @param array $data
     */
    public function __construct(array $data = []) {
        foreach ($data as $key => $val) {
            if (property_exists($this, $key)) {
                $this->{$key} = $val;
            }
        }
    }

}
