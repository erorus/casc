<?php

namespace Erorus\CASC\VersionConfig;

use Erorus\CASC\HostList;

interface VersionConfig
{
    /**
     * @return string The TACT product code.
     */
    public function getProgram(): string;

    /**
     * @return string The region code.
     */
    public function getRegion(): string;

    /**
     * @return string A path component, without leading or trailing slashes.
     */
    public function getCDNPath(): string;

    /**
     * @return string[]|HostList A list of CDN hostnames.
     */
    public function getHosts(): array|HostList;

    /**
     * @return string[]|HostList A list of CDN URL prefixes, e.g. ["http://cdn.example.com/"]
     */
    public function getServers(): array|HostList;

    /**
     * @return string The hex file hash to download the build configuration for this version.
     */
    public function getBuildConfig(): string;

    /**
     * @return string The hex file hash to download the CDN configuration for this version.
     */
    public function getCDNConfig(): string;

    /**
     * @return string The full game version name represented by this config. e.g. "8.3.0.34601"
     */
    public function getVersion(): string;
}
