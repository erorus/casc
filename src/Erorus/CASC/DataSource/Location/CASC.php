<?php

namespace Erorus\CASC\DataSource\Location;

use Erorus\CASC\DataSource\Location;

/**
 * Where a piece of content is located in a local CASC archive.
 */
class CASC implements Location {
    /** @var int Which archive file has the content. */
    public $archive;

    /** @var string A prefix of the encoding hash. */
    public $hash;

    /** @var int The total length of the content */
    public $length;

    /** @var int The byte offset in the archive. */
    public $offset;

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
